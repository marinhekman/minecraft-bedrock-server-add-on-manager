<?php

declare(strict_types=1);

namespace App\Dev;

use App\Service\RedisClient;

/**
 * Seeds Redis with controlled state for tests and dev scenarios.
 * Bypasses MinecraftMonitor and DockerClient entirely.
 */
class TestStateSeeder
{
    public function __construct(
        private readonly RedisClient $redis,
    ) {}

    public function seedServer(
        string  $name,
        bool    $running,
        string  $memoryProfile = 'medium',
        int     $players = 0,
        ?int    $graceUntil = null,   // Unix timestamp when grace period ends
        bool    $cooldown = false,
        int     $cooldownTtl = 300,
        ?int    $startedAt = null,
        ?int    $port = null,
        ?string $containerId = null,
    ): void {
        $this->redis->setServer($name, [
            'name'            => $name,
            'containerId'     => $containerId ?? 'test-container-' . $name,
            'containerName'   => 'mc-' . $name,
            'containerStatus' => $running ? 'running' : 'stopped',
            'port'            => $port ?? 19132,
            'startedAt'       => $startedAt ?? time(),
            'running'         => $running,
            'memoryProfile'   => $memoryProfile,
        ]);

        $this->redis->setPlayerCount($name, $players);

        if ($graceUntil !== null) {
            // graceUntil is when it ends; setServerEmpty expects when it started.
            // We use a fixed grace of 60s to back-calculate the start timestamp.
            $grace        = 60;
            $graceStarted = $graceUntil - $grace;
            $ttlRemaining = max(1, $graceUntil - time());
            $this->redis->setServerEmpty($name, $graceStarted, $ttlRemaining);
        }

        if ($cooldown) {
            $this->redis->setCooldown($name, $cooldownTtl);
        }
    }

    public function seedVote(string $gamertag, string $serverName): void
    {
        $this->redis->setVote($gamertag, $serverName);
    }

    public function seedHeartbeat(string $gamertag, int $ttl = 120): void
    {
        // setHeartbeat() always uses time() internally, so we write directly
        // via the underlying votes mechanism — but RedisClient doesn't expose
        // a raw set. We call setHeartbeat() for active users (ttl=120 is fine)
        // and accept that inactive-user scenarios require the key to be absent.
        $this->redis->setHeartbeat($gamertag);
    }

    public function seedUser(string $username, string $gamertag, string $role): void
    {
        // Users are loaded from users.yaml by UserProvider, not from Redis.
        // This method is a no-op in the seeder — it exists so test code can
        // call it without conditionals, and functional tests handle user setup
        // via the users.yaml fixture file directly.
    }

    /**
     * Clears all vote/server/heartbeat/grace/cooldown keys from Redis.
     * Real MinecraftMonitor data repopulates on the next scan cycle.
     */
    public function reset(): void
    {
        foreach ($this->redis->getAllServerNames() as $name) {
            $this->redis->clearServerEmpty($name);
            $this->redis->clearVotesForServer($name);
        }

        // Clear all known key patterns
        foreach (['server:*', 'players:*', 'loaded:*', 'stats:*',
                  'heartbeat:*', 'vote_cooldown:*', 'server_empty:*'] as $pattern) {
            foreach ($this->redis->keys($pattern) as $key) {
                $this->redis->del([$key]);
            }
        }

        // Clear votes hash entirely
        $this->redis->del(['votes']);
    }
}
