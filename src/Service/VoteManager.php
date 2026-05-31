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
     * If resources are blocked, also evaluates which running empty servers
     * should be auto-stopped. Returns those via getServersToAutoStop().
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

            if ($votes > $leaderVotes) {
                $secondVotes = $leaderVotes;
                $leaderVotes = $votes;
                $leader      = $name;
            } elseif ($votes > $secondVotes) {
                $secondVotes = $votes;
            }
        }

        if ($leader === null || $leaderVotes === 0 || $leaderVotes === $secondVotes) {
            return null;
        }

        foreach ($this->redis->getAllServerNames() as $name) {
            if ($this->redis->getPlayerCount($name) > 0) {
                return null;
            }
        }

        if ($this->redis->hasCooldown($leader)) {
            return null;
        }

        $data    = $this->redis->getServer($leader);
        $profile = $data['memoryProfile'] ?? 'medium';

        if ($this->budgetChecker->canStart($profile)) {
            return $leader;
        }

        // Resources blocked — auto-stop logic will handle it via getServersToAutoStop()
        return null;
    }

    /**
     * Returns servers that should be auto-stopped to free resources for the
     * vote leader. Returns empty array if auto-stop is not possible or needed.
     *
     * @return list<string> Server names to stop, sorted highest profile first
     */
    public function getServersToAutoStop(): array
    {
        // Find the vote leader
        $leader      = null;
        $leaderVotes = 0;
        $secondVotes = 0;

        foreach ($this->redis->getAllServerNames() as $name) {
            $data = $this->redis->getServer($name);
            if ($data === null || ($data['running'] ?? false)) {
                continue;
            }

            $votes = $this->getActiveVoteCount($name);
            if ($votes > $leaderVotes) {
                $secondVotes = $leaderVotes;
                $leaderVotes = $votes;
                $leader      = $name;
            } elseif ($votes > $secondVotes) {
                $secondVotes = $votes;
            }
        }

        if ($leader === null || $leaderVotes === 0 || $leaderVotes === $secondVotes) {
            return [];
        }

        // Already startable — no auto-stop needed
        $leaderData    = $this->redis->getServer($leader);
        $leaderProfile = $leaderData['memoryProfile'] ?? 'medium';
        if ($this->budgetChecker->canStart($leaderProfile)) {
            return [];
        }

        // Find running servers with 0 players, sorted highest profile first
        $candidates = [];
        foreach ($this->redis->getAllServerNames() as $name) {
            if ($name === $leader) {
                continue;
            }
            $data = $this->redis->getServer($name);
            if ($data === null || !($data['running'] ?? false)) {
                continue;
            }
            if ($this->redis->getPlayerCount($name) > 0) {
                continue;
            }
            $candidates[] = [
                'name'    => $name,
                'profile' => $data['memoryProfile'] ?? 'medium',
            ];
        }

        // Sort highest profile first (most resources freed)
        $hierarchy = ['low' => 0, 'medium' => 1, 'high' => 2];
        usort($candidates, function (array $a, array $b) use ($hierarchy): int {
            return ($hierarchy[$b['profile']] ?? 1) <=> ($hierarchy[$a['profile']] ?? 1);
        });

        // Simulate stopping candidates until canStart passes
        $runningProfiles = $this->budgetChecker->getRunningProfiles();
        $toStop          = [];

        foreach ($candidates as $candidate) {
            $toStop[]        = $candidate['name'];
            $runningProfiles = array_values(array_filter(
                $runningProfiles,
                fn($p) => $p !== $candidate['profile'],
            ));

            // Re-check with simulated running profiles
            if ($this->budgetChecker->canStartWithProfiles($leaderProfile, $runningProfiles)) {
                return $toStop;
            }
        }

        // Cannot free enough resources
        return [];
    }

    /**
     * Called when the start countdown timer fires.
     */
    public function confirmStart(string $serverName): bool
    {
        $current = $this->checkAndTrigger();
        return $current === $serverName;
    }

    /**
     * Post-start cleanup.
     */
    public function onServerStarted(string $serverName): void
    {
        $this->redis->clearCountdown($serverName);
        $this->redis->setCooldown($serverName, RedisClient::COUNTDOWN_TTL * 4);
    }

    /**
     * Post-stop cleanup for auto-stopped servers.
     */
    public function onServerAutoStopped(string $serverName): void
    {
        $this->redis->clearStopCountdown($serverName);
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
        $username = $this->redis->getUsernameByGamertag($gamertag)
            ?? strtolower(str_replace(' ', '', $gamertag));

        $path = '/mc-data/config/avatars/' . $username . '.png';

        return file_exists($path)
            ? '/avatars/' . $username . '.png'
            : '/images/avatar_default.png';
    }
}
