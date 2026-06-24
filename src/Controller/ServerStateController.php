<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ServerStateBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Provides a polling-based fallback for the live server-state updates
 * that are normally pushed by the WebSocket server.
 *
 * Used by the frontend when the WebSocket connection is unavailable.
 */
class ServerStateController extends AbstractController
{
    public function __construct(
        private readonly ServerStateBuilder $serverStateBuilder,
    ) {}

    #[Route('/api/server-states', name: 'api_server_states', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json([
            'servers' => $this->serverStateBuilder->buildAll(),
        ], 200, [
            'Cache-Control' => 'no-store',
        ]);
    }
}

