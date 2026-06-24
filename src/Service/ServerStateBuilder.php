<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Builds the live server-state payload that is shared between:
 *   - WebSocketServer  (broadcast on every change)
 *   - ServerStateController  (HTTP poll fallback)
 *
 * Shape mirrors the WebSocket server_update / init payload.
 */
final class ServerStateBuilder
{
    public function __construct(
        private readonly RedisClient           $redis,
        private readonly VoteManager           $voteManager,
        private readonly ResourceBudgetChecker $budgetChecker,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function buildAll(): array
    {
        $state = [];
        foreach ($this->redis->getAllServerNames() as $name) {
            $state[$name] = $this->buildOne($name);
        }
        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildOne(string $name): array
    {
        $server    = $this->redis->getServer($name);
        $countdown = $this->redis->getCountdown($name);

        return [
            'server'             => $server,
            'playerCount'        => $this->redis->getPlayerCount($name),
            'loadedUuids'        => $this->redis->getLoadedUuids($name),
            'stats'              => $this->redis->getStats($name),
            'memoryProfile'      => $server['memoryProfile'] ?? 'medium',
            'votes'              => [
                'count'  => $this->voteManager->getActiveVoteCount($name),
                'voters' => $this->voteManager->getActiveVoters($name),
            ],
            'stopCountdownUntil' => $this->resolveStopCountdownUntil($name, $server),
            'countdownUntil'     => $countdown !== null
                ? $countdown + RedisClient::COUNTDOWN_TTL
                : null,
            'blocked'            => $this->voteManager->getBlockingReason($name),
            'starting'           => $this->redis->isStarting($name),
        ];
    }

    private function resolveStopCountdownUntil(string $name, ?array $server): ?int
    {
        if (!($server['running'] ?? false)) {
            return null;
        }
        $stopStartedAt = $this->redis->getStopCountdown($name);
        return $stopStartedAt !== null ? $stopStartedAt + RedisClient::COUNTDOWN_TTL : null;
    }
}

