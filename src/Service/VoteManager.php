<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\GlobalMeta;
use App\Security\User;

final class VoteManager
{
    public function __construct(
        private readonly RedisClient           $redis,
        private readonly DockerClient          $dockerClient,
        private readonly ResourceBudgetChecker $budgetChecker,
        private readonly GlobalMetaReader      $globalMetaReader,
        private readonly ServerMetaReader      $serverMetaReader,
        private readonly string                $mcDataPath,
    ) {}

    // ── Casting votes ─────────────────────────────────────────────────────────

    public function castVote(User $user, string $serverName): void
    {
        $gamertag   = $user->getGamertag();
        $currentVote = $this->redis->getVotes()[$gamertag] ?? null;

        if ($currentVote === $serverName) {
            // Toggle: voting for the same server retracts the vote.
            $this->redis->removeVote($gamertag);
            return;
        }

        $this->redis->setVote($gamertag, $serverName);
        $this->checkAndTrigger($serverName);
    }

    public function retractVote(User $user): void
    {
        $this->redis->removeVote($user->getGamertag());
    }

    public function clearVotesForServer(string $serverName): void
    {
        $this->redis->clearVotesForServer($serverName);
    }

    // ── Vote queries ──────────────────────────────────────────────────────────

    /**
     * Returns the number of active (heartbeat-valid) votes for a server.
     */
    public function getActiveVoteCount(string $serverName): int
    {
        return count($this->getActiveVotersForServer($serverName));
    }

    /**
     * Returns [{gamertag, avatarPath}] for active voters on a server.
     *
     * @return list<array{gamertag: string, avatarPath: string}>
     */
    public function getActiveVoters(string $serverName): array
    {
        $result = [];

        foreach ($this->getActiveVotersForServer($serverName) as $gamertag) {
            $result[] = [
                'gamertag'   => $gamertag,
                'avatarPath' => $this->resolveAvatarPath($gamertag),
            ];
        }

        return $result;
    }

    /**
     * Returns stopped servers sorted by active vote count descending,
     * with ties broken alphabetically.
     *
     * @return list<array{name: string, activeVotes: int}>
     */
    public function getVoteRanking(): array
    {
        $ranking = [];

        foreach ($this->redis->getAllServerNames() as $name) {
            $data = $this->redis->getServer($name);
            if ($data === null || ($data['running'] ?? false)) {
                continue;
            }
            $ranking[] = [
                'name'        => $name,
                'activeVotes' => $this->getActiveVoteCount($name),
            ];
        }

        usort($ranking, function (array $a, array $b): int {
            if ($a['activeVotes'] !== $b['activeVotes']) {
                return $b['activeVotes'] <=> $a['activeVotes'];
            }
            return $a['name'] <=> $b['name'];
        });

        return $ranking;
    }

    // ── Auto-start trigger ────────────────────────────────────────────────────

    /**
     * Full check sequence. Starts the server if all conditions pass.
     * Safe to call speculatively after every vote cast.
     */
    public function checkAndTrigger(string $serverName): void
    {
        $data = $this->redis->getServer($serverName);

        // 1. Server must be stopped.
        if ($data === null || ($data['running'] ?? false)) {
            return;
        }

        // 2. No vote cooldown active.
        if ($this->redis->hasCooldown($serverName)) {
            return;
        }

        // 3. Active votes must meet or exceed threshold.
        if ($this->getActiveVoteCount($serverName) < $this->getThreshold($serverName)) {
            return;
        }

        // 4. No running server has players.
        foreach ($this->redis->getAllServerNames() as $name) {
            if ($this->redis->getPlayerCount($name) > 0) {
                return;
            }
        }

        // 5. No running server is in grace period.
        foreach ($this->redis->getAllServerNames() as $name) {
            if ($this->redis->getServerEmpty($name) !== null) {
                return;
            }
        }

        // 6. Resource budget allows starting this server.
        $profile = $data['memoryProfile'] ?? 'medium';
        if (!$this->budgetChecker->canStart($profile)) {
            return;
        }

        $this->doStart($serverName, $data);
    }

    /**
     * Called by MinecraftMonitor when a server's grace period has expired
     * and it still has 0 players. Stops the server only if another server
     * is waiting with enough votes and is resource-blocked by this one.
     */
    public function triggerAutoStopIfNeeded(string $serverName): void
    {
        $data = $this->redis->getServer($serverName);

        // Must still be running with 0 players.
        if ($data === null || !($data['running'] ?? false)) {
            return;
        }

        if ($this->redis->getPlayerCount($serverName) > 0) {
            return;
        }

        // Find a stopped server that has enough votes and is resource-blocked.
        $waitingServer = $this->findWaitingServer($serverName);
        if ($waitingServer === null) {
            return;
        }

        $containerId = $data['containerId'] ?? null;
        if ($containerId === null) {
            return;
        }

        $this->output("Auto-stopping $serverName to make room for $waitingServer");

        $this->dockerClient->stopContainer($containerId);
        $this->redis->clearServerEmpty($serverName);
    }

    // ── Blocking reason ───────────────────────────────────────────────────────

