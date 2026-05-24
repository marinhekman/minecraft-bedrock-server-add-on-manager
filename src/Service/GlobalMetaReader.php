<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\GlobalMeta;
use Symfony\Component\Yaml\Yaml;

class GlobalMetaReader
{
    private const CONFIG_PATH = '/mc-data/config/meta.yaml';

    public function read(): GlobalMeta
    {
        if (!file_exists(self::CONFIG_PATH)) {
            return GlobalMeta::defaults();
        }

        try {
            $data = Yaml::parseFile(self::CONFIG_PATH) ?? [];
        } catch (\Exception) {
            return GlobalMeta::defaults();
        }

        if (!is_array($data)) {
            return GlobalMeta::defaults();
        }

        return GlobalMeta::fromArray($data);
    }
}
