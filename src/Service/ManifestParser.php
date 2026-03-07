<?php

namespace App\Service;

use App\Model\AddonManifest;
use App\Model\AddonType;

class ManifestParser
{
    public function parseFile(string $path): AddonManifest
    {
        if (!file_exists($path)) {
            throw new \RuntimeException(sprintf('Manifest file not found: %s', $path));
        }

        $data = json_decode(file_get_contents($path), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(sprintf(
                'Invalid JSON in manifest %s: %s', $path, json_last_error_msg()
            ));
        }

        return $this->parse($data, $path);
    }

    public function parseString(string $json, string $context = ''): AddonManifest
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(sprintf(
                'Invalid JSON in manifest%s: %s',
                $context ? " ($context)" : '',
                json_last_error_msg()
            ));
        }

        return $this->parse($data, $context);
    }

    private function parse(array $data, string $context): AddonManifest
    {
        $header = $data['header'] ?? null;
        if (!$header) {
            throw new \RuntimeException(sprintf('Missing "header" in manifest: %s', $context));
        }

        $uuid = $header['uuid'] ?? null;
        if (!$uuid) {
            throw new \RuntimeException(sprintf('Missing "header.uuid" in manifest: %s', $context));
        }

        $name    = $this->cleanName($header['name'] ?? 'Unknown');
        $version = $header['version'] ?? [0, 0, 0];
        $type    = $this->resolveType($data['modules'] ?? [], $context);

        return new AddonManifest(
            uuid: $uuid,
            name: $name,
            version: $version,
            type: $type,
            dependencies: $data['dependencies'] ?? [],
        );
    }

    private function cleanName(string $name): string
    {
        // Strip Minecraft color/formatting codes (§ followed by any character)
        return trim(preg_replace('/§./', '', $name));
    }

    private function resolveType(array $modules, string $context): AddonType
    {
        foreach ($modules as $module) {
            $addonType = AddonType::tryFrom($module['type'] ?? '');
            if ($addonType !== null) {
                return $addonType;
            }
        }

        throw new \RuntimeException(sprintf(
            'Could not determine addon type from modules in manifest: %s', $context
        ));
    }
}
