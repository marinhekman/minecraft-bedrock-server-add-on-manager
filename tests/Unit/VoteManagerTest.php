<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dev\TestStateSeeder;
use App\Model\GlobalMeta;
use App\Security\User;
use App\Service\GlobalMetaReader;
use App\Service\RedisClient;
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
    private VoteManager     $voteManager;

    protected function setUp(): void
    {
        $predis = new \Predis\Client($_ENV['REDIS_URL'] ?? 'redis://127.0.0.1:6379');
        $this->redis  = new RedisClient($predis);
        $this->seeder = new TestStateSeeder($this->redis);
        $this->seeder->reset();

        $globalMeta = new GlobalMeta(heartbeatTtl: 120);

        $globalMetaReader = $this->createStub(GlobalMetaReader::class);
        $globalMetaReader->method('read')->willReturn($globalMeta);

        $serverMetaReader = $this->createStub(ServerMetaReader::class);
        $serverMetaReader->method('read')->willReturn(new \App\Model\ServerMeta());

        $this->voteManager = new VoteManager(
            redis:            $this->redis,
            globalMetaReader: $globalMetaReader,
            serverMetaReader: $serverMetaReader,
            mcDataPath:       '/tmp/mc-test',
        );
    }

    protected function tearDown(): void
    {
        $this->seeder->reset();
    }

    // ── castVote ──────────────────────────────────────────────────────────────

    public function testCastVoteStoresVote(): void
    {
        $this->seeder->seedServer('server1', running: false);
        $user = $this->makeUser('Player1');

        $this->voteManager->castVote($user, 'server1');

        $votes = $this->redis->getVotes();
        $this->assertSame('server1', $votes['Player1']);
    }

    public function testCastVoteToggleRetractsVote(): void
    {
        $this->seeder->seedServer('server1', running: false);
        $this->seeder->seedVote('Player1', 'server1');
        $user = $this->makeUser('Player1');

        $this->voteManager->castVote($user, 'server1');

        $votes = $this->redis->getVotes();
        $this->assertArrayNotHasKey('Player1', $votes);
    }

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

    // ── active vote counting ──────────────────────────────────────────────────

    public function testInactiveVotesDontCount(): void
    {
        $this->seeder->seedServer('server1', running: false);
        // Votes with no heartbeat — all inactive
        $this->seeder->seedVote('Player1', 'server1');
        $this->seeder->seedVote('Player2', 'server1');
        $this->seeder->seedVote('Player3', 'server1');

        $this->assertSame(0, $this->voteManager->getActiveVoteCount('server1'));
    }

    public function testActiveVotesCountCorrectly(): void
    {
        $this->seeder->seedServer('server1', running: false);
        $this->seeder->seedHeartbeat('Player1');
        $this->seeder->seedHeartbeat('Player2');
        $this->seeder->seedVote('Player1', 'server1');
        $this->seeder->seedVote('Player2', 'server1');
        // Player3 voted but no heartbeat
        $this->seeder->seedVote('Player3', 'server1');

        $this->assertSame(2, $this->voteManager->getActiveVoteCount('server1'));
    }

    // ── getVoteRanking ────────────────────────────────────────────────────────

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

    public function testVoteRankingIncludesRunningServers(): void
    {
        $this->seeder->seedServer('server1', running: true);
        $this->seeder->seedServer('server2', running: false);

        $ranking  = $this->voteManager->getVoteRanking();
        $names    = array_column($ranking, 'name');

        // Running servers still appear in ranking (vote button hidden in UI)
        $this->assertContains('server1', $names);
        $this->assertContains('server2', $names);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    #[AllowMockObjectsWithoutExpectations]
    private function makeUser(string $gamertag): User
    {
        $user = $this->createStub(User::class);
        $user->method('getGamertag')->willReturn($gamertag);
        return $user;
    }
}
