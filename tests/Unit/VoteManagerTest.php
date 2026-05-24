<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dev\TestStateSeeder;
use App\Model\GlobalMeta;
use App\Security\User;
use App\Service\DockerClient;
use App\Service\GlobalMetaReader;
use App\Service\RedisClient;
use App\Service\ResourceBudgetChecker;
use App\Service\ServerMetaReader;
use App\Service\VoteManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VoteManager using a real RedisClient against a live mc-redis.
 * Run with: php bin/phpunit --testsuite unit
 *
 * Requires: running mc-redis on mc-redis:6379 (set via REDIS_URL in phpunit.xml).
 */
class VoteManagerTest extends TestCase
{
    private RedisClient     $redis;
    private TestStateSeeder $seeder;
    private DockerClient    $docker;
    private VoteManager     $voteManager;

    protected function setUp(): void
    {
        $predis = new \Predis\Client($_ENV['REDIS_URL'] ?? 'redis://127.0.0.1:6379');
        $this->redis  = new RedisClient($predis);
        $this->seeder = new TestStateSeeder($this->redis);
        $this->seeder->reset();

        $this->docker = $this->createMock(DockerClient::class);

        $globalMeta = new GlobalMeta(
            resourceLimits:   [['high' => 1, 'low' => 1], ['medium' => 2]],
            voteThreshold:    3,
            voteCooldown:     300,
            serverEmptyGrace: 60,
        );

        $globalMetaReader = $this->createStub(GlobalMetaReader::class);
        $globalMetaReader->method('read')->willReturn($globalMeta);

        $serverMetaReader = $this->createStub(ServerMetaReader::class);
        $serverMetaReader->method('read')->willReturn(new \App\Model\ServerMeta());

        $budgetChecker = new ResourceBudgetChecker($this->redis, $globalMetaReader);

        $this->voteManager = new VoteManager(
            redis:            $this->redis,
            dockerClient:     $this->docker,
            budgetChecker:    $budgetChecker,
            globalMetaReader: $globalMetaReader,
            serverMetaReader: $serverMetaReader,
            mcDataPath:       '/tmp/mc-test',
        );
    }

    protected function tearDown(): void
    {
        $this->seeder->reset();
    }

    // ── checkAndTrigger ───────────────────────────────────────────────────────

    public function testAutoStartTriggeredWhenAllClear(): void
    {
        $this->docker->expects($this->once())->method('restartContainer');

        $this->seeder->seedServer('server1', running: false, memoryProfile: 'medium');
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server1');
        $this->seeder->seedVote('Player2', 'server1');
        $this->seeder->seedVote('Player3', 'server1');

        $this->voteManager->checkAndTrigger('server1');

        $this->assertTrue($this->redis->hasCooldown('server1'));
    }

    public function testAutoStartNotTriggeredBelowThreshold(): void
    {
        $this->docker->expects($this->never())->method('restartContainer');

        $this->seeder->seedServer('server1', running: false, memoryProfile: 'medium');
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedVote('Player1', 'server1');
        $this->seeder->seedVote('Player2', 'server1');

        $this->voteManager->checkAndTrigger('server1');

        $this->assertFalse($this->redis->hasCooldown('server1'));
    }

    public function testAutoStartNotTriggeredWhenAlreadyRunning(): void
    {
        $this->docker->expects($this->never())->method('restartContainer');

        $this->seeder->seedServer('server1', running: true, memoryProfile: 'medium');
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server1');
        $this->seeder->seedVote('Player2', 'server1');
        $this->seeder->seedVote('Player3', 'server1');

        $this->voteManager->checkAndTrigger('server1');

        $this->assertFalse($this->redis->hasCooldown('server1'));
    }

    public function testAutoStartNotTriggeredDuringCooldown(): void
    {
        $this->docker->expects($this->never())->method('restartContainer');

        $this->seeder->seedServer('server1', running: false, memoryProfile: 'medium', cooldown: true);
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server1');
        $this->seeder->seedVote('Player2', 'server1');
        $this->seeder->seedVote('Player3', 'server1');

        $this->voteManager->checkAndTrigger('server1');

        // Cooldown was pre-existing — still present, no new start triggered.
        $this->assertTrue($this->redis->hasCooldown('server1'));
    }

