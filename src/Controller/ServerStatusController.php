<?php

namespace App\Controller;

use App\Service\RedisClient;
use App\Service\ServerRegistry;
use App\Service\VoteManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class ServerStatusController extends AbstractController
{
    public function __construct(
        private readonly ServerRegistry $serverRegistry,
        private readonly RedisClient    $redisClient,
        private readonly VoteManager    $voteManager,
    ) {}

    #[Route('/server/{serverName}/status', name: 'server_status', methods: ['GET'])]
    public function status(string $serverName): JsonResponse
    {
        $server = $this->serverRegistry->get($serverName);

        if ($server === null) {
            return $this->json(['error' => 'Server not found'], 404);
        }

        $myGamertag = null;
        if ($this->getUser()) {
            /** @var \App\Security\User $user */
            $user       = $this->getUser();
            $myGamertag = $user->getGamertag();
        }

        $voters = $this->voteManager->getActiveVoters($serverName);

        return $this->json([
            'running'       => $server->isRunning(),
            'startedAt'     => $server->startedAt,
            'loadedUuids'   => $this->redisClient->getLoadedUuids($serverName),
            'playerCount'   => $this->redisClient->getPlayerCount($serverName),
            'stats'         => $this->redisClient->getStats($serverName),
            'memoryProfile' => $server->memoryProfile,
            'graceUntil'    => $this->voteManager->getGraceUntil($serverName),
            'votes'         => [
                'count'          => $this->voteManager->getActiveVoteCount($serverName),
                'threshold'      => $this->voteManager->getThreshold($serverName),
                'voters'         => $voters,
                'userHasVoted'   => $myGamertag !== null && in_array(
                    $myGamertag,
                    array_column($voters, 'gamertag'),
                    true,
                ),
                'cooldown'       => $this->voteManager->getCooldown($serverName),
                'blockingReason' => $this->voteManager->getBlockingReason($serverName),
                'blockingDetail' => $this->voteManager->getBlockingDetail($serverName),
            ],
        ]);
    }
}