    /**
     * Returns the primary reason a stopped server cannot start yet,
     * or null if it can start (or is already running).
     *
     * @return 'players'|'grace'|'resources'|null
     */
    public function getBlockingReason(string $serverName): ?string
    {
        $data = $this->redis->getServer($serverName);
        if ($data === null || ($data['running'] ?? false)) {
            return null;
        }

        // Check for players on any running server.
        foreach ($this->redis->getAllServerNames() as $name) {
            if ($name === $serverName) {
                continue;
            }
            $otherData = $this->redis->getServer($name);
            if ($otherData === null || !($otherData['running'] ?? false)) {
                continue;
            }
            if ($this->redis->getPlayerCount($name) > 0) {
                return 'players';
            }
        }

        // Check for a running server in grace period.
        foreach ($this->redis->getAllServerNames() as $name) {
            if ($name === $serverName) {
                continue;
            }
            if ($this->redis->getServerEmpty($name) !== null) {
                return 'grace';
            }
        }

        // Check resource budget.
        $profile = $data['memoryProfile'] ?? 'medium';
        if (!$this->budgetChecker->canStart($profile)) {
            return 'resources';
        }

        return null;
    }

    /**
     * Returns a human-readable explanation for the blocking reason,
     * for use in the frontend callout block.
     */
    public function getBlockingDetail(string $serverName): string
    {
        return match ($this->getBlockingReason($serverName)) {
            'players'   => $this->buildPlayersBlockingDetail(),
            'grace'     => $this->buildGraceBlockingDetail(),
            'resources' => $this->buildResourcesBlockingDetail($serverName),
            default     => '',
        };
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private function doStart(string $serverName, array $serverData): void
    {
        $containerId = $serverData['containerId'] ?? null;
        if ($containerId === null) {
            return;
        }

        $globalMeta = $this->globalMetaReader->read();
        $cooldown   = $this->getEffectiveCooldown($serverName, $globalMeta);

        $this->dockerClient->restartContainer($containerId);
        $this->redis->clearVotesForServer($serverName);
        $this->redis->setCooldown($serverName, $cooldown);
    }

    /**
     * Returns the gamertags of users who voted for $serverName
     * and still have an active heartbeat.
     *
     * @return list<string>
     */
    private function getActiveVotersForServer(string $serverName): array
    {
        $activeUsers = array_flip($this->redis->getActiveUsers());
        $voters      = [];

        foreach ($this->redis->getVotes() as $gamertag => $votedFor) {
            if ($votedFor === $serverName && isset($activeUsers[$gamertag])) {
                $voters[] = $gamertag;
            }
        }

        return $voters;
    }

    /**
     * Finds a stopped server that has active votes >= its threshold
     * and is resource-blocked specifically because $blockingServerName
     * is running.
     */
    private function findWaitingServer(string $blockingServerName): ?string
    {
        foreach ($this->redis->getAllServerNames() as $name) {
            if ($name === $blockingServerName) {
                continue;
            }

            $data = $this->redis->getServer($name);
            if ($data === null || ($data['running'] ?? false)) {
                continue;
            }

            if ($this->getActiveVoteCount($name) < $this->getThreshold($name)) {
                continue;
            }

            // Is it resource-blocked? i.e. it can't start now but could if
            // $blockingServerName were stopped.
            $profile = $data['memoryProfile'] ?? 'medium';
            if (!$this->budgetChecker->canStart($profile)) {
                return $name;
            }
        }

        return null;
    }

    public function getThreshold(string $serverName): int
    {
        $globalMeta = $this->globalMetaReader->read();
        $serverMeta = $this->serverMetaReader->read(
            $this->mcDataPath . '/' . $serverName,
        );

        return $serverMeta->voteThreshold ?? $globalMeta->voteThreshold;
    }

    private function getEffectiveCooldown(string $serverName, GlobalMeta $globalMeta): int
    {
        $serverMeta = $this->serverMetaReader->read(
            $this->mcDataPath . '/' . $serverName,
        );

        return $serverMeta->voteCooldown ?? $globalMeta->voteCooldown;
    }

    private function resolveAvatarPath(string $gamertag): string
    {
        // Lowercase gamertag for filesystem lookup, matching UserProvider convention.
        $username = strtolower($gamertag);
        $path     = '/mc-data/config/avatars/' . $username . '.png';

        return file_exists($path)
            ? '/avatars/' . $username . '.png'
            : '/images/avatar_default.png';
    }

    private function buildPlayersBlockingDetail(): string
    {
        foreach ($this->redis->getAllServerNames() as $name) {
            $data = $this->redis->getServer($name);
            if ($data === null || !($data['running'] ?? false)) {
                continue;
            }
            $count = $this->redis->getPlayerCount($name);
            if ($count > 0) {
                $displayName = $data['containerName'] ?? $name;
                $noun        = $count === 1 ? 'player' : 'players';
                return sprintf(
                    '%s still has %d %s online. This server will start automatically once they leave.',
                    $displayName,
                    $count,
                    $noun,
                );
            }
        }

        return 'Another server still has players online.';
    }

    private function buildGraceBlockingDetail(): string
    {
        foreach ($this->redis->getAllServerNames() as $name) {
            if ($this->redis->getServerEmpty($name) !== null) {
                $data        = $this->redis->getServer($name);
                $displayName = $data['containerName'] ?? $name;
                return sprintf(
                    '%s is empty and stopping. This server will start automatically once it stops.',
                    $displayName,
                );
            }
        }

        return 'Another server is empty and stopping.';
    }

    private function buildResourcesBlockingDetail(string $serverName): string
    {
        $data    = $this->redis->getServer($serverName);
        $profile = $data['memoryProfile'] ?? 'medium';

        return sprintf(
            'This server requires %s memory resources. Waiting for other servers to free up resources before it can start.',
            $profile,
        );
    }

    private function output(string $message): void
    {
        // VoteManager has no OutputInterface — log via error_log for now.
        // MinecraftMonitor owns the output; this is a background-safe fallback.
        error_log('[VoteManager] ' . $message);
    }
}

