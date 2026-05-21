<?php

namespace App\Controller;

use App\Service\RedisClient;
use App\Service\ServerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class ServerStatusController extends AbstractController
{
    public function __construct(
        private readonly ServerRegistry $serverRegistry,
        private readonly RedisClient    $redisClient,
    ) {}

    #[Route('/server/{serverName}/status', name: 'server_status', methods: ['GET'])]
    public function status(string $serverName): JsonResponse
    {
        $server = $this->serverRegistry->get($serverName);

        if ($server === null) {
            return $this->json(['error' => 'Server not found'], 404);
        }

        return $this->json([
            'running'     => $server->isRunning(),
            'startedAt'   => $server->startedAt,
            'loadedUuids' => $this->redisClient->getLoadedUuids($serverName),
            'playerCount' => $this->redisClient->getPlayerCount($serverName),
            'stats'       => $this->redisClient->getStats($serverName),
        ]);
    }
}
