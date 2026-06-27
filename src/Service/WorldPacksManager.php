<?php

namespace App\Service;

use App\Model\AddonType;
use App\Model\ServerInstance;
use Psr\Log\LoggerInterface;

class WorldPacksManager
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

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
            $this->logger->debug('World pack index file does not exist yet; using empty list.', [
                'server' => $server->name,
                'type' => $type->value,
                'file' => $file,
            ]);
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
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf(
                    'Failed to create world metadata directory "%s". %s',
                    $dir,
                    $this->getLastPhpErrorMessage(),
                ));
            }
            $this->logger->info('Created world metadata directory for add-on enable/disable state.', [
                'server' => $server->name,
                'type' => $type->value,
                'directory' => $dir,
            ]);
        }

        $bytes = @file_put_contents(
            $file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
        if ($bytes === false) {
            throw new \RuntimeException(sprintf(
                'Failed to write world pack index file "%s". %s',
                $file,
                $this->getLastPhpErrorMessage(),
            ));
        }

        $this->logger->debug('Updated world pack index file.', [
            'server' => $server->name,
            'type' => $type->value,
            'file' => $file,
            'entry_count' => count($data),
            'bytes_written' => $bytes,
        ]);
    }

    private function getLastPhpErrorMessage(): string
    {
        $last = error_get_last();
        if (!is_array($last) || !isset($last['message'])) {
            return 'No additional PHP error details available.';
        }

        return $last['message'];
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
