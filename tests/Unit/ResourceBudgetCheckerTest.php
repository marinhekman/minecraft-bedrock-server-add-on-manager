<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Model\GlobalMeta;
use App\Service\GlobalMetaReader;
use App\Service\RedisClient;
use App\Service\ResourceBudgetChecker;
use PHPUnit\Framework\TestCase;

class ResourceBudgetCheckerTest extends TestCase
{
    private function makeChecker(array $limits, array $running): ResourceBudgetChecker
    {
        $meta = new GlobalMeta(
            resourceLimits: $limits,
            heartbeatTtl:   120,
        );

        $globalMetaReader = $this->createStub(GlobalMetaReader::class);
        $globalMetaReader->method('read')->willReturn($meta);

        $redis = $this->createStub(RedisClient::class);

        $serverNames = array_map(
            fn(int $i) => 'server' . $i,
            $running !== [] ? range(1, count($running)) : [],
        );

        $redis->method('getAllServerNames')->willReturn($serverNames);
        $redis->method('getServer')->willReturnCallback(
            function (string $name) use ($serverNames, $running): ?array {
                $index = array_search($name, $serverNames, true);
                if ($index === false) {
                    return null;
                }
                return [
                    'running'       => true,
                    'memoryProfile' => $running[$index],
                ];
            }
        );

        return new ResourceBudgetChecker($redis, $globalMetaReader);
    }

    // ── canStart ──────────────────────────────────────────────────────────────

    public function testHighAllowedWhenEmpty(): void
    {
        $checker = $this->makeChecker(
            limits:  [['high' => 1, 'low' => 1], ['medium' => 2]],
            running: [],
        );
        $this->assertTrue($checker->canStart('high'));
    }

    public function testMediumAllowedWhenEmpty(): void
    {
        $checker = $this->makeChecker(
            limits:  [['high' => 1, 'low' => 1], ['medium' => 2]],
            running: [],
        );
        $this->assertTrue($checker->canStart('medium'));
    }

    public function testLowAllowedWhenEmpty(): void
    {
        $checker = $this->makeChecker(
            limits:  [['high' => 1, 'low' => 1], ['medium' => 2]],
            running: [],
        );
        $this->assertTrue($checker->canStart('low'));
    }

    public function testLowAllowedWithHighRunning(): void
    {
        // high running → takes high slot; low candidate → takes low slot ✅
        $checker = $this->makeChecker(
            limits:  [['high' => 1, 'low' => 1], ['medium' => 2]],
            running: ['high'],
        );
        $this->assertTrue($checker->canStart('low'));
    }

    public function testTwoMediumAllowed(): void
    {
        // medium running → takes medium slot; medium candidate → takes second medium slot ✅
        $checker = $this->makeChecker(
            limits:  [['high' => 1, 'low' => 1], ['medium' => 2]],
            running: ['medium'],
        );
        $this->assertTrue($checker->canStart('medium'));
    }

    public function testLowWithMediumRunningAllowed(): void
    {
        // low running → takes medium slot (lowest available ≥ low);
        // low candidate → takes second medium slot ✅
        $checker = $this->makeChecker(
            limits:  [['medium' => 2]],
            running: ['low'],
        );
        $this->assertTrue($checker->canStart('low'));
    }

    public function testHighBlockedByMedium(): void
    {
        // medium running takes medium slot; high candidate needs high slot — none available ❌
        $checker = $this->makeChecker(
            limits:  [['high' => 1, 'low' => 1], ['medium' => 2]],
            running: ['medium'],
        );
        $this->assertFalse($checker->canStart('high'));
    }

    public function testMediumBlockedByHigh(): void
    {
        // high running takes high slot; medium candidate needs medium slot — none available ❌
        $checker = $this->makeChecker(
            limits:  [['high' => 1, 'low' => 1], ['medium' => 2]],
            running: ['high'],
        );
        $this->assertFalse($checker->canStart('medium'));
    }

