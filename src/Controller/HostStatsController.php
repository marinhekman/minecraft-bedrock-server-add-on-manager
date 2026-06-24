<?php

namespace App\Controller;

use App\Service\DiskSpaceChecker;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HostStatsController extends AbstractController
{
    public function __construct(
        private readonly DiskSpaceChecker $diskSpaceChecker,
        private readonly LoggerInterface  $logger,
    ) {}

    #[Route('/host/stats', name: 'host_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $memory = $this->readMemoryStats();

        $diskStats = $this->diskSpaceChecker->getDiskStats();

        return $this->json([
            'memoryAvailable' => $memory !== null,
            'memorySource'    => $memory['source'] ?? null,
            'memoryError'     => $memory['error'] ?? null,
            'totalMb'         => $memory['totalMb'] ?? null,
            'usedMb'          => $memory['usedMb'] ?? null,
            'availMb'         => $memory['availMb'] ?? null,
            'usedPercent'     => $memory['usedPercent'] ?? null,
            'diskTotalGb'     => $diskStats['totalGb'],
            'diskUsedGb'      => $diskStats['usedGb'],
            'diskAvailGb'     => $diskStats['availGb'],
            'diskUsedPercent' => $diskStats['usedPercent'],
            'minFreeDiskGb'   => $diskStats['minFreeDiskGb'],
            'canCreateServer' => $diskStats['canCreateServer'],
        ]);
    }

    /**
     * @return array{source: string, totalMb: float|int, usedMb: float|int, availMb: float|int, usedPercent: float|int}|array{error: string}|null
     */
    private function readMemoryStats(): ?array
    {
        $proc = $this->readProcMeminfo();
        if ($proc !== null) {
            return $proc;
        }

        $cgroup = $this->readCgroupMemory();
        if ($cgroup !== null) {
            return $cgroup;
        }

        $message = 'No supported memory source available (/proc/meminfo or cgroup files).';
        $this->logger->warning($message);

        return ['error' => $message];
    }

    /**
     * @return array{source: string, totalMb: float|int, usedMb: float|int, availMb: float|int, usedPercent: float|int}|null
     */
    private function readProcMeminfo(): ?array
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo === false) {
            return null;
        }

        $values = [];
        foreach (explode("\n", $meminfo) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s+kB/', $line, $m)) {
                $values[$m[1]] = (int) $m[2];
            }
        }

        $totalMb = round(($values['MemTotal'] ?? 0) / 1024);
        $availMb = round(($values['MemAvailable'] ?? 0) / 1024);
        $usedMb  = $totalMb - $availMb;

        if ($totalMb <= 0) {
            $this->logger->warning('MemTotal missing or zero in /proc/meminfo');
            return null;
        }

        return [
            'source'      => 'proc_meminfo',
            'totalMb'     => $totalMb,
            'usedMb'      => $usedMb,
            'availMb'     => $availMb,
            'usedPercent' => round($usedMb / $totalMb * 100, 1),
        ];
    }

    /**
     * @return array{source: string, totalMb: float|int, usedMb: float|int, availMb: float|int, usedPercent: float|int}|null
     */
    private function readCgroupMemory(): ?array
    {
        $pairs = [
            ['/sys/fs/cgroup/memory.current', '/sys/fs/cgroup/memory.max'],
            ['/sys/fs/cgroup/memory/memory.usage_in_bytes', '/sys/fs/cgroup/memory/memory.limit_in_bytes'],
        ];

        foreach ($pairs as [$usagePath, $limitPath]) {
            $usage = @file_get_contents($usagePath);
            $limit = @file_get_contents($limitPath);

            if ($usage === false || $limit === false) {
                continue;
            }

            $usage = trim($usage);
            $limit = trim($limit);

            if ($usage === '' || $limit === '' || $limit === 'max') {
                continue;
            }

            $usageBytes = (int) $usage;
            $limitBytes = (int) $limit;
            if ($usageBytes <= 0 || $limitBytes <= 0 || $usageBytes > $limitBytes) {
                continue;
            }

            $usedMb  = round($usageBytes / 1024 / 1024);
            $totalMb = round($limitBytes / 1024 / 1024);
            $availMb = max(0, $totalMb - $usedMb);

            $this->logger->info('Using cgroup memory stats fallback', ['usagePath' => $usagePath, 'limitPath' => $limitPath]);

            return [
                'source'      => 'cgroup',
                'totalMb'     => $totalMb,
                'usedMb'      => $usedMb,
                'availMb'     => $availMb,
                'usedPercent' => round($usedMb / $totalMb * 100, 1),
            ];
        }

        return null;
    }
}
