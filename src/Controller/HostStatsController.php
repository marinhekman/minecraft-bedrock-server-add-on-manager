<?php

namespace App\Controller;

use App\Service\DiskSpaceChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HostStatsController extends AbstractController
{
    public function __construct(
        private readonly DiskSpaceChecker $diskSpaceChecker,
    ) {}

    #[Route('/host/stats', name: 'host_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo === false) {
            return $this->json(['error' => '/proc/meminfo not available'], 503);
        }

        $values = [];
        foreach (explode("\n", $meminfo) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s+kB/', $line, $m)) {
                $values[$m[1]] = (int) $m[2];
            }
        }

        $totalMb    = round(($values['MemTotal']     ?? 0) / 1024);
        $availMb    = round(($values['MemAvailable'] ?? 0) / 1024);
        $usedMb     = $totalMb - $availMb;
        $usedPercent = $totalMb > 0 ? round($usedMb / $totalMb * 100, 1) : 0;

        $diskStats = $this->diskSpaceChecker->getDiskStats();

        return $this->json([
            'totalMb'         => $totalMb,
            'usedMb'          => $usedMb,
            'availMb'         => $availMb,
            'usedPercent'     => $usedPercent,
            'diskTotalGb'     => $diskStats['totalGb'],
            'diskUsedGb'      => $diskStats['usedGb'],
            'diskAvailGb'     => $diskStats['availGb'],
            'diskUsedPercent' => $diskStats['usedPercent'],
            'minFreeDiskGb'   => $diskStats['minFreeDiskGb'],
            'canCreateServer' => $diskStats['canCreateServer'],
        ]);
    }
}
