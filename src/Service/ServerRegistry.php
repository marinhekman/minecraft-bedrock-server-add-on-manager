<?php

namespace App\Service;

use App\Model\ServerInstance;

class ServerRegistry
{
    private const BASE_PATH = '/mc-data';

    public function __construct(
        private readonly DockerClient $dockerClient,
    ) {}

    /** @return ServerInstance[] */
    public function getAll(): array
    {
        return $this->scan();
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

        $containersByHostPath = $this->buildContainerMap();

        $instances = [];

        foreach (new \DirectoryIterator(self::BASE_PATH) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }

            $containerPath = $entry->getPathname();
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
                startedAt:       $this->resolveStartedAt($container),
            );
        }

        usort($instances, fn($a, $b) => strcmp($a->name, $b->name));

        return $instances;
    }

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

    private function resolveStartedAt(?array $container): ?int
    {
        if ($container === null) {
            return null;
        }

        $inspect   = $this->dockerClient->inspectContainer($container['Id']);
        $startedAt = $inspect['State']['StartedAt'] ?? null;

        if ($startedAt === null) {
            return null;
        }

        return (new \DateTimeImmutable($startedAt))->getTimestamp();
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
