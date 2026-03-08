<?php

namespace App\Service;

use App\Model\AddonPack;
use App\Model\AddonType;
use App\Model\ServerInstance;

class AddonScanner
{
    public function __construct(
        private readonly ManifestParser    $manifestParser,
        private readonly WorldPacksManager $worldPacksManager,
    ) {}

    /**
     * Returns all installed addon packs for a server, both enabled and disabled,
     * and both user-installed and system packs.
     *
     * @return AddonPack[]
     */
    public function scan(ServerInstance $server): array
    {
        $packs = array_merge(
            $this->scanDirectory($server->getBehaviourPacksPath(), [AddonType::Behaviour, AddonType::Script], $server),
            $this->scanDirectory($server->getResourcePacksPath(), [AddonType::Resource], $server),
        );

        usort($packs, fn($a, $b) => strcmp($a->manifest->name, $b->manifest->name));

        return $packs;
    }

    /** @return AddonPack[] */
    private function scanDirectory(string $dir, array $expectedTypes, ServerInstance $server): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $enabledUuids = [];
        foreach ($expectedTypes as $type) {
            $enabledUuids = array_merge($enabledUuids, $this->worldPacksManager->getEnabledUuids($server, $type));
        }
        $packs = [];

        foreach (new \DirectoryIterator($dir) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }

            $manifestPath = $entry->getPathname() . '/manifest.json';
            if (!file_exists($manifestPath)) {
                continue;
            }

            try {
                $manifest = $this->manifestParser->parseFile($manifestPath);
            } catch (\RuntimeException) {
                // Skip packs with unreadable or invalid manifests
                continue;
            }

            // Skip if the manifest type doesn't match the folder it's in
            if (!in_array($manifest->type, $expectedTypes, true)) {
                continue;
            }

            $packs[] = new AddonPack(
                manifest: $manifest,
                path: $entry->getPathname(),
                enabled: in_array($manifest->uuid, $enabledUuids, true),
                isSystem: $this->isSystemPack($entry->getFilename()),
            );
        }

        return $packs;
    }

    private function isSystemPack(string $folderName): bool
    {
        return !str_starts_with(strtolower($folderName), 'user_');
    }
}
