<?php

namespace App\Service;

use Predis\Client;

/**
 * Typed wrapper around Redis for all portal/manager state.
 *
 * Key schema:
 *   server:{name}        → JSON ServerInstance data
 *   players:{name}       → int player count
 *   loaded:{name}        → JSON array of loaded pack UUIDs
 *   stats:{name}         → JSON { cpu, memUsageMb, memLimitMb, memPercent }
 *   chat                 → Redis list of last N chat messages (JSON)
 *   votes                → Redis hash { gamertag => serverName }
 *   heartbeat:{gamertag} → Unix timestamp of last heartbeat
 */
class RedisClient
{
    private const SERVER_TTL  = 60;   // seconds before server data considered stale
    private const CHAT_MAX    = 100;  // max chat messages to keep

    public function __construct(
        private readonly Client $redis,
    ) {}

    // ── Server data ───────────────────────────────────────────────────────────

    public function setServer(string $name, array $data): void
    {
        $this->redis->setex("server:$name", self::SERVER_TTL, json_encode($data));
    }

    public function getServer(string $name): ?array
    {
        $val = $this->redis->get("server:$name");
        return $val ? json_decode($val, true) : null;
    }

    public function getAllServerNames(): array
    {
        $keys = $this->redis->keys('server:*');
        return array_map(fn($k) => str_replace('server:', '', $k), $keys);
    }

    // ── Player counts ─────────────────────────────────────────────────────────

    public function setPlayerCount(string $serverName, int $count): void
    {
        $this->redis->setex("players:$serverName", self::SERVER_TTL, $count);
    }

    public function getPlayerCount(string $serverName): int
    {
        return (int) ($this->redis->get("players:$serverName") ?? 0);
    }

    // ── Loaded pack UUIDs ─────────────────────────────────────────────────────

    public function setLoadedUuids(string $serverName, array $uuids): void
    {
        $this->redis->setex("loaded:$serverName", self::SERVER_TTL, json_encode($uuids));
    }

    public function getLoadedUuids(string $serverName): array
    {
        $val = $this->redis->get("loaded:$serverName");
        return $val ? json_decode($val, true) : [];
    }

    // ── Container stats ───────────────────────────────────────────────────────

    public function setStats(string $serverName, array $stats): void
    {
        $this->redis->setex("stats:$serverName", self::SERVER_TTL, json_encode($stats));
    }

    public function getStats(string $serverName): ?array
    {
        $val = $this->redis->get("stats:$serverName");
        return $val ? json_decode($val, true) : null;
    }

    // ── Chat ──────────────────────────────────────────────────────────────────

    public function pushChatMessage(array $message): void
    {
        $this->redis->rpush('chat', json_encode($message));
        $this->redis->ltrim('chat', -self::CHAT_MAX, -1);
    }

    public function getChatHistory(): array
    {
        return array_map(
            fn($m) => json_decode($m, true),
            $this->redis->lrange('chat', 0, -1)
        );
    }

    // ── Votes ─────────────────────────────────────────────────────────────────

    public function setVote(string $gamertag, string $serverName): void
    {
        $this->redis->hset('votes', $gamertag, $serverName);
    }

    public function removeVote(string $gamertag): void
    {
        $this->redis->hdel('votes', [$gamertag]);
    }

    public function getVotes(): array
    {
        return $this->redis->hgetall('votes') ?? [];
    }

    public function getVoteCountPerServer(): array
    {
        $votes = $this->getVotes();
        $counts = [];
        foreach ($votes as $serverName) {
            $counts[$serverName] = ($counts[$serverName] ?? 0) + 1;
        }
        return $counts;
    }

    // ── Heartbeats ────────────────────────────────────────────────────────────

    public function setHeartbeat(string $gamertag): void
    {
        $this->redis->setex("heartbeat:$gamertag", 120, time());
    }

    public function getActiveUsers(): array
    {
        $keys = $this->redis->keys('heartbeat:*');
        return array_map(fn($k) => str_replace('heartbeat:', '', $k), $keys);
    }
}
