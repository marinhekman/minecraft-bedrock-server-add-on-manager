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
        ?int    $port = null,
        ?string $containerId = null,
    ): void {
        $this->redis->setServer($name, [
            'name'            => $name,
            'containerId'     => $containerId ?? 'test-container-' . $name,
            'containerName'   => 'mc-' . $name,
            'containerStatus' => $running ? 'running' : 'stopped',
            'port'            => $port ?? 19132,
            'startedAt'       => time(),
            'running'         => $running,
            'memoryProfile'   => $memoryProfile,
        ]);

        $this->redis->setPlayerCount($name, $players);
    }

    public function seedVote(string $gamertag, string $serverName): void
    {
        $this->redis->setVote($gamertag, $serverName);
    }

    public function seedHeartbeat(string $gamertag): void
    {
        $this->redis->setHeartbeat($gamertag);
    }

    public function reset(): void
    {
        // Only clear voting and heartbeat state — never wipe server/player/stats data
        // that MinecraftMonitor is responsible for maintaining.
        foreach (['heartbeat:*'] as $pattern) {
            foreach ($this->redis->keys($pattern) as $key) {
                $this->redis->del([$key]);
            }
        }

        $this->redis->del(['votes']);
    }
}
