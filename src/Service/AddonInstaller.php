<?php

namespace App\Service;

use App\Model\AddonManifest;
use App\Model\AddonType;
use App\Model\ServerInstance;
use Psr\Log\LoggerInterface;

class AddonInstaller
{
    /**
     * Top-level folder names inside a .mcaddon that act as containers
     * for multiple packs rather than being a pack themselves.
     */
    private const BEHAVIOUR_CONTAINER_FOLDERS = ['behavior_packs', 'behavior_pack'];
    private const RESOURCE_CONTAINER_FOLDERS  = ['resource_packs', 'resource_pack'];

    public function __construct(
        private readonly ManifestParser $manifestParser,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Install a .mcaddon or .mcpack file into the server.
     * Returns a list of installed pack names.
     *
     * @return string[]
     */
    public function install(ServerInstance $server, string $uploadedFilePath, string $originalFilename): array
    {
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        $this->logger->info('Starting add-on installation.', [
            'server' => $server->name,
            'file_name' => $originalFilename,
            'extension' => $ext,
            'temp_path' => $uploadedFilePath,
        ]);

        try {
            return match ($ext) {
                'mcaddon' => $this->installMcAddon($server, $uploadedFilePath),
                'mcpack'  => $this->installMcPack($server, $uploadedFilePath),
                default   => throw new \RuntimeException(sprintf(
                    'Unsupported file type ".%s". Only .mcaddon and .mcpack are supported.', $ext
                )),
            };
        } finally {
            if (file_exists($uploadedFilePath)) {
                if (!@unlink($uploadedFilePath)) {
                    $this->logger->warning('Failed to remove uploaded temporary file after installation attempt.', [
                        'server' => $server->name,
                        'temp_path' => $uploadedFilePath,
                        'error' => $this->getLastPhpErrorMessage(),
                    ]);
                }
            }
        }
    }

    /** @return string[] */
    private function installMcAddon(ServerInstance $server, string $path): array
    {
        $zip = $this->openZip($path);
        $installed = [];
        $errors = [];

        $tmpDir = sys_get_temp_dir() . '/mcaddon_' . uniqid();
        $this->createDirectoryOrThrow($tmpDir, [
            'server' => $server->name,
            'purpose' => 'mcaddon temp extraction directory',
        ]);

        try {
            if (!$zip->extractTo($tmpDir)) {
                throw new \RuntimeException(sprintf(
                    'Failed to extract mcaddon archive "%s" to "%s". %s',
                    basename($path),
                    $tmpDir,
                    $this->getLastPhpErrorMessage(),
                ));
            }
            $zip->close();

            // Case 0: .mcaddon contains behavior_packs/ or resource_packs/ container
            // folders — each containing pack subfolders with their own manifest.json.
            // e.g.:
            //   behavior_packs/
            //       MyBehaviorPack/
            //           manifest.json
            //   resource_packs/
            //       MyResourcePack/
            //           manifest.json
            foreach (new \DirectoryIterator($tmpDir) as $entry) {
                if (!$entry->isDir() || $entry->isDot()) {
                    continue;
                }

                $folderName = strtolower($entry->getFilename());

                if (in_array($folderName, self::BEHAVIOUR_CONTAINER_FOLDERS, true)) {
                    foreach (new \DirectoryIterator($entry->getPathname()) as $packEntry) {
                        if (!$packEntry->isDir() || $packEntry->isDot()) {
                            continue;
                        }
                        if (file_exists($packEntry->getPathname() . '/manifest.json')) {
                            try {
                                $this->logger->debug('Installing behaviour pack from container folder.', [
                                    'server' => $server->name,
                                    'pack_dir' => $packEntry->getPathname(),
                                ]);
                                $installed = array_merge(
                                    $installed,
                                    $this->installPackFolder($server, $packEntry->getPathname())
                                );
                            } catch (\RuntimeException $e) {
                                $this->logger->warning('Failed installing behaviour pack from container folder.', [
                                    'server' => $server->name,
                                    'pack_dir' => $packEntry->getPathname(),
                                    'error' => $e->getMessage(),
                                ]);
                                $errors[] = $e->getMessage();
                            }
                        }
                    }
                } elseif (in_array($folderName, self::RESOURCE_CONTAINER_FOLDERS, true)) {
                    foreach (new \DirectoryIterator($entry->getPathname()) as $packEntry) {
                        if (!$packEntry->isDir() || $packEntry->isDot()) {
                            continue;
                        }
                        if (file_exists($packEntry->getPathname() . '/manifest.json')) {
                            try {
                                $this->logger->debug('Installing resource pack from container folder.', [
                                    'server' => $server->name,
                                    'pack_dir' => $packEntry->getPathname(),
                                ]);
                                $installed = array_merge(
                                    $installed,
                                    $this->installPackFolder($server, $packEntry->getPathname())
                                );
                            } catch (\RuntimeException $e) {
                                $this->logger->warning('Failed installing resource pack from container folder.', [
                                    'server' => $server->name,
                                    'pack_dir' => $packEntry->getPathname(),
                                    'error' => $e->getMessage(),
                                ]);
                                $errors[] = $e->getMessage();
                            }
                        }
                    }
                }
            }

            // Case 1: .mcaddon contains .mcpack files
            if (empty($installed) && empty($errors)) {
                $nestedMcPacks = glob($tmpDir . '/*.mcpack') ?: [];

                foreach ($nestedMcPacks as $mcpack) {
                    try {
                        $this->logger->debug('Installing nested mcpack from mcaddon.', [
                            'server' => $server->name,
                            'mcpack' => $mcpack,
                        ]);
                        $installed = array_merge($installed, $this->installMcPack($server, $mcpack));
                    } catch (\RuntimeException $e) {
                        $this->logger->warning('Failed installing nested mcpack from mcaddon.', [
                            'server' => $server->name,
                            'mcpack' => $mcpack,
                            'error' => $e->getMessage(),
                        ]);
                        $errors[] = $e->getMessage();
                    }
                }
            }

            // Case 2: .mcaddon contains pack subfolders directly (each with manifest.json)
            if (empty($installed) && empty($errors)) {
                foreach (new \DirectoryIterator($tmpDir) as $entry) {
                    if (!$entry->isDir() || $entry->isDot()) {
                        continue;
                    }
                    if (file_exists($entry->getPathname() . '/manifest.json')) {
                        try {
                            $this->logger->debug('Installing pack from top-level folder in mcaddon.', [
                                'server' => $server->name,
                                'pack_dir' => $entry->getPathname(),
                            ]);
                            $installed = array_merge(
                                $installed,
                                $this->installPackFolder($server, $entry->getPathname())
                            );
                        } catch (\RuntimeException $e) {
                            $this->logger->warning('Failed installing top-level pack folder from mcaddon.', [
                                'server' => $server->name,
                                'pack_dir' => $entry->getPathname(),
                                'error' => $e->getMessage(),
                            ]);
                            $errors[] = $e->getMessage();
                        }
                    }
                }

            }

            // Case 2b: .mcaddon contains one wrapper folder (or deeper nesting)
            // and manifests are not directly in the top-level pack folders.
            if (empty($installed) && empty($errors)) {
                $packDirs = $this->findManifestDirectoriesRecursively($tmpDir);
                foreach ($packDirs as $packDir) {
                    try {
                        $this->logger->debug('Installing pack from recursively discovered manifest.', [
                            'server' => $server->name,
                            'pack_dir' => $packDir,
                        ]);
                        $installed = array_merge($installed, $this->installPackFolder($server, $packDir));
                    } catch (\RuntimeException $e) {
                        $this->logger->warning('Failed installing recursively discovered pack folder.', [
                            'server' => $server->name,
                            'pack_dir' => $packDir,
                            'error' => $e->getMessage(),
                        ]);
                        $errors[] = $e->getMessage();
                    }
                }
            }

            // Case 3: .mcaddon is itself a single pack (manifest.json at root)
            if (empty($installed) && empty($errors)) {
                $installed = $this->installPackFolder($server, $tmpDir);
            }

        } finally {
            $this->removeDirectory($tmpDir, [
                'server' => $server->name,
                'purpose' => 'mcaddon temp extraction directory',
            ]);
        }

        if (!empty($errors) && empty($installed)) {
            $this->logger->error('mcaddon installation failed.', [
                'server' => $server->name,
                'errors' => $errors,
            ]);
            throw new \RuntimeException(implode(' / ', $errors));
        }

        if (!empty($errors)) {
            $this->logger->warning('mcaddon installation partially succeeded.', [
                'server' => $server->name,
                'installed' => $installed,
                'errors' => $errors,
            ]);
        }

        foreach ($errors as $error) {
            $installed[] = '⚠️ ' . $error;
        }

        $this->logger->info('mcaddon installation completed.', [
            'server' => $server->name,
            'installed_entries' => $installed,
        ]);

        return $installed;
    }

    /** @return string[] */
    private function installMcPack(ServerInstance $server, string $path): array
    {
        $zip = $this->openZip($path);

        $tmpDir = sys_get_temp_dir() . '/mcpack_' . uniqid();
        $this->createDirectoryOrThrow($tmpDir, [
            'server' => $server->name,
            'purpose' => 'mcpack temp extraction directory',
        ]);

        try {
            if (!$zip->extractTo($tmpDir)) {
                throw new \RuntimeException(sprintf(
                    'Failed to extract mcpack archive "%s" to "%s". %s',
                    basename($path),
                    $tmpDir,
                    $this->getLastPhpErrorMessage(),
                ));
            }
            $zip->close();

            return $this->installPackFolder($server, $tmpDir);
        } finally {
            $this->removeDirectory($tmpDir, [
                'server' => $server->name,
                'purpose' => 'mcpack temp extraction directory',
            ]);
        }
    }

    /** @return string[] */
    private function installPackFolder(ServerInstance $server, string $dir): array
    {
        $manifestPath = $this->findManifest($dir);
        if ($manifestPath === null) {
            $this->logger->warning('No manifest.json found while installing pack folder.', [
                'server' => $server->name,
                'pack_dir' => $dir,
            ]);
            throw new \RuntimeException('No manifest.json found in the pack.');
        }

        $manifest  = $this->manifestParser->parseFile($manifestPath);
        $targetDir = $this->resolveTargetDir($server, $manifest);
        $packDir   = dirname($manifestPath);
        $destDir   = $targetDir . '/user_' . $this->sanitizeFolderName($manifest);

        if (is_dir($destDir)) {
            $existingManifestPath = $destDir . '/manifest.json';
            if (file_exists($existingManifestPath)) {
                $existing = $this->manifestParser->parseFile($existingManifestPath);
                $cmp = $this->compareVersions($manifest->version, $existing->version);

                if ($cmp === 0) {
                    $this->logger->warning('Skipping install because same add-on version is already present.', [
                        'server' => $server->name,
                        'addon_name' => $manifest->name,
                        'addon_uuid' => $manifest->uuid,
                        'version' => $manifest->getVersionString(),
                    ]);
                    throw new \RuntimeException(sprintf(
                        '"%s" version %s is already installed.',
                        $manifest->name,
                        $manifest->getVersionString()
                    ));
                }

                if ($cmp < 0) {
                    $this->logger->warning('Skipping install because installed version is newer than uploaded add-on.', [
                        'server' => $server->name,
                        'addon_name' => $manifest->name,
                        'addon_uuid' => $manifest->uuid,
                        'uploaded_version' => $manifest->getVersionString(),
                        'installed_version' => $existing->getVersionString(),
                    ]);
                    throw new \RuntimeException(sprintf(
                        'Cannot install "%s" version %s — version %s is already installed. Remove it first to downgrade.',
                        $manifest->name,
                        $manifest->getVersionString(),
                        $existing->getVersionString()
                    ));
                }
            }

            $this->removeDirectory($destDir, [
                'server' => $server->name,
                'purpose' => 'existing destination pack directory',
                'destination' => $destDir,
            ]);
        }

        $this->copyDirectory($packDir, $destDir, [
            'server' => $server->name,
            'source' => $packDir,
            'destination' => $destDir,
            'addon_name' => $manifest->name,
            'addon_uuid' => $manifest->uuid,
        ]);

        $this->logger->info('Add-on pack installed.', [
            'server' => $server->name,
            'addon_name' => $manifest->name,
            'addon_uuid' => $manifest->uuid,
            'addon_type' => $manifest->type->value,
            'version' => $manifest->getVersionString(),
            'destination' => $destDir,
        ]);

        return [$manifest->name];
    }

    private function findManifest(string $dir): ?string
    {
        if (file_exists($dir . '/manifest.json')) {
            return $dir . '/manifest.json';
        }

        foreach (new \DirectoryIterator($dir) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }
            $candidate = $entry->getPathname() . '/manifest.json';
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function findManifestDirectoriesRecursively(string $baseDir): array
    {
        $dirs = [];

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        ) as $entry) {
            if (!$entry->isDir()) {
                continue;
            }

            $manifest = $entry->getPathname() . '/manifest.json';
            if (file_exists($manifest)) {
                $dirs[] = $entry->getPathname();
            }
        }

        return array_values(array_unique($dirs));
    }

