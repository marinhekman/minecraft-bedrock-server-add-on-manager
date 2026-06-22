<?php

namespace App\Service;

class DiskSpaceChecker
{
    private const MC_DATA_PATH = '/mc-data';

    public function __construct(
        private readonly int $minFreeDiskGb = 4,
    ) {}

    public function getDiskStats(): array
    {
        $path = self::MC_DATA_PATH;

        if (!is_dir($path)) {
            return [
                'totalGb'         => 0,
                'usedGb'          => 0,
                'availGb'         => 0,
                'usedPercent'     => 0,
                'minFreeDiskGb'   => $this->minFreeDiskGb,
                'canCreateServer' => false,
            ];
        }

        $totalBytes = disk_total_space($path);
        $freeBytes  = disk_free_space($path);

        if ($totalBytes === false || $freeBytes === false) {
            return [
                'totalGb'         => 0,
                'usedGb'          => 0,
                'availGb'         => 0,
                'usedPercent'     => 0,
                'minFreeDiskGb'   => $this->minFreeDiskGb,
                'canCreateServer' => false,
            ];
        }

        $usedBytes   = $totalBytes - $freeBytes;
        $freeGb      = $freeBytes / 1024 / 1024 / 1024;
        $usedPercent = $totalBytes > 0 ? ($usedBytes / $totalBytes) * 100 : 0;

        return [
            'totalGb'         => round($totalBytes / 1024 / 1024 / 1024, 1),
            'usedGb'          => round($usedBytes / 1024 / 1024 / 1024, 1),
            'availGb'         => round($freeGb, 1),
            'usedPercent'     => round($usedPercent, 1),
            'minFreeDiskGb'   => $this->minFreeDiskGb,
            'canCreateServer' => $freeGb >= $this->minFreeDiskGb,
        ];
    }

    public function canCreateServer(): bool
    {
        return $this->getDiskStats()['canCreateServer'];
    }
}

