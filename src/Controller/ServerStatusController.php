<?php

namespace App\Controller;

use App\Service\AddonScanner;
use App\Service\DockerClient;
use App\Service\PackLoadChecker;
use App\Service\ServerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class ServerStatusController extends AbstractController
{
    public function __construct(
        private readonly ServerRegistry  $serverRegistry,
        private readonly AddonScanner    $addonScanner,
        private readonly PackLoadChecker $packLoadChecker,
        private readonly DockerClient    $dockerClient,
    ) {}

    #[Route('/server/{serverName}/status', name: 'server_status', methods: ['GET'])]
    public function status(string $serverName): JsonResponse
    {
        $server = $this->serverRegistry->get($serverName);

        if ($server === null) {
            return $this->json(['error' => 'Server not found'], 404);
        }

        $packs = $this->addonScanner->scan($server);

        $stats = null;
        if ($server->containerId !== null && $server->isRunning()) {
            try {
                $stats = $this->dockerClient->getContainerStats($server->containerId);
            } catch (\RuntimeException) {
                // Stats unavailable — not critical
            }
        }

        return $this->json([
            'running'     => $server->isRunning(),
            'startedAt'   => $server->startedAt,
            'loadedUuids' => $this->packLoadChecker->getLoadedUuids($server, $packs),
            'stats'       => $stats,
        ]);
    }
}
