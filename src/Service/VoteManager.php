<?php

declare(strict_types=1);

namespace App\Service;

use App\Security\User;

final class VoteManager
{
    public function __construct(
        private readonly RedisClient           $redis,
        private readonly GlobalMetaReader      $globalMetaReader,
        private readonly ServerMetaReader      $serverMetaReader,
        private readonly ResourceBudgetChecker $budgetChecker,
        private readonly string                $mcDataPath,
    ) {}

    // ── Casting votes ─────────────────────────────────────────────────────────

    public function castVote(User $user, string $serverName): void
    {
        $gamertag    = $user->getGamertag();
        $currentVote = $this->redis->getVotes()[$gamertag] ?? null;

        if ($currentVote === $serverName) {
            $this->redis->removeVote($gamertag);
        } else {
            $this->redis->setVote($gamertag, $serverName);
        }
    }

    public function retractVote(User $user): void
    {
        $this->redis->removeVote($user->getGamertag());
    }

    // ── Vote queries ──────────────────────────────────────────────────────────

    public function getActiveVoteCount(string $serverName): int
    {
        return count($this->getActiveVotersForServer($serverName));
    }

    /**
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
     * @return list<array{name: string, activeVotes: int}>
     */
    public function getVoteRanking(): array
    {
        $ranking = [];
        foreach ($this->redis->getAllServerNames() as $name) {
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

    public function getHeartbeatTtl(string $serverName): int
    {
        $serverMeta = $this->serverMetaReader->read($this->mcDataPath . '/' . $serverName);
        return $serverMeta->heartbeatTtl ?? $this->globalMetaReader->read()->heartbeatTtl;
    }

    // ── Countdown trigger ─────────────────────────────────────────────────────

    /**
     * Evaluates whether a server should begin its start countdown.
     * Returns the server name to begin countdown for, or null.
     *
     * Rules:
     * - Server must be stopped with at least 1 active vote
     * - Must have strictly more active votes than any other stopped server
     * - No running server has players
     * - No cooldown active
     * - ResourceBudgetChecker passes
     *
     * Only one server can be in countdown at a time.
     */
    public function checkAndTrigger(): ?string
    {
        $leader      = null;
        $leaderVotes = 0;
        $secondVotes = 0;

        foreach ($this->redis->getAllServerNames() as $name) {
            $data = $this->redis->getServer($name);
            if ($data === null || ($data['running'] ?? false)) {
                continue;
            }

            $votes = $this->getActiveVoteCount($name);
            error_log("[checkAndTrigger] stopped server: $name, activeVotes: $votes");

            if ($votes > $leaderVotes) {
                $secondVotes = $leaderVotes;
                $leaderVotes = $votes;
                $leader      = $name;
            } elseif ($votes > $secondVotes) {
                $secondVotes = $votes;
            }
        }

        error_log("[checkAndTrigger] leader=$leader, leaderVotes=$leaderVotes, secondVotes=$secondVotes");

        if ($leader === null || $leaderVotes === 0 || $leaderVotes === $secondVotes) {
            return null;
        }

        foreach ($this->redis->getAllServerNames() as $name) {
            if ($this->redis->getPlayerCount($name) > 0) {
                error_log("[checkAndTrigger] blocked by players on $name");
                return null;
            }
        }

        if ($this->redis->hasCooldown($leader)) {
            error_log("[checkAndTrigger] blocked by cooldown on $leader");
            return null;
        }

        $data    = $this->redis->getServer($leader);
        $profile = $data['memoryProfile'] ?? 'medium';
        if (!$this->budgetChecker->canStart($profile)) {
            error_log("[checkAndTrigger] blocked by resource budget, profile=$profile");
            return null;
        }

        error_log("[checkAndTrigger] returning leader=$leader");
        return $leader;
    }

    /**
     * Called when the countdown timer fires. Validates conditions are still
     * met, then starts the server and sets cooldown.
     * Returns true if the server was started.
     */
    public function confirmStart(string $serverName): bool
    {
        // Re-validate — conditions may have changed during the 15s countdown
        $current = $this->checkAndTrigger();
        if ($current !== $serverName) {
            return false;
        }

        $data        = $this->redis->getServer($serverName);
        $containerId = $data['containerId'] ?? null;
        if ($containerId === null) {
            return false;
        }

        return true;
    }

    /**
     * Post-start cleanup — called by MinecraftMonitor after restartContainer succeeds.
     */
    public function onServerStarted(string $serverName): void
    {
        $this->redis->clearCountdown($serverName);
        $this->redis->setCooldown($serverName, RedisClient::COUNTDOWN_TTL * 4);
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
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

    private function resolveAvatarPath(string $gamertag): string
    {
        $username = strtolower($gamertag);
        $path     = '/mc-data/config/avatars/' . $username . '.png';

        return file_exists($path)
            ? '/avatars/' . $username . '.png'
            : '/images/avatar_default.png';
    }
}
