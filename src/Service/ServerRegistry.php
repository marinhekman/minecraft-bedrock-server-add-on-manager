<?php

namespace App\Service;

use App\Model\ServerInstance;

class ServerRegistry
{
    private const BASE_PATH = '/mc-data';

    /** @var ServerInstance[]|null */
    private ?array $instances = null;

    public function __construct(
        private readonly DockerClient $dockerClient,
    ) {}

    /** @return ServerInstance[] */
    public function getAll(): array
    {
        if ($this->instances === null) {
            $this->instances = $this->scan();
        }

        return $this->instances;
    }

    public function get(string $name): ?ServerInstance
    {
        foreach ($this->getAll() as $instance) {
            if ($instance->name === $name) {
                return $instance;
            }
        }

        return null;
    }

    /** @return ServerInstance[] */
    private function scan(): array
    {
        if (!is_dir(self::BASE_PATH)) {
            return [];
        }

        // Build a map of host path → container info from Docker API
        $containersByHostPath = $this->buildContainerMap();

        $instances = [];

        foreach (new \DirectoryIterator(self::BASE_PATH) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }

            $containerPath = $entry->getPathname(); // e.g. /mc-data/server1
            $hostPath      = $this->dockerClient->resolveHostPath($containerPath);
            $container     = $hostPath ? ($containersByHostPath[$hostPath] ?? null) : null;

            $instances[] = new ServerInstance(
                name:            $entry->getFilename(),
                dataPath:        $containerPath,
                containerId:     $container['Id'] ?? null,
                containerName:   isset($container['Names'][0])
                                     ? ltrim($container['Names'][0], '/')
                                     : null,
                containerStatus: $container['State'] ?? null,
                port:            $this->resolvePort($container),
            );
        }

        usort($instances, fn($a, $b) => strcmp($a->name, $b->name));

        return $instances;
    }

    /**
     * Returns a map of host data path => container info
     * for all running itzg/minecraft-bedrock-server containers.
     */
    private function buildContainerMap(): array
    {
        $map = [];

        foreach ($this->dockerClient->listMinecraftContainers() as $container) {
            $inspect = $this->dockerClient->inspectContainer($container['Id']);

            foreach ($inspect['Mounts'] ?? [] as $mount) {
                if ($mount['Destination'] === '/data') {
                    $map[$mount['Source']] = $container;
                    break;
                }
            }
        }

        return $map;
    }

    private function resolvePort(?array $container): ?int
    {
        if ($container === null) {
            return null;
        }

        foreach ($container['Ports'] ?? [] as $port) {
            if (($port['PrivatePort'] ?? null) === 19132) {
                return $port['PublicPort'] ?? null;
            }
        }

        return null;
    }
}
