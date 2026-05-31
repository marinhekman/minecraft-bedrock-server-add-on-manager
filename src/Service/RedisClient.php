<?php

namespace App\Service;

use Predis\Client;

/**
 * Typed wrapper around Redis for all portal/manager state.
 *
 * Key schema:
 *   server:{name}          → JSON ServerInstance data
 *   players:{name}         → int player count
 *   loaded:{name}          → JSON array of loaded pack UUIDs
 *   stats:{name}           → JSON { cpu, memUsageMb, memLimitMb, memPercent }
 *   chat                   → Redis list of last N chat messages (JSON)
 *   votes                  → Redis hash { gamertag => serverName }
 *   heartbeat:{gamertag}   → Unix timestamp of last heartbeat
 *   vote_cooldown:{name}   → "1", TTL = cooldown seconds after a start
 *   start_countdown:{name} → Unix timestamp when countdown began, TTL = COUNTDOWN_TTL
 */
class RedisClient
{
    private const SERVER_TTL      = 60;   // seconds before server data considered stale
    private const CHAT_MAX        = 100;  // max chat messages to keep
    private const HEARTBEAT_TTL   = 120;  // default heartbeat TTL in seconds
    public  const COUNTDOWN_TTL   = 15;   // seconds before auto-start fires

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

    /**
     * Stores the player count with a TTL that expires at 2:00 AM local time.
     * At 2am there are no players, so stale counts are cleaned up naturally
     * without needing event-driven resets for every edge case.
     */
    public function setPlayerCount(string $serverName, int $count): void
    {
        $this->redis->setex("players:$serverName", $this->secondsUntil2am(), (string) $count);
    }

    public function getPlayerCount(string $serverName): int
    {
        return (int) ($this->redis->get("players:$serverName") ?? 0);
    }

    private function secondsUntil2am(): int
    {
        $now  = new \DateTimeImmutable();
        $next = new \DateTimeImmutable('today 02:00:00');

        if ($now >= $next) {
            $next = $next->modify('+1 day');
        }

        $seconds = $next->getTimestamp() - $now->getTimestamp();

        // Safety floor: at least 1 hour, at most 25 hours
        return max(3600, min($seconds, 90000));
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
        $encoded = json_encode($message);
        if ($encoded === false) {
            return;
        }
        $this->redis->rpush('chat', $encoded);
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
        $votes  = $this->getVotes();
        $counts = [];
        foreach ($votes as $serverName) {
            $counts[$serverName] = ($counts[$serverName] ?? 0) + 1;
        }
        return $counts;
    }

    public function clearVotesForServer(string $serverName): void
    {
        foreach ($this->getVotes() as $gamertag => $votedFor) {
            if ($votedFor === $serverName) {
                $this->redis->hdel('votes', [$gamertag]);
            }
        }
    }

    // ── Heartbeats ────────────────────────────────────────────────────────────

    public function setHeartbeat(string $gamertag, int $ttl = self::HEARTBEAT_TTL): void
    {
        $this->redis->setex("heartbeat:$gamertag", $ttl, (string) time());
    }

    public function getActiveUsers(): array
    {
        $keys = $this->redis->keys('heartbeat:*');
        return array_map(fn($k) => str_replace('heartbeat:', '', $k), $keys);
    }

    // ── Vote cooldown ─────────────────────────────────────────────────────────

    public function setCooldown(string $serverName, int $ttl): void
    {
        $this->redis->setex("vote_cooldown:$serverName", $ttl, '1');
    }

    public function hasCooldown(string $serverName): bool
    {
        return (bool) $this->redis->exists("vote_cooldown:$serverName");
    }

    // ── Start countdown ───────────────────────────────────────────────────────

    /**
     * Record that a countdown has begun for this server.
     * TTL matches COUNTDOWN_TTL so the key auto-expires if something goes wrong.
     */
    public function setCountdown(string $serverName): void
    {
        $this->redis->setex(
            "start_countdown:$serverName",
            self::COUNTDOWN_TTL,
            (string) time(),
        );
    }

    /**
     * Returns the Unix timestamp when the countdown started, or null if none active.
     */
    public function getCountdown(string $serverName): ?int
    {
        $val = $this->redis->get("start_countdown:$serverName");
        return $val !== null && $val !== false ? (int) $val : null;
    }

    public function clearCountdown(string $serverName): void
    {
        $this->redis->del(["start_countdown:$serverName"]);
    }

    public function getCountdownServerName(): ?string
    {
        $keys = $this->redis->keys('start_countdown:*');
        if (empty($keys)) {
            return null;
        }
        return str_replace('start_countdown:', '', $keys[0]);
    }

    // ── Raw key operations (used by TestStateSeeder) ──────────────────────────

    public function keys(string $pattern): array
    {
        return $this->redis->keys($pattern) ?? [];
    }

    public function del(array $keys): void
    {
        if ($keys !== []) {
            $this->redis->del($keys);
        }
    }

    public function setGamertagUsername(string $gamertag, string $username): void
    {
        $this->redis->setex("gamertag_user:$gamertag", self::HEARTBEAT_TTL, $username);
    }

    public function getUsernameByGamertag(string $gamertag): ?string
    {
        $val = $this->redis->get("gamertag_user:$gamertag");
        return $val !== false && $val !== null ? $val : null;
    }

    // ── Stop countdown ────────────────────────────────────────────────────────────

    public function setStopCountdown(string $serverName): void
    {
        $this->redis->setex(
            "stop_countdown:$serverName",
            self::COUNTDOWN_TTL,
            (string) time(),
        );
    }

    public function getStopCountdown(string $serverName): ?int
    {
        $val = $this->redis->get("stop_countdown:$serverName");
        return $val !== null && $val !== false ? (int) $val : null;
    }

    public function clearStopCountdown(string $serverName): void
    {
        $this->redis->del(["stop_countdown:$serverName"]);
    }
}
