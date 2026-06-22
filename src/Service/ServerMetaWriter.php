<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

class ServerMetaWriter
{
    private const META_DIR   = 'mc-server-manager';
    private const META_FILE  = 'meta.yaml';
    private const IMAGE_FILE = 'image.png';

    public function writeName(string $dataPath, string $displayName): void
    {
        $this->updateMeta($dataPath, ['display_name' => $displayName]);
    }

    public function writeDescription(string $dataPath, string $description): void
    {
        $this->updateMeta($dataPath, ['description' => $description]);
    }

    public function writeImage(string $dataPath, string $tmpPath, string $mimeType): void
    {
        $dir = rtrim($dataPath, '/') . '/' . self::META_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new \InvalidArgumentException('Unsupported image type: ' . $mimeType);
        }

        $raw = @file_get_contents($tmpPath);
        if ($raw === false) {
            throw new \RuntimeException('Failed to read uploaded image.');
        }

        // Decode using a generic loader so missing format-specific GD functions
        // (e.g. imagecreatefromwebp) never cause a fatal error.
        $src = @imagecreatefromstring($raw);

        if ($src === false) {
            throw new \RuntimeException('Failed to decode uploaded image. Check GD format support.');
        }

        $origW = imagesx($src);
        $origH = imagesy($src);

        // Preserve original dimensions; CSS handles display size/cropping.
        $dst = imagecreatetruecolor($origW, $origH);

        // Preserve transparency for PNG/GIF
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $origW, $origH, $origW, $origH);

        imagedestroy($src);

        $dest = $dir . '/' . self::IMAGE_FILE;
        if (!imagepng($dst, $dest)) {
            imagedestroy($dst);
            throw new \RuntimeException('Failed to save image.');
        }

        imagedestroy($dst);
    }

    private function updateMeta(string $dataPath, array $updates): void
    {
        $dir  = rtrim($dataPath, '/') . '/' . self::META_DIR;
        $file = $dir . '/' . self::META_FILE;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data = [];
        if (file_exists($file)) {
            try {
                $data = Yaml::parseFile($file) ?? [];
            } catch (\Exception) {
                $data = [];
            }
        }

        foreach ($updates as $key => $value) {
            if ($value === '' || $value === null) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }
        }

        $written = @file_put_contents($file, Yaml::dump($data));
        if ($written === false) {
            throw new \RuntimeException("Failed to write meta.yaml at {$file}");
        }
    }
}

