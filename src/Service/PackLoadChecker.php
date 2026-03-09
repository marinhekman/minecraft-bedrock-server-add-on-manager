<?php

namespace App\Service;

use App\Model\AddonPack;
use App\Model\ServerInstance;

class PackLoadChecker
{
    // Read logs from container start up to 10 seconds after — Pack Stack lines
    // always appear within this window based on observed server startup behaviour.
    private const LOG_WINDOW_SECONDS = 10;

    public function __construct(
        private readonly DockerClient $dockerClient,
    ) {}

    /**
     * Returns UUIDs of packs confirmed loaded by the server on its last boot,
     * including resource packs inferred from loaded behaviour pack dependencies.
     *
     * @param AddonPack[] $allPacks
     * @return string[]
     */
    public function getLoadedUuids(ServerInstance $server, array $allPacks = []): array
    {
        if ($server->containerId === null || $server->startedAt === null) {
            return [];
        }

        try {
            $logs = $this->dockerClient->getLogsBetween(
                $server->containerId,
                $server->startedAt,
                $server->startedAt + self::LOG_WINDOW_SECONDS,
            );
        } catch (\RuntimeException) {
            return [];
        }

        $loadedUuids = $this->parsePackStackUuids($logs);

        // Infer loaded resource packs from dependencies of loaded behaviour packs
        foreach ($allPacks as $pack) {
            if (!in_array($pack->manifest->uuid, $loadedUuids, true)) {
                continue;
            }
            foreach ($pack->manifest->dependencies as $dep) {
                if (isset($dep['uuid'])) {
                    $loadedUuids[] = $dep['uuid'];
                }
            }
        }

        return array_values(array_unique($loadedUuids));
    }

    /** @return string[] */
    private function parsePackStackUuids(string $logs): array
    {
        $uuids = [];

        preg_match_all(
            '/Pack Stack\s+-\s+\[\d+\]\s+.+\(id:\s*([a-f0-9\-]+),/i',
            $logs,
            $matches
        );

        foreach ($matches[1] as $uuid) {
            $uuids[] = trim($uuid);
        }

        return $uuids;
    }
}
