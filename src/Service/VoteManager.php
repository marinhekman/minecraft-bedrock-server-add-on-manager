<?php

declare(strict_types=1);

namespace App\Service;

use App\Security\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class VoteManager
{
    public function __construct(
        private readonly RedisClient           $redis,
        private readonly GlobalMetaReader      $globalMetaReader,
        private readonly ServerMetaReader      $serverMetaReader,
        private readonly ResourceBudgetChecker $budgetChecker,
        private readonly string                $mcDataPath,
        #[Autowire(service: 'monolog.logger.vote')]
        private readonly LoggerInterface       $logger,
    ) {}

    // ── Casting votes ─────────────────────────────────────────────────────────

    public function castVote(User $user, string $serverName): void
    {
        $gamertag    = $user->getGamertag();
        $currentVote = $this->redis->getVotes()[$gamertag] ?? null;

        if ($currentVote === $serverName) {
            $this->redis->removeVote($gamertag);
            $this->logger->info('Vote retracted', ['gamertag' => $gamertag, 'server' => $serverName]);
        } else {
            $this->redis->setVote($gamertag, $serverName);
            $this->logger->info('Vote cast', [
                'gamertag'     => $gamertag,
                'server'       => $serverName,
                'previous_vote' => $currentVote,
            ]);
        }
    }

    public function retractVote(User $user): void
    {
        $this->redis->removeVote($user->getGamertag());
        $this->logger->info('Vote force-retracted', ['gamertag' => $user->getGamertag()]);
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

        if ($this->redis->hasCooldown($leader)) {
            $this->logger->debug('Countdown blocked by cooldown', ['server' => $leader]);
            return null;
        }

        $data    = $this->redis->getServer($leader);
        $profile = $data['memoryProfile'] ?? 'medium';

        $decision = $this->budgetChecker->explainCanStart($profile);
        $this->logger->debug('Resource decision path evaluated', [
            'server' => $leader,
            'votes' => $leaderVotes,
            'candidateProfile' => $profile,
            'runningProfiles' => $decision['runningProfiles'],
            'resourceLimitsConfigured' => $decision['resourceLimitsConfigured'],
            'matchedSlotSetIndex' => $decision['matchedSlotSetIndex'],
            'slotSetEvaluations' => $decision['slotSetEvaluations'],
        ]);

        // If resources allow starting without stopping anything — go ahead
        // regardless of players on other running servers.
        if ($decision['allowed']) {
            $this->logger->info('Countdown trigger: server qualifies for start', [
                'server' => $leader,
                'votes'  => $leaderVotes,
                'profile' => $profile,
                'runningProfiles' => $decision['runningProfiles'],
                'matchedSlotSetIndex' => $decision['matchedSlotSetIndex'],
            ]);
            return $leader;
        }

        // Resources blocked — only proceed if all running servers are empty
        // (auto-stop can free the needed slot).
        foreach ($this->redis->getAllServerNames() as $name) {
            if ($this->redis->getPlayerCount($name) > 0) {
                $this->logger->debug('Countdown blocked: resources occupied and players present', [
                    'leader'  => $leader,
                    'blocker' => $name,
                    'candidateProfile' => $profile,
                    'runningProfiles' => $decision['runningProfiles'],
                    'slotSetEvaluations' => $decision['slotSetEvaluations'],
                ]);
                return null;
            }
        }

        $this->logger->debug('Countdown blocked: resources do not allow start and no players-based unblock path selected', [
            'server' => $leader,
            'candidateProfile' => $profile,
            'runningProfiles' => $decision['runningProfiles'],
            'slotSetEvaluations' => $decision['slotSetEvaluations'],
        ]);

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
        $ok      = $current === $serverName;
        if (!$ok) {
            $this->logger->warning('Countdown confirmation failed', [
                'expected' => $serverName,
                'current'  => $current,
            ]);
        }
        return $ok;
    }

    /**
     * Post-start cleanup.
     */
    public function onServerStarted(string $serverName): void
    {
        $this->redis->clearCountdown($serverName);
        $this->redis->setCooldown($serverName, RedisClient::COUNTDOWN_TTL * 4);
        $this->logger->info('Server auto-started, cooldown set', ['server' => $serverName]);
    }

    /**
     * Post-stop cleanup for auto-stopped servers.
     */
    public function onServerAutoStopped(string $serverName): void
    {
        $this->redis->clearStopCountdown($serverName);
        $this->logger->info('Server auto-stopped', ['server' => $serverName]);
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

    /**
     * Returns the blocking reason for a stopped server that has votes but cannot start.
     * Used by both HomeController (page render) and WebSocketServer (WebSocket payload)
     * to ensure consistent messaging.
     */
    public function getBlockingReason(string $serverName): ?string
    {
        $data = $this->redis->getServer($serverName);
        if ($data === null || ($data['running'] ?? false)) {
            return null;
        }

        if ($this->getActiveVoteCount($serverName) === 0) {
            return null;
        }

        $profile = $data['memoryProfile'] ?? 'medium';

        if ($this->budgetChecker->canStart($profile)) {
            return null;
        }

        // Check if a stop countdown is already active on any server
        $anyStopCountdownActive = false;
        foreach ($this->redis->getAllServerNames() as $otherName) {
            if ($this->redis->getStopCountdown($otherName) !== null) {
                $anyStopCountdownActive = true;
                break;
            }
        }

        if ($anyStopCountdownActive) {
            // If another running server still has players, show players_leaving
            // (stop is in progress but we're still waiting for resources to free up)
            foreach ($this->redis->getAllServerNames() as $otherName) {
                $other = $this->redis->getServer($otherName);
                if (($other['running'] ?? false) && $this->redis->getPlayerCount($otherName) > 0) {
                    return 'players_leaving';
                }
            }
            return 'resources_stopping';
        }

        // Check if any running server with 0 players can still be stopped
        // (exclude servers already being stopped via stop countdown)
        foreach ($this->redis->getAllServerNames() as $otherName) {
            $other = $this->redis->getServer($otherName);
            if (!($other['running'] ?? false)) {
                continue;
            }
            if ($this->redis->getStopCountdown($otherName) !== null) {
                continue; // already being stopped — not a new candidate
            }
            if ($this->redis->getPlayerCount($otherName) === 0) {
                return null; // system will handle it, no message needed
            }
        }

        // No stoppable empty servers remain — check if players are the reason
        foreach ($this->redis->getAllServerNames() as $otherName) {
            $other = $this->redis->getServer($otherName);
            if (($other['running'] ?? false) && $this->redis->getPlayerCount($otherName) > 0) {
                return 'players';
            }
        }

        return 'resources';
    }
}