    public function testAutoStartNotTriggeredWithPlayersOnOtherServer(): void
    {
        $this->docker->expects($this->never())->method('restartContainer');

        $this->seeder->seedServer('server1', running: false, memoryProfile: 'medium');
        $this->seeder->seedServer('server2', running: true,  memoryProfile: 'medium', players: 2);
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server1');
        $this->seeder->seedVote('Player2', 'server1');
        $this->seeder->seedVote('Player3', 'server1');

        $this->voteManager->checkAndTrigger('server1');

        $this->assertFalse($this->redis->hasCooldown('server1'));
    }

    public function testAutoStartNotTriggeredDuringGracePeriod(): void
    {
        $this->docker->expects($this->never())->method('restartContainer');

        $this->seeder->seedServer('server1', running: false, memoryProfile: 'medium');
        $this->seeder->seedServer('server2', running: true,  memoryProfile: 'medium', players: 0, graceUntil: time() + 30);
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server1');
        $this->seeder->seedVote('Player2', 'server1');
        $this->seeder->seedVote('Player3', 'server1');

        $this->voteManager->checkAndTrigger('server1');

        $this->assertFalse($this->redis->hasCooldown('server1'));
    }

    public function testAutoStartNotTriggeredWhenResourceBlocked(): void
    {
        $this->docker->expects($this->never())->method('restartContainer');

        // high is running; medium candidate doesn't fit any combination with high
        $this->seeder->seedServer('server1', running: false, memoryProfile: 'medium');
        $this->seeder->seedServer('server2', running: true,  memoryProfile: 'high');
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server1');
        $this->seeder->seedVote('Player2', 'server1');
        $this->seeder->seedVote('Player3', 'server1');

        $this->voteManager->checkAndTrigger('server1');

        $this->assertFalse($this->redis->hasCooldown('server1'));
    }

    public function testInactiveVotesDontCountTowardThreshold(): void
    {
        $this->docker->expects($this->never())->method('restartContainer');

        $this->seeder->seedServer('server1', running: false, memoryProfile: 'medium');
        // Three votes exist but no heartbeats — all inactive
        $this->seeder->seedVote('Player1', 'server1');
        $this->seeder->seedVote('Player2', 'server1');
        $this->seeder->seedVote('Player3', 'server1');

        $this->voteManager->checkAndTrigger('server1');

        $this->assertFalse($this->redis->hasCooldown('server1'));
    }

    public function testCooldownSetAfterStart(): void
    {
        $this->docker->expects($this->once())->method('restartContainer');

        $this->seeder->seedServer('server1', running: false, memoryProfile: 'medium');
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server1');
        $this->seeder->seedVote('Player2', 'server1');
        $this->seeder->seedVote('Player3', 'server1');

        $this->voteManager->checkAndTrigger('server1');

        $this->assertTrue($this->redis->hasCooldown('server1'));
    }

    public function testVotesClearedAfterStart(): void
    {
        $this->docker->expects($this->once())->method('restartContainer');

        $this->seeder->seedServer('server1', running: false, memoryProfile: 'medium');
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server1');
        $this->seeder->seedVote('Player2', 'server1');
        $this->seeder->seedVote('Player3', 'server1');

        $this->voteManager->checkAndTrigger('server1');

        $this->assertSame(0, $this->voteManager->getActiveVoteCount('server1'));
    }

    // ── triggerAutoStopIfNeeded ───────────────────────────────────────────────

    public function testAutoStopTriggeredWhenServerWaiting(): void
    {
        $this->docker->expects($this->once())->method('stopContainer');

        // high + medium is not a valid combination, so server2 is resource-blocked by server1
        $this->seeder->seedServer('server1', running: true,  memoryProfile: 'high', players: 0);
        $this->seeder->seedServer('server2', running: false, memoryProfile: 'medium');
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server2');
        $this->seeder->seedVote('Player2', 'server2');
        $this->seeder->seedVote('Player3', 'server2');

        $this->voteManager->triggerAutoStopIfNeeded('server1');

        $this->assertSame(3, $this->voteManager->getActiveVoteCount('server2'));
    }

    public function testAutoStopNotTriggeredIfNoServerWaiting(): void
    {
        $this->docker->expects($this->never())->method('stopContainer');

        $this->seeder->seedServer('server1', running: true, memoryProfile: 'medium', players: 0);

        $this->voteManager->triggerAutoStopIfNeeded('server1');

        $data = $this->redis->getServer('server1');
        $this->assertTrue($data['running']);
    }