    public function testThreeMediumBlocked(): void
    {
        // two medium running fill both medium slots; third medium blocked ❌
        $checker = $this->makeChecker(
            limits:  [['high' => 1, 'low' => 1], ['medium' => 2]],
            running: ['medium', 'medium'],
        );
        $this->assertFalse($checker->canStart('medium'));
    }

    public function testMediumPlusLowBlocksAnything(): void
    {
        // medium + low running fills all slots in both slot sets
        $checker = $this->makeChecker(
            limits:  [['high' => 1, 'low' => 1], ['medium' => 2]],
            running: ['medium', 'low'],
        );
        $this->assertFalse($checker->canStart('low'));
        $this->assertFalse($checker->canStart('medium'));
        $this->assertFalse($checker->canStart('high'));
    }

    public function testNoLimitsConfiguredAlwaysAllows(): void
    {
        $checker = $this->makeChecker(limits: [], running: ['high', 'high']);
        $this->assertTrue($checker->canStart('high'));
        $this->assertTrue($checker->canStart('low'));
    }

    // ── fitsInSlotSet ─────────────────────────────────────────────────────────

    public function testLowFitsInMediumSlotWhenNoLowSlot(): void
    {
        // low server can occupy a medium slot if no low slot available
        $checker = $this->makeChecker(limits: [], running: []);
        $this->assertTrue(
            $checker->fitsInSlotSet([], 'low', ['medium' => 1]),
        );
    }

    public function testHighCannotOccupyMediumSlot(): void
    {
        // high server can only occupy high slots
        $checker = $this->makeChecker(limits: [], running: []);
        $this->assertFalse(
            $checker->fitsInSlotSet([], 'high', ['medium' => 1]),
        );
    }

    public function testHighCannotOccupyLowSlot(): void
    {
        $checker = $this->makeChecker(limits: [], running: []);
        $this->assertFalse(
            $checker->fitsInSlotSet([], 'high', ['low' => 1]),
        );
    }

    public function testExactSlotFit(): void
    {
        $checker = $this->makeChecker(limits: [], running: []);
        $this->assertTrue(
            $checker->fitsInSlotSet(['high'], 'low', ['high' => 1, 'low' => 1]),
        );
    }

    public function testRunningServersConsumeSlots(): void
    {
        // high running takes high slot; low candidate takes low slot ✅
        $checker = $this->makeChecker(limits: [], running: []);
        $this->assertTrue(
            $checker->fitsInSlotSet(['high'], 'low', ['high' => 1, 'low' => 1]),
        );
    }

    public function testCandidateBlockedWhenAllSlotsConsumed(): void
    {
        // two medium running fill both slots; low candidate has nowhere to go ❌
        $checker = $this->makeChecker(limits: [], running: []);
        $this->assertFalse(
            $checker->fitsInSlotSet(['medium', 'medium'], 'low', ['medium' => 2]),
        );
    }

    public function testLowPrefersMediumOverHighSlot(): void
    {
        // low server should take medium slot first, leaving high slot free
        // so a subsequent high server could still start
        $checker = $this->makeChecker(limits: [], running: []);
        // After low takes medium slot, high slot is still free
        $this->assertTrue(
            $checker->fitsInSlotSet(['low'], 'high', ['medium' => 1, 'high' => 1]),
        );
    }

    // ── canStartWithProfiles ──────────────────────────────────────────────────

    public function testCanStartWithProfilesSimulatesStoppedServers(): void
    {
        // With high + low running, normally blocked for medium
        // But simulate stopping high → only low running → medium fits in second medium slot
        $checker = $this->makeChecker(
            limits:  [['high' => 1, 'low' => 1], ['medium' => 2]],
            running: ['high', 'low'],
        );
        // Simulated: only low running after stopping high
        $this->assertTrue($checker->canStartWithProfiles('medium', ['low']));
    }

    public function testCanStartWithProfilesBlockedWhenStillNoRoom(): void
    {
        $checker = $this->makeChecker(
            limits:  [['high' => 1, 'low' => 1], ['medium' => 2]],
            running: [],
        );
        // Two medium running simulated — no room for third
        $this->assertFalse($checker->canStartWithProfiles('medium', ['medium', 'medium']));
    }
}
