<?php

namespace App\Service;

use App\Model\AddonManifest;
use App\Model\AddonType;
use App\Model\ServerInstance;

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
                unlink($uploadedFilePath);
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
        mkdir($tmpDir, 0775, true);

        try {
            $zip->extractTo($tmpDir);
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
                                $installed = array_merge(
                                    $installed,
                                    $this->installPackFolder($server, $packEntry->getPathname())
                                );
                            } catch (\RuntimeException $e) {
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
                                $installed = array_merge(
                                    $installed,
                                    $this->installPackFolder($server, $packEntry->getPathname())
                                );
                            } catch (\RuntimeException $e) {
                                $errors[] = $e->getMessage();
                            }
                        }
                    }
                }
            }

            // Case 1: .mcaddon contains .mcpack files
            if (empty($installed) && empty($errors)) {
                foreach (glob($tmpDir . '/*.mcpack') as $mcpack) {
                    try {
                        $installed = array_merge($installed, $this->installMcPack($server, $mcpack));
                    } catch (\RuntimeException $e) {
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
                            $installed = array_merge(
                                $installed,
                                $this->installPackFolder($server, $entry->getPathname())
                            );
                        } catch (\RuntimeException $e) {
                            $errors[] = $e->getMessage();
                        }
                    }
                }
            }

            // Case 3: .mcaddon is itself a single pack (manifest.json at root)
            if (empty($installed) && empty($errors)) {
                $installed = $this->installPackFolder($server, $tmpDir);
            }

        } finally {
            $this->removeDirectory($tmpDir);
        }

        if (!empty($errors) && empty($installed)) {
            throw new \RuntimeException(implode(' / ', $errors));
        }

        foreach ($errors as $error) {
            $installed[] = '⚠️ ' . $error;
        }

        return $installed;
    }

    /** @return string[] */
    private function installMcPack(ServerInstance $server, string $path): array
    {
        $zip = $this->openZip($path);

        $tmpDir = sys_get_temp_dir() . '/mcpack_' . uniqid();
        mkdir($tmpDir, 0775, true);

        try {
            $zip->extractTo($tmpDir);
            $zip->close();

            return $this->installPackFolder($server, $tmpDir);
        } finally {
            $this->removeDirectory($tmpDir);
        }
    }

    /** @return string[] */
    private function installPackFolder(ServerInstance $server, string $dir): array
    {
        $manifestPath = $this->findManifest($dir);
        if ($manifestPath === null) {
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
                    throw new \RuntimeException(sprintf(
                        '"%s" version %s is already installed.',
                        $manifest->name,
                        $manifest->getVersionString()
                    ));
                }

                if ($cmp < 0) {
                    throw new \RuntimeException(sprintf(
                        'Cannot install "%s" version %s — version %s is already installed. Remove it first to downgrade.',
                        $manifest->name,
                        $manifest->getVersionString(),
                        $existing->getVersionString()
                    ));
                }
            }

            $this->removeDirectory($destDir);
        }

        $this->copyDirectory($packDir, $destDir);

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

    private function copyDirectory(string $src, string $dst): void
    {
        mkdir($dst, 0775, true);

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        ) as $item) {
            $destPath = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
            if ($item->isDir()) {
                mkdir($destPath, 0775, true);
            } else {
                copy($item->getPathname(), $destPath);
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
