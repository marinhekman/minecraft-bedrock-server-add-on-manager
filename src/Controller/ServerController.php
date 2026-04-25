<?php

namespace App\Controller;

use App\Service\DockerClient;
use App\Service\ServerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/server/{serverName}')]
class ServerController extends AbstractController
{
    public function __construct(
        private readonly ServerRegistry $serverRegistry,
        private readonly DockerClient   $dockerClient,
    ) {}

    #[Route('/command', name: 'server_command', methods: ['POST'])]
    public function command(string $serverName, Request $request): RedirectResponse
    {
        $server = $this->serverRegistry->get($serverName);

        if ($server === null) {
            $this->addFlash('error', sprintf('Server "%s" not found.', $serverName));
            return $this->redirectToRoute('dashboard');
        }

        if ($server->containerId === null) {
            $this->addFlash('error', sprintf('No running container found for server "%s".', $serverName));
            return $this->redirectToRoute('dashboard');
        }

        $command = trim($request->request->get('command', ''));

        if ($command === '') {
            $this->addFlash('error', 'Command cannot be empty.');
            return $this->redirectToRoute('dashboard');
        }

        try {
            $this->dockerClient->sendCommand($server->containerId, $command);
            $this->addFlash('success', sprintf('Command sent: <code>%s</code>', htmlspecialchars($command)));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', sprintf('Failed to send command: %s', $e->getMessage()));
        }

        return $this->redirectToRoute('dashboard');
    }

    #[Route('/restart', name: 'server_restart', methods: ['POST'])]
    public function restart(string $serverName): RedirectResponse
    {
        $server = $this->serverRegistry->get($serverName);

        if ($server === null) {
            $this->addFlash('error', sprintf('Server "%s" not found.', $serverName));
            return $this->redirectToRoute('dashboard');
        }

        if ($server->containerId === null) {
            $this->addFlash('error', sprintf('No running container found for server "%s".', $serverName));
            return $this->redirectToRoute('dashboard');
        }

        try {
            $this->dockerClient->restartContainer($server->containerId);
            $this->addFlash('success', sprintf('Server "%s" is restarting.', $server->containerName ?? $serverName));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', sprintf('Failed to restart server: %s', $e->getMessage()));
        }

        return $this->redirectToRoute('dashboard');
    }
}