    public function testAutoStopNotTriggeredIfPlayersPresent(): void
    {
        $this->docker->expects($this->never())->method('stopContainer');

        $this->seeder->seedServer('server1', running: true,  memoryProfile: 'medium', players: 2);
        $this->seeder->seedServer('server2', running: false, memoryProfile: 'medium');
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server2');
        $this->seeder->seedVote('Player2', 'server2');
        $this->seeder->seedVote('Player3', 'server2');

        $this->voteManager->triggerAutoStopIfNeeded('server1');

        $data = $this->redis->getServer('server1');
        $this->assertTrue($data['running']);
    }

    public function testAutoStopNotTriggeredIfWaitingServerNotResourceBlocked(): void
    {
        $this->docker->expects($this->never())->method('stopContainer');

        // Both medium — two medium is a valid combination, so server2 is NOT blocked
        $this->seeder->seedServer('server1', running: true,  memoryProfile: 'medium', players: 0);
        $this->seeder->seedServer('server2', running: false, memoryProfile: 'medium');
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server2');
        $this->seeder->seedVote('Player2', 'server2');
        $this->seeder->seedVote('Player3', 'server2');

        $this->voteManager->triggerAutoStopIfNeeded('server1');

        $data = $this->redis->getServer('server1');
        $this->assertTrue($data['running']);
    }

    // ── castVote ──────────────────────────────────────────────────────────────

    #[AllowMockObjectsWithoutExpectations]
    public function testCastVoteStoresVote(): void
    {
        $this->seeder->seedServer('server1', running: false);
        $user = $this->makeUser('Player1');

        $this->voteManager->castVote($user, 'server1');

        $votes = $this->redis->getVotes();
        $this->assertSame('server1', $votes['Player1']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCastVoteToggleRetractsVote(): void
    {
        $this->seeder->seedServer('server1', running: false);
        $this->seeder->seedVote('Player1', 'server1');
        $user = $this->makeUser('Player1');

        $this->voteManager->castVote($user, 'server1');

        $votes = $this->redis->getVotes();
        $this->assertArrayNotHasKey('Player1', $votes);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCastVoteMovesVoteToNewServer(): void
    {
        $this->seeder->seedServer('server1', running: false);
        $this->seeder->seedServer('server2', running: false);
        $this->seeder->seedVote('Player1', 'server1');
        $user = $this->makeUser('Player1');

        $this->voteManager->castVote($user, 'server2');

        $votes = $this->redis->getVotes();
        $this->assertSame('server2', $votes['Player1']);
    }

    // ── getVoteRanking ────────────────────────────────────────────────────────

    #[AllowMockObjectsWithoutExpectations]
    public function testVoteRankingSortsByActiveVotesDescending(): void
    {
        $this->seeder->seedServer('server1', running: false);
        $this->seeder->seedServer('server2', running: false);
        $this->seeder->seedServer('server3', running: false);
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedHeartbeat('Player3');
        $this->seeder->seedVote('Player1', 'server2');
        $this->seeder->seedVote('Player2', 'server2');
        $this->seeder->seedVote('Player3', 'server3');

        $ranking = $this->voteManager->getVoteRanking();

        $this->assertSame('server2', $ranking[0]['name']);
        $this->assertSame('server3', $ranking[1]['name']);
        $this->assertSame('server1', $ranking[2]['name']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testVoteRankingTiesResolvedAlphabetically(): void
    {
        $this->seeder->seedServer('server1', running: false);
        $this->seeder->seedServer('server2', running: false);
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedVote('Player1', 'server2');
        $this->seeder->seedVote('Player2', 'server1');

        $ranking = $this->voteManager->getVoteRanking();

        $this->assertSame('server1', $ranking[0]['name']);
        $this->assertSame('server2', $ranking[1]['name']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testVoteRankingExcludesRunningServers(): void
    {
        $this->seeder->seedServer('server1', running: true);
        $this->seeder->seedServer('server2', running: false);

        $ranking = $this->voteManager->getVoteRanking();

        $names = array_column($ranking, 'name');
        $this->assertNotContains('server1', $names);
        $this->assertContains('server2', $names);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $gamertag): User
    {
        $user = $this->createStub(User::class);
        $user->method('getGamertag')->willReturn($gamertag);
        return $user;
    }
}
