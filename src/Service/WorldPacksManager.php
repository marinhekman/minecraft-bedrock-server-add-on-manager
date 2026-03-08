<?php

namespace App\Service;

use App\Model\AddonType;
use App\Model\ServerInstance;

class WorldPacksManager
{
    /**
     * Returns the UUIDs of all enabled packs for a given type.
     *
     * @return string[]
     */
    public function getEnabledUuids(ServerInstance $server, AddonType $type): array
    {
        $data = $this->read($server, $type);

        return array_column($data, 'pack_id');
    }

    public function enable(ServerInstance $server, AddonType $type, string $uuid, array $version): void
    {
        $data = $this->read($server, $type);

        foreach ($data as $entry) {
            if ($entry['pack_id'] === $uuid) {
                return; // already enabled
            }
        }

        $data[] = ['pack_id' => $uuid, 'version' => $version];

        $this->write($server, $type, $data);
    }

    public function disable(ServerInstance $server, AddonType $type, string $uuid): void
    {
        $data = $this->read($server, $type);

        $data = array_values(
            array_filter($data, fn($entry) => $entry['pack_id'] !== $uuid)
        );

        $this->write($server, $type, $data);
    }

    private function read(ServerInstance $server, AddonType $type): array
    {
        $file = $this->resolveFile($server, $type);

        if (!file_exists($file)) {
            return [];
        }

        $data = json_decode(file_get_contents($file), true);

        if (!is_array($data)) {
            return [];
        }

        return $data;
    }

    private function write(ServerInstance $server, AddonType $type, array $data): void
    {
        $file = $this->resolveFile($server, $type);
        $dir  = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents(
            $file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    private function resolveFile(ServerInstance $server, AddonType $type): string
    {
        return match ($type) {
            AddonType::Behaviour,
            AddonType::Script    => $server->getWorldBehaviourPacksFile(),
            AddonType::Resource  => $server->getWorldResourcePacksFile(),
        };
    }
}
