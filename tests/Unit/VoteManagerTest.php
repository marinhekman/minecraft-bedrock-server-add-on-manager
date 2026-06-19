<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Model\GlobalMeta;
use App\Model\ServerMeta;
use App\Security\User;
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
    private RedisClient  $redis;
    private VoteManager  $voteManager;

    protected function setUp(): void
    {
        $predis      = new \Predis\Client($_ENV['REDIS_URL'] ?? 'redis://127.0.0.1:6379');
        $this->redis = new RedisClient($predis);

        $this->flushTestKeys();

        $globalMeta = new GlobalMeta(resourceLimits: [], heartbeatTtl: 120);

        $globalMetaReader = $this->createStub(GlobalMetaReader::class);
        $globalMetaReader->method('read')->willReturn($globalMeta);

        $serverMetaReader = $this->createStub(ServerMetaReader::class);
        $serverMetaReader->method('read')->willReturn(new ServerMeta());

        $budgetChecker = new ResourceBudgetChecker($this->redis, $globalMetaReader);

        $this->voteManager = new VoteManager(
            redis:            $this->redis,
            globalMetaReader: $globalMetaReader,
            serverMetaReader: $serverMetaReader,
            budgetChecker:    $budgetChecker,
            mcDataPath:       '/tmp/mc-test',
        );
    }

    protected function tearDown(): void
    {
        $this->flushTestKeys();
    }

    private function flushTestKeys(): void
    {
        // Clear votes and heartbeats
        foreach ($this->redis->keys('heartbeat:*') as $key) {
            $this->redis->del([$key]);
        }
        foreach ($this->redis->keys('server:*') as $key) {
            $this->redis->del([$key]);
        }
        foreach ($this->redis->keys('players:*') as $key) {
            $this->redis->del([$key]);
        }
        foreach ($this->redis->keys('vote_cooldown:*') as $key) {
            $this->redis->del([$key]);
        }
        foreach ($this->redis->keys('start_countdown:*') as $key) {
            $this->redis->del([$key]);
        }
        foreach ($this->redis->keys('stop_countdown:*') as $key) {
            $this->redis->del([$key]);
        }
        $this->redis->del(['votes']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function seedServer(string $name, bool $running, string $profile = 'medium'): void
    {
        $this->redis->setServer($name, [
            'name'          => $name,
            'containerId'   => 'fake-' . $name,
            'running'       => $running,
            'memoryProfile' => $profile,
            'startedAt'     => $running ? time() : null,
        ]);
    }

    private function seedHeartbeat(string $gamertag): void
    {
        $this->redis->setHeartbeat($gamertag);
    }

    private function seedVote(string $gamertag, string $serverName): void
    {
        $this->redis->setVote($gamertag, $serverName);
    }

    #[AllowMockObjectsWithoutExpectations]
    private function makeUser(string $gamertag): User
    {
        $user = $this->createStub(User::class);
        $user->method('getGamertag')->willReturn($gamertag);
        return $user;
    }

    // ── castVote ──────────────────────────────────────────────────────────────

    public function testCastVoteStoresVote(): void
    {
        $this->seedServer('server1', running: false);
        $user = $this->makeUser('Player1');

        $this->voteManager->castVote($user, 'server1');

        $votes = $this->redis->getVotes();
        $this->assertSame('server1', $votes['Player1']);
    }

    public function testCastVoteToggleRetractsVote(): void
    {
        $this->seedServer('server1', running: false);
        $this->seedVote('Player1', 'server1');
        $user = $this->makeUser('Player1');

        $this->voteManager->castVote($user, 'server1');

        $votes = $this->redis->getVotes();
        $this->assertArrayNotHasKey('Player1', $votes);
    }

    public function testCastVoteMovesVoteToNewServer(): void
    {
        $this->seedServer('server1', running: false);
        $this->seedServer('server2', running: false);
        $this->seedVote('Player1', 'server1');
        $user = $this->makeUser('Player1');

        $this->voteManager->castVote($user, 'server2');

        $votes = $this->redis->getVotes();
        $this->assertSame('server2', $votes['Player1']);
    }

    // ── active vote counting ──────────────────────────────────────────────────

    public function testInactiveVotesDontCount(): void
    {
        $this->seedServer('server1', running: false);
        $this->seedVote('Player1', 'server1');
        $this->seedVote('Player2', 'server1');
        $this->seedVote('Player3', 'server1');
        // No heartbeats — all inactive

        $this->assertSame(0, $this->voteManager->getActiveVoteCount('server1'));
    }

    public function testActiveVotesCountCorrectly(): void
    {
        $this->seedServer('server1', running: false);
        $this->seedHeartbeat('Player1');
        $this->seedHeartbeat('Player2');
        $this->seedVote('Player1', 'server1');
        $this->seedVote('Player2', 'server1');
        $this->seedVote('Player3', 'server1'); // no heartbeat — inactive

        $this->assertSame(2, $this->voteManager->getActiveVoteCount('server1'));
    }

    // ── getVoteRanking ────────────────────────────────────────────────────────

    public function testVoteRankingSortsByActiveVotesDescending(): void
    {
        $this->seedServer('server1', running: false);
        $this->seedServer('server2', running: false);
        $this->seedServer('server3', running: false);
        $this->seedHeartbeat('Player1');
        $this->seedHeartbeat('Player2');
        $this->seedHeartbeat('Player3');
        $this->seedVote('Player1', 'server2');
        $this->seedVote('Player2', 'server2');
        $this->seedVote('Player3', 'server3');

        $ranking = $this->voteManager->getVoteRanking();

        $this->assertSame('server2', $ranking[0]['name']);
        $this->assertSame('server3', $ranking[1]['name']);
        $this->assertSame('server1', $ranking[2]['name']);
    }

    public function testVoteRankingTiesResolvedAlphabetically(): void
    {
        $this->seedServer('server1', running: false);
        $this->seedServer('server2', running: false);
        $this->seedHeartbeat('Player1');
        $this->seedHeartbeat('Player2');
        $this->seedVote('Player1', 'server2');
        $this->seedVote('Player2', 'server1');

        $ranking = $this->voteManager->getVoteRanking();

        $this->assertSame('server1', $ranking[0]['name']);
        $this->assertSame('server2', $ranking[1]['name']);
    }

    public function testVoteRankingIncludesRunningServers(): void
    {
        $this->seedServer('server1', running: true);
        $this->seedServer('server2', running: false);

        $ranking = $this->voteManager->getVoteRanking();
        $names   = array_column($ranking, 'name');

        $this->assertContains('server1', $names);
        $this->assertContains('server2', $names);
    }

    // ── checkAndTrigger ───────────────────────────────────────────────────────

    public function testCheckAndTriggerReturnsLeaderWithMostVotes(): void
    {
        $this->seedServer('server1', running: false);
        $this->seedServer('server2', running: false);
        $this->seedHeartbeat('Player1');
        $this->seedHeartbeat('Player2');
        $this->seedVote('Player1', 'server1');
        $this->seedVote('Player2', 'server1');

        $this->assertSame('server1', $this->voteManager->checkAndTrigger());
    }

    public function testCheckAndTriggerReturnsNullOnTie(): void
    {
        $this->seedServer('server1', running: false);
        $this->seedServer('server2', running: false);
        $this->seedHeartbeat('Player1');
        $this->seedHeartbeat('Player2');
        $this->seedVote('Player1', 'server1');
        $this->seedVote('Player2', 'server2');

        $this->assertNull($this->voteManager->checkAndTrigger());
    }

    public function testCheckAndTriggerReturnsNullWhenNoVotes(): void
    {
        $this->seedServer('server1', running: false);

        $this->assertNull($this->voteManager->checkAndTrigger());
    }

    public function testCheckAndTriggerReturnsNullWhenCooldownActive(): void
    {
        $this->seedServer('server1', running: false);
        $this->seedHeartbeat('Player1');
        $this->seedVote('Player1', 'server1');
        $this->redis->setCooldown('server1', 60);

        $this->assertNull($this->voteManager->checkAndTrigger());
    }

    public function testCheckAndTriggerAllowsStartWhenResourcesPermit(): void
    {
        // No resource limits configured → always allowed
        $this->seedServer('server1', running: false);
        $this->seedServer('server2', running: true);
        $this->seedHeartbeat('Player1');
        $this->seedVote('Player1', 'server1');

        $this->assertSame('server1', $this->voteManager->checkAndTrigger());
    }

    // ── getServersToAutoStop ──────────────────────────────────────────────────

    public function testGetServersToAutoStopReturnsEmptyWhenAlreadyStartable(): void
    {
        $this->seedServer('server1', running: false);
        $this->seedHeartbeat('Player1');
        $this->seedVote('Player1', 'server1');

        $this->assertSame([], $this->voteManager->getServersToAutoStop());
    }

    public function testGetServersToAutoStopReturnsEmptyWhenNoVotes(): void
    {
        $this->seedServer('server1', running: false);
        $this->seedServer('server2', running: true);

        $this->assertSame([], $this->voteManager->getServersToAutoStop());
    }
}
