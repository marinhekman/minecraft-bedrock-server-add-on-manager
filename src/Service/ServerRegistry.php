<?php

namespace App\Service;

use App\Model\ServerInstance;

/**
 * Reads server state from Redis (populated by the WebSocket/Monitor process).
 * Falls back to filesystem scanning if Redis is empty (e.g. after a reset or
 * before the monitor has run).
 */
class ServerRegistry
{
    public function __construct(
        private readonly RedisClient $redisClient,
    ) {}

    /** @return ServerInstance[] */
    public function getAll(): array
    {
        // Merge Redis + filesystem so newly created folders are visible
        // immediately even before monitor sync writes Redis keys.
        $names = array_values(array_unique(array_merge(
            $this->redisClient->getAllServerNames(),
            $this->scanFilesystem(),
        )));

        $instances = [];
        foreach ($names as $name) {
            $instance = $this->buildInstance($name);
            if ($instance !== null) {
                $instances[] = $instance;
            } else {
                // Redis has no data for this name (filesystem fallback) —
                // build a minimal offline instance so the card still renders.
                $instances[] = new ServerInstance(
                    name:     $name,
                    dataPath: '/mc-data/' . $name,
                );
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

    /**
     * Scans /mc-data/ for server data folders as a fallback when Redis is empty.
     *
     * @return string[]
     */
    private function scanFilesystem(): array
    {
        $names  = [];
        $mcData = '/mc-data';

        if (!is_dir($mcData)) {
            return $names;
        }

        foreach (new \DirectoryIterator($mcData) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }
            $path = $entry->getPathname();
            if (
                is_dir($path . '/worlds')
                || file_exists($path . '/server.properties')
                || file_exists($path . '/mc-server-manager/meta.yaml')
            ) {
                $names[] = $entry->getFilename();
            }
        }

        return $names;
    }
}
