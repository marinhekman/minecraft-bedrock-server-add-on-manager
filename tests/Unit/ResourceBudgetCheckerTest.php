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
            resourceLimits:   $limits,
            voteThreshold:    3,
            voteCooldown:     300,
            serverEmptyGrace: 60,
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

    public function testLowAllowedWithHigh(): void
    {
        $checker = $this->makeChecker(
            limits:  [['high' => 1, 'low' => 1], ['medium' => 2]],
            running: ['high'],
        );
        $this->assertTrue($checker->canStart('low'));
    }

    public function testTwoMediumAllowed(): void
    {
        $checker = $this->makeChecker(
            limits:  [['high' => 1, 'low' => 1], ['medium' => 2]],
            running: ['medium'],
        );
        $this->assertTrue($checker->canStart('medium'));
    }

    public function testHighBlockedByMedium(): void
    {
        $checker = $this->makeChecker(
            limits:  [['high' => 1, 'low' => 1], ['medium' => 2]],
            running: ['medium'],
        );
        $this->assertFalse($checker->canStart('high'));
    }

    public function testMediumBlockedByHigh(): void
    {
        $checker = $this->makeChecker(
            limits:  [['high' => 1, 'low' => 1], ['medium' => 2]],
            running: ['high'],
        );
        $this->assertFalse($checker->canStart('medium'));
    }

    public function testThreeMediumBlocked(): void
    {
        $checker = $this->makeChecker(
            limits:  [['high' => 1, 'low' => 1], ['medium' => 2]],
            running: ['medium', 'medium'],
        );
        $this->assertFalse($checker->canStart('medium'));
    }

    public function testMediumPlusLowBlocksAnything(): void
    {
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
    }

    // ── isValidCombination ────────────────────────────────────────────────────

    public function testUnlistedProfileBlocksCombination(): void
    {
        $checker = $this->makeChecker(limits: [], running: []);
        $this->assertFalse(
            $checker->isValidCombination(['high', 'medium'], ['high' => 1]),
        );
    }

    public function testExactLimitAllowed(): void
    {
        $checker = $this->makeChecker(limits: [], running: []);
        $this->assertTrue(
            $checker->isValidCombination(['high', 'low'], ['high' => 1, 'low' => 1]),
        );
    }

    public function testExceedingLimitBlocked(): void
    {
        $checker = $this->makeChecker(limits: [], running: []);
        $this->assertFalse(
            $checker->isValidCombination(['medium', 'medium', 'medium'], ['medium' => 2]),
        );
    }
}
