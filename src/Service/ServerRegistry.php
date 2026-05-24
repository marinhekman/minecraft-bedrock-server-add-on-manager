<?php

namespace App\Service;

use App\Model\ServerInstance;

/**
 * Reads server state from Redis (populated by the WebSocket/Monitor process).
 */
class ServerRegistry
{
    public function __construct(
        private readonly RedisClient $redisClient,
    ) {}

    /** @return ServerInstance[] */
    public function getAll(): array
    {
        $instances = [];

        foreach ($this->redisClient->getAllServerNames() as $name) {
            $instance = $this->buildInstance($name);
            if ($instance !== null) {
                $instances[] = $instance;
            }
        }

        usort($instances, fn($a, $b) => strcmp($a->name, $b->name));

        return $instances;
    }

    public function get(string $name): ?ServerInstance
    {
        return $this->buildInstance($name);
    }

    private function buildInstance(string $name): ?ServerInstance
    {
        $data = $this->redisClient->getServer($name);
        if ($data === null) {
            return null;
        }

        return new ServerInstance(
            name:            $data['name'] ?? $name,
            dataPath:        '/mc-data/' . $name,
            containerId:     $data['containerId'] ?? null,
            containerName:   $data['containerName'] ?? null,
            containerStatus: $data['containerStatus'] ?? null,
            port:            $data['port'] ?? null,
            startedAt:       $data['startedAt'] ?? null,
            memoryProfile:   $data['memoryProfile'] ?? 'medium',
        );
    }
}
