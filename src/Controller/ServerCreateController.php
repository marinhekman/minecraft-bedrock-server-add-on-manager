<?php

namespace App\Controller;

use App\Service\DiskSpaceChecker;
use App\Service\DockerClient;
use App\Service\ServerDefaultsPopulator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Yaml\Yaml;

#[Route('/admin/server')]
class ServerCreateController extends AbstractController
{
    public function __construct(
        private readonly DockerClient             $dockerClient,
        private readonly DiskSpaceChecker         $diskSpaceChecker,
        private readonly ServerDefaultsPopulator  $defaultsPopulator,
        private readonly TranslatorInterface      $translator,
    ) {}

    #[Route('/create', name: 'server_create', methods: ['POST'])]
    public function create(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // Check disk space first
        if (!$this->diskSpaceChecker->canCreateServer()) {
            $this->addFlash('error', $this->translator->trans('Cannot create server: not enough free disk space.'));
            return $this->redirectToRoute('admin_dashboard');
        }

        $displayName = trim($request->request->get('display_name', ''));
        $seed        = trim($request->request->get('seed', ''));
        $profile     = $request->request->get('memory_profile', 'medium');

        if ($displayName === '') {
            $this->addFlash('error', $this->translator->trans('Display name is required.'));
            return $this->redirectToRoute('admin_dashboard');
        }

        if (!in_array($profile, ['low', 'medium', 'high'], true)) {
            $this->addFlash('error', $this->translator->trans('Invalid memory profile.'));
            return $this->redirectToRoute('admin_dashboard');
        }

        try {
            // Resolve host base path and next server number
            $hostBasePath = $this->resolveHostBasePath();
            $serverNum    = $this->getNextServerNumber();
            $serverName   = "server{$serverNum}";
            $hostDataPath = "{$hostBasePath}/{$serverName}";
            $containerDataPath = "/mc-data/{$serverName}";
            $containerName = "mc-server-{$serverNum}";

            // Deterministic mapping: server1->19132, server2->19133, ...
            $port = 19131 + $serverNum;
            if (!$this->isPortAvailable($port)) {
                throw new \RuntimeException($this->translator->trans(
                    'Port %port% is already in use. Free that port or choose a different server number.',
                    ['%port%' => $port],
                ));
            }

            // Ensure the Minecraft server image is available locally
            if (!$this->dockerClient->imageExists('itzg/minecraft-bedrock-server')) {
                $this->dockerClient->pullImage('itzg/minecraft-bedrock-server');
            }

            // Build Docker create payload
            $env = [
                'EULA=TRUE',
                'MEMORY_PROFILE=' . $profile,
            ];
            if ($seed !== '') {
                $env[] = 'LEVEL_SEED=' . $seed;
            }

            $memoryBytes = match ($profile) {
                'low'    => 1073741824,      // 1GB
                'medium' => 2147483648,      // 2GB
                'high'   => 4294967296,      // 4GB
            };

            $config = [
                'Image' => 'itzg/minecraft-bedrock-server',
                'Env'   => $env,
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

            // Create container
            $createResult = $this->dockerClient->createContainer($containerName, $config);
            $containerId  = $createResult['Id'] ?? null;

            if (!$containerId) {
                throw new \RuntimeException($this->translator->trans('Failed to create container: no ID returned.'));
            }

            // Write metadata
            $this->writeServerMetadata($containerDataPath, $displayName);

            // Populate allowlist and permissions from users.yaml (use container path)
            try {
                $this->defaultsPopulator->populateDefaultsForNewServer($containerDataPath);
            } catch (\Exception $e) {
                // Log but don't fail server creation if defaults population fails
            }

            $this->addFlash('success', $this->translator->trans(
                'Server "%name%" created on port %port% (not started). Start it manually from the dashboard or via voting.',
                ['%name%' => $displayName, '%port%' => $port],
            ));

        } catch (\Exception $e) {
            $this->addFlash('error', $this->translator->trans('Failed to create server: %message%', ['%message%' => $e->getMessage()]));
        }

        return $this->redirectToRoute('admin_dashboard');
    }

    private function resolveHostBasePath(): string
    {
        // Preferred: single root mount host:/mc-data
        $mcDataHostPath = $this->dockerClient->resolveHostPath('/mc-data');
        if ($mcDataHostPath !== null) {
            return rtrim($mcDataHostPath, '/');
        }

        // Backward-compatible fallback for older per-server mounts
        $server1HostPath = $this->dockerClient->resolveHostPath('/mc-data/server1');
        if ($server1HostPath !== null) {
            return dirname($server1HostPath);
        }

        throw new \RuntimeException(
            $this->translator->trans('Could not resolve host path for /mc-data. Ensure /mc-data is mounted in the manager container.')
        );
    }

    private function getNextServerNumber(): int
    {
        $max = 0;

        if (!is_dir('/mc-data')) {
            return 1;
        }

        foreach (new \DirectoryIterator('/mc-data') as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }

            $name = $entry->getFilename();

            // Preferred naming: server1, server2, ...
            if (preg_match('/^server(\d+)$/', $name, $m)) {
                $max = max($max, (int) $m[1]);
                continue;
            }

            // Backward-compatible migration support: minecraft-data, minecraft-data2, ...
            if ($name === 'minecraft-data') {
                $max = max($max, 1);
                continue;
            }
            if (preg_match('/^minecraft-data(\d+)$/', $name, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max + 1;
    }

    private function isPortAvailable(int $port): bool
    {
        $usedPorts = [];
        $containers = $this->dockerClient->listAllContainers();

        foreach ($containers as $container) {
            // Fast path: ports reported in /containers/json
            foreach ($container['Ports'] ?? [] as $portBinding) {
                if ($portBinding['PublicPort'] ?? null) {
                    $usedPorts[] = $portBinding['PublicPort'];
                }
            }

            // Robust path: inspect HostConfig.PortBindings so stopped/not-started
            // containers with reserved host ports are also accounted for.
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

    private function writeServerMetadata(string $hostDataPath, string $displayName): void
    {
        $mcManagerDir = "{$hostDataPath}/mc-server-manager";

        if (!is_dir($mcManagerDir)) {
            @mkdir($mcManagerDir, 0755, true);
        }

        $metaFile = "{$mcManagerDir}/meta.yaml";

        $data = [];
        if (file_exists($metaFile)) {
            try {
                $data = Yaml::parseFile($metaFile) ?? [];
            } catch (\Exception) {
                $data = [];
            }
        }

        $data['display_name'] = $displayName;


        $written = @file_put_contents($metaFile, Yaml::dump($data));
        if ($written === false) {
            throw new \RuntimeException("Failed to write meta.yaml at {$metaFile}");
        }
    }
}

