<?php

namespace App\Controller;

use App\Model\ServerInstance;
use App\Service\DockerClient;
use App\Service\ServerDefaultsPopulator;
use App\Service\RedisClient;
use App\Service\ServerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/server/{serverName}')]
class ServerController extends AbstractController
{
    public function __construct(
        private readonly ServerRegistry           $serverRegistry,
        private readonly DockerClient             $dockerClient,
        private readonly ServerDefaultsPopulator  $defaultsPopulator,
        private readonly RedisClient              $redisClient,
        private readonly TranslatorInterface      $translator,
    ) {}

    #[Route('/command', name: 'server_command', methods: ['POST'])]
    public function command(string $serverName, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $server = $this->serverRegistry->get($serverName);

        if ($server === null) {
            $this->addFlash('error', $this->translator->trans('Server "%name%" not found.', ['%name%' => $serverName]));
            return $this->redirectToRoute('admin_dashboard');
        }

        if ($server->containerId === null) {
            $this->addFlash('error', $this->translator->trans('No running container found for server "%name%".', ['%name%' => $serverName]));
            return $this->redirectToRoute('admin_dashboard');
        }

        $command = trim($request->request->get('command', ''));

        if ($command === '') {
            $this->addFlash('error', $this->translator->trans('Command cannot be empty.'));
            return $this->redirectToRoute('admin_dashboard');
        }

        try {
            $this->dockerClient->sendCommand($server->containerId, $command);
            $this->addFlash('success', $this->translator->trans('Command sent: <code>%command%</code>', ['%command%' => htmlspecialchars($command)]));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $this->translator->trans('Failed to send command: %message%', ['%message%' => $e->getMessage()]));
        }

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/stop', name: 'server_stop', methods: ['POST'])]
    public function stop(string $serverName): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $server = $this->serverRegistry->get($serverName);

        if ($server === null) {
            $this->addFlash('error', $this->translator->trans('Server "%name%" not found.', ['%name%' => $serverName]));
            return $this->redirectToRoute('admin_dashboard');
        }

        if ($server->containerId === null) {
            $this->addFlash('error', $this->translator->trans('No container found for server "%name%".', ['%name%' => $serverName]));
            return $this->redirectToRoute('admin_dashboard');
        }

        try {
            $this->dockerClient->stopContainer($server->containerId);
            $this->addFlash('success', $this->translator->trans('Server "%name%" is stopping.', ['%name%' => $server->containerName ?? $serverName]));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $this->translator->trans('Failed to stop server: %message%', ['%message%' => $e->getMessage()]));
        }

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/restart', name: 'server_restart', methods: ['POST'])]
    public function restart(string $serverName): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $server = $this->serverRegistry->get($serverName);

        if ($server === null) {
            $this->addFlash('error', $this->translator->trans('Server "%name%" not found.', ['%name%' => $serverName]));
            return $this->redirectToRoute('admin_dashboard');
        }

        try {
            $containerId = $this->ensureContainerExists($serverName, $server);

            if ($server->isRunning()) {
                $this->dockerClient->restartContainer($containerId);
            } else {
                $this->dockerClient->startContainer($containerId);
            }

            $this->addFlash('success', $this->translator->trans('Server "%name%" is starting.', ['%name%' => $server->containerName ?? $serverName]));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $this->translator->trans('Failed to start server: %message%', ['%message%' => $e->getMessage()]));
        }

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/delete', name: 'server_delete', methods: ['POST'])]
    public function delete(string $serverName): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $server = $this->serverRegistry->get($serverName);
        if ($server === null) {
            $this->addFlash('error', $this->translator->trans('Server "%name%" not found.', ['%name%' => $serverName]));
            return $this->redirectToRoute('admin_dashboard');
        }

        try {
            if ($server->containerId !== null) {
                if ($server->isRunning()) {
                    try {
                        $this->dockerClient->stopContainer($server->containerId);
                    } catch (\RuntimeException) {
                        // Fall through to forced removal below.
                    }
                }

                $this->dockerClient->removeContainer($server->containerId, true);
            }

            $this->removeDirectory($server->dataPath);
            $this->redisClient->removeServerState($serverName);

            $this->addFlash('success', $this->translator->trans('Server "%name%" has been deleted.', ['%name%' => $serverName]));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $this->translator->trans('Failed to delete server: %message%', ['%message%' => $e->getMessage()]));
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
            throw new \RuntimeException($this->translator->trans('Could not resolve host path for "%path%".', ['%path%' => $server->dataPath]));
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
                throw new \RuntimeException($this->translator->trans(
                    'Cannot start %name%: expected port %port% is already in use.',
                    ['%name%' => $serverName, '%port%' => $port],
                ));
            }
        } else {
            $port = 19133;
            if (!$this->isPortAvailable($port)) {
                throw new \RuntimeException($this->translator->trans('Cannot start server: port 19133 is already in use.'));
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
            throw new \RuntimeException($this->translator->trans('Failed to create missing container for existing server data folder.'));
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

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // Mounted folders can still receive writes right after container stop/remove;
        // retry a few times to avoid transient "Directory not empty" failures.
        $lastError = null;
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->removeDirectoryOnce($dir);

            clearstatcache(true, $dir);
            if (!is_dir($dir)) {
                return;
            }

            $lastError = sprintf('Directory "%s" not empty after delete attempt %d.', $dir, $attempt);
            usleep(300000);
        }

        throw new \RuntimeException($lastError ?? sprintf('Failed to remove directory "%s".', $dir));
    }

    private function removeDirectoryOnce(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
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
