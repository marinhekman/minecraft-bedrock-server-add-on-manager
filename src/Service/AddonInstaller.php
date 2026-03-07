<?php

namespace App\Service;

use App\Model\AddonManifest;
use App\Model\AddonType;
use App\Model\ServerInstance;

class AddonInstaller
{
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
            // Always clean up the uploaded temp file
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

        $tmpDir = sys_get_temp_dir() . '/mcaddon_' . uniqid();
        mkdir($tmpDir, 0775, true);

        try {
            $zip->extractTo($tmpDir);
            $zip->close();

            // An .mcaddon can contain .mcpack files or pack folders directly
            foreach (glob($tmpDir . '/*.mcpack') as $mcpack) {
                $installed = array_merge($installed, $this->installMcPack($server, $mcpack));
            }

            // Also handle .mcaddon that directly contain pack folders (with manifest.json)
            if (empty($installed)) {
                $installed = $this->installPackFolder($server, $tmpDir);
            }
        } finally {
            $this->removeDirectory($tmpDir);
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
        // Manifest may be at root level or one folder deep
        $manifestPath = $this->findManifest($dir);
        if ($manifestPath === null) {
            throw new \RuntimeException('No manifest.json found in the pack.');
        }

        $manifest  = $this->manifestParser->parseFile($manifestPath);
        $targetDir = $this->resolveTargetDir($server, $manifest);
        $packDir   = dirname($manifestPath);
        $destDir   = $targetDir . '/' . $this->sanitizeFolderName($manifest);

        if (is_dir($destDir)) {
            throw new \RuntimeException(sprintf(
                'Add-on "%s" is already installed.', $manifest->name
            ));
        }

        $this->copyDirectory($packDir, $destDir);

        return [$manifest->name];
    }

    private function findManifest(string $dir): ?string
    {
        // Check root level first
        if (file_exists($dir . '/manifest.json')) {
            return $dir . '/manifest.json';
        }

        // Check one level deep (pack files are sometimes wrapped in a subfolder)
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
            AddonType::Behaviour => $server->getBehaviourPacksPath(),
            AddonType::Resource  => $server->getResourcePacksPath(),
        };
    }

    private function sanitizeFolderName(AddonManifest $manifest): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $manifest->name);
        return $name . '_' . substr($manifest->uuid, 0, 8);
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
