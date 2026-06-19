<?php

namespace App\Service;

use App\Model\ServerMeta;
use Symfony\Component\Yaml\Yaml;

class ServerMetaReader
{
    private const META_DIR   = 'mc-server-manager';
    private const META_FILE  = 'meta.yaml';
    private const IMAGE_FILE = 'image.png';

    public function read(string $dataPath): ServerMeta
    {
        $dir   = rtrim($dataPath, '/') . '/' . self::META_DIR;
        $file  = $dir . '/' . self::META_FILE;
        $image = $dir . '/' . self::IMAGE_FILE;

        $data = [];
        if (file_exists($file)) {
            try {
                $data = Yaml::parseFile($file) ?? [];
            } catch (\Exception) {
                $data = [];
            }
        }

        return new ServerMeta(
            displayName:  $data['display_name']  ?? null,
            description:  $data['description']   ?? null,
            imagePath:    file_exists($image) ? $image : null,
            heartbeatTtl: isset($data['heartbeat_ttl']) ? (int) $data['heartbeat_ttl'] : null,
        );
    }
}
