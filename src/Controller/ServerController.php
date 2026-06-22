<?php

namespace App\Controller;

use App\Model\ServerInstance;
use App\Service\DockerClient;
use App\Service\ServerDefaultsPopulator;
use App\Service\ServerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/server/{serverName}')]
class ServerController extends AbstractController
{
    public function __construct(
        private readonly ServerRegistry           $serverRegistry,
        private readonly DockerClient             $dockerClient,
        private readonly ServerDefaultsPopulator  $defaultsPopulator,
    ) {}

    #[Route('/command', name: 'server_command', methods: ['POST'])]
    public function command(string $serverName, Request $request): RedirectResponse
    {
        $server = $this->serverRegistry->get($serverName);

        if ($server === null) {
            $this->addFlash('error', sprintf('Server "%s" not found.', $serverName));
            return $this->redirectToRoute('admin_dashboard');
        }

        if ($server->containerId === null) {
            $this->addFlash('error', sprintf('No running container found for server "%s".', $serverName));
            return $this->redirectToRoute('admin_dashboard');
        }

        $command = trim($request->request->get('command', ''));

        if ($command === '') {
            $this->addFlash('error', 'Command cannot be empty.');
            return $this->redirectToRoute('admin_dashboard');
        }

        try {
            $this->dockerClient->sendCommand($server->containerId, $command);
            $this->addFlash('success', sprintf('Command sent: <code>%s</code>', htmlspecialchars($command)));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', sprintf('Failed to send command: %s', $e->getMessage()));
        }

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/stop', name: 'server_stop', methods: ['POST'])]
    public function stop(string $serverName): RedirectResponse
    {
        $server = $this->serverRegistry->get($serverName);

        if ($server === null) {
            $this->addFlash('error', sprintf('Server "%s" not found.', $serverName));
            return $this->redirectToRoute('admin_dashboard');
        }

        if ($server->containerId === null) {
            $this->addFlash('error', sprintf('No container found for server "%s".', $serverName));
            return $this->redirectToRoute('admin_dashboard');
        }

        try {
            $this->dockerClient->stopContainer($server->containerId);
            $this->addFlash('success', sprintf('Server "%s" is stopping.', $server->containerName ?? $serverName));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', sprintf('Failed to stop server: %s', $e->getMessage()));
        }

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/restart', name: 'server_restart', methods: ['POST'])]
    public function restart(string $serverName): RedirectResponse
    {
        $server = $this->serverRegistry->get($serverName);

        if ($server === null) {
            $this->addFlash('error', sprintf('Server "%s" not found.', $serverName));
            return $this->redirectToRoute('admin_dashboard');
        }

        try {
            $containerId = $this->ensureContainerExists($serverName, $server);

            if ($server->isRunning()) {
                $this->dockerClient->restartContainer($containerId);
            } else {
                $this->dockerClient->startContainer($containerId);
            }

            $this->addFlash('success', sprintf('Server "%s" is starting.', $server->containerName ?? $serverName));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', sprintf('Failed to start server: %s', $e->getMessage()));
        }

        return $this->redirectToRoute('admin_dashboard');
    }

    private function ensureContainerExists(string $serverName, ServerInstance $server): string
    {
        if ($server->containerId !== null) {
            return $server->containerId;
        }

        $hostDataPath = $this->dockerClient->resolveHostPath($server->dataPath);
        if ($hostDataPath === null) {
            throw new \RuntimeException(sprintf('Could not resolve host path for "%s".', $server->dataPath));
        }

        if (!$this->dockerClient->imageExists('itzg/minecraft-bedrock-server')) {
            $this->dockerClient->pullImage('itzg/minecraft-bedrock-server');
        }

        $serverNum = null;
        if (preg_match('/^server(\d+)$/', $serverName, $m)) {
            $serverNum = (int) $m[1];
        }

        $containerName = $serverNum !== null
            ? sprintf('mc-server-%d', $serverNum)
            : 'mc-' . preg_replace('/[^a-z0-9-]+/i', '-', strtolower($serverName));

        if ($serverNum !== null) {
            $port = 19131 + $serverNum;
            if (!$this->isPortAvailable($port)) {
                throw new \RuntimeException(sprintf(
                    'Cannot start %s: expected port %d is already in use.',
                    $serverName,
                    $port
                ));
            }
        } else {
            $port = 19133;
            if (!$this->isPortAvailable($port)) {
                throw new \RuntimeException('Cannot start server: port 19133 is already in use.');
            }
        }

        $profile = in_array($server->memoryProfile, ['low', 'medium', 'high'], true)
            ? $server->memoryProfile
            : 'medium';

        $memoryBytes = match ($profile) {
            'low'    => 1073741824,
            'medium' => 2147483648,
            'high'   => 4294967296,
        };

        $config = [
            'Image' => 'itzg/minecraft-bedrock-server',
            'Env'   => [
                'EULA=TRUE',
                'MEMORY_PROFILE=' . $profile,
            ],
            'HostConfig' => [
                'NetworkMode'   => 'mc-net',
                'Binds'         => ["{$hostDataPath}:/data"],
                'PortBindings'  => [
                    '19132/udp' => [['HostPort' => (string) $port]],
                ],
                'RestartPolicy' => ['Name' => 'unless-stopped'],
                'Memory'        => $memoryBytes,
            ],
        ];

        $result = $this->dockerClient->createContainer($containerName, $config);
        $id     = $result['Id'] ?? null;

        if ($id === null) {
            throw new \RuntimeException('Failed to create missing container for existing server data folder.');
        }

        // Populate allowlist and permissions from users.yaml
        // Use container path /mc-data/serverN format
        $containerDataPath = "/mc-data/{$serverName}";
        try {
            $this->defaultsPopulator->populateDefaultsForNewServer($containerDataPath);
        } catch (\Exception) {
            // Log but don't fail container creation if defaults population fails
        }

        return $id;
    }

    private function isPortAvailable(int $port): bool
    {
        $usedPorts  = [];
        $containers = $this->dockerClient->listAllContainers();

        foreach ($containers as $container) {
            foreach ($container['Ports'] ?? [] as $portBinding) {
                if ($portBinding['PublicPort'] ?? null) {
                    $usedPorts[] = (int) $portBinding['PublicPort'];
                }
            }

            $containerId = $container['Id'] ?? null;
            if (!$containerId) {
                continue;
            }

            $inspect = $this->dockerClient->inspectContainer($containerId);
            foreach (($inspect['HostConfig']['PortBindings'] ?? []) as $bindings) {
                foreach ($bindings as $binding) {
                    if (isset($binding['HostPort']) && $binding['HostPort'] !== '') {
                        $usedPorts[] = (int) $binding['HostPort'];
                    }
                }
            }
        }

        $usedPorts = array_values(array_unique($usedPorts));
        return !in_array($port, $usedPorts, true);
    }

    #[Route('/image', name: 'server_image', methods: ['GET'])]
    public function image(string $serverName): Response
    {
        $server = $this->serverRegistry->get($serverName);
        if ($server === null) {
            throw $this->createNotFoundException();
        }

        $path = $server->dataPath . '/mc-server-manager/image.png';
        if (!file_exists($path)) {
            throw $this->createNotFoundException();
        }

        return new \Symfony\Component\HttpFoundation\BinaryFileResponse(
            $path,
            200,
            ['Content-Type' => 'image/png'],
        );
    }
}
