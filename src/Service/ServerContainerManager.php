<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ServerContainerManager
{
    public function __construct(
        private readonly DockerClient $dockerClient,
        private readonly ServerDefaultsPopulator $defaultsPopulator,
        #[Autowire(service: 'monolog.logger.server')]
        private readonly LoggerInterface $logger,
    ) {}

    public function ensureContainerExists(string $serverName, string $dataPath, string $memoryProfile = 'medium'): string
    {
        $hostDataPath = $this->dockerClient->resolveHostPath($dataPath);
        if ($hostDataPath === null) {
            throw new \RuntimeException(sprintf('Could not resolve host path for "%s".', $dataPath));
        }

        $serverNum = null;
        if (preg_match('/^server(\d+)$/', $serverName, $m)) {
            $serverNum = (int) $m[1];
        }

        $containerName = $serverNum !== null
            ? sprintf('mc-server-%d', $serverNum)
            : 'mc-' . preg_replace('/[^a-z0-9-]+/i', '-', strtolower($serverName));

        $existing = $this->findContainerByName($containerName);
        if ($existing !== null) {
            $this->logger->info('Reusing existing container for server', [
                'server' => $serverName,
                'containerName' => $containerName,
                'containerId' => $existing['Id'] ?? null,
            ]);

            return $existing['Id'];
        }

        if (!$this->dockerClient->imageExists('itzg/minecraft-bedrock-server')) {
            $this->logger->info('Minecraft image missing, pulling before container creation', ['server' => $serverName]);
            $this->dockerClient->pullImage('itzg/minecraft-bedrock-server');
        }

        $port = $serverNum !== null ? 19131 + $serverNum : 19133;
        if (!$this->isPortAvailable($port)) {
            throw new \RuntimeException(sprintf('Cannot start %s: expected port %d is already in use.', $serverName, $port));
        }

        $profile = in_array($memoryProfile, ['low', 'medium', 'high'], true)
            ? $memoryProfile
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

        try {
            $this->defaultsPopulator->populateDefaultsForNewServer('/mc-data/' . $serverName);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to populate server defaults after creating missing container', [
                'server' => $serverName,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger->info('Created missing container for server', [
            'server' => $serverName,
            'containerName' => $containerName,
            'containerId' => $id,
            'profile' => $profile,
            'port' => $port,
        ]);

        return $id;
    }

    private function findContainerByName(string $containerName): ?array
    {
        foreach ($this->dockerClient->listAllContainers() as $container) {
            foreach ($container['Names'] ?? [] as $name) {
                if (ltrim((string) $name, '/') === $containerName) {
                    return $container;
                }
            }
        }

        return null;
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

        return !in_array($port, array_values(array_unique($usedPorts)), true);
    }
}