    private function resolveTargetDir(ServerInstance $server, AddonManifest $manifest): string
    {
        return match ($manifest->type) {
            AddonType::Behaviour,
            AddonType::Script    => $server->getBehaviourPacksPath(),
            AddonType::Resource  => $server->getResourcePacksPath(),
        };
    }

    private function sanitizeFolderName(AddonManifest $manifest): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $manifest->name);
        return $name . '_' . substr($manifest->uuid, 0, 8);
    }

    private function compareVersions(array $a, array $b): int
    {
        for ($i = 0; $i < max(count($a), count($b)); $i++) {
            $av = $a[$i] ?? 0;
            $bv = $b[$i] ?? 0;
            if ($av !== $bv) {
                return $av <=> $bv;
            }
        }
        return 0;
    }

    private function openZip(string $path): \ZipArchive
    {
        $zip = new \ZipArchive();
        $result = $zip->open($path);

        if ($result !== true) {
            throw new \RuntimeException(sprintf(
                'Failed to open zip file "%s" (error code: %d).', basename($path), $result
            ));
        }

        return $zip;
    }

    private function copyDirectory(string $src, string $dst, array $context = []): void
    {
        $this->createDirectoryOrThrow($dst, $context + [
            'purpose' => 'destination add-on directory',
        ]);

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        ) as $item) {
            $destPath = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
            if ($item->isDir()) {
                $this->createDirectoryOrThrow($destPath, $context + [
                    'purpose' => 'add-on subdirectory',
                    'directory' => $destPath,
                ]);
            } else {
                if (!@copy($item->getPathname(), $destPath)) {
                    throw new \RuntimeException(sprintf(
                        'Failed to copy add-on file from "%s" to "%s". %s',
                        $item->getPathname(),
                        $destPath,
                        $this->getLastPhpErrorMessage(),
                    ));
                }
            }
        }

    }

    private function removeDirectory(string $dir, array $context = []): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            if ($item->isDir()) {
                if (!@rmdir($item->getPathname())) {
                    $this->logger->warning('Failed removing directory while cleaning up add-on files.', $context + [
                        'path' => $item->getPathname(),
                        'error' => $this->getLastPhpErrorMessage(),
                    ]);
                }
            } else {
                if (!@unlink($item->getPathname())) {
                    $this->logger->warning('Failed removing file while cleaning up add-on files.', $context + [
                        'path' => $item->getPathname(),
                        'error' => $this->getLastPhpErrorMessage(),
                    ]);
                }
            }
        }

        if (!@rmdir($dir)) {
            $this->logger->warning('Failed removing root directory while cleaning up add-on files.', $context + [
                'path' => $dir,
                'error' => $this->getLastPhpErrorMessage(),
            ]);
        }
    }

    private function createDirectoryOrThrow(string $dir, array $context = []): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf(
                'Failed to create directory "%s". %s',
                $dir,
                $this->getLastPhpErrorMessage(),
            ));
        }

    }

    private function getLastPhpErrorMessage(): string
    {
        $last = error_get_last();
        if (!is_array($last) || !isset($last['message'])) {
            return 'No additional PHP error details available.';
        }

        return $last['message'];
    }
}
