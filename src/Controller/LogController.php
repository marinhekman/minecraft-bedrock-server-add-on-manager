<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class LogController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.logs_dir%')]
        private readonly string $logsDir,
    ) {}

    /**
     * Returns the last N JSON log entries from the central app log file.
     * Only accessible to admins.
     */
    #[Route('/admin/logs/tail', name: 'admin_logs_tail', methods: ['GET'])]
    public function tail(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // rotating_file handler adds a date suffix; try today's file first, then plain name
        $candidates = [
            $this->logsDir . '/app-' . date('Y-m-d') . '.log',
            $this->logsDir . '/app.log',
        ];

        $logFile = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate) && is_readable($candidate)) {
                $logFile = $candidate;
                break;
            }
        }

        if ($logFile === null) {
            return $this->json(['entries' => [], 'notice' => 'Log file not found yet. No events logged.']);
        }

        $lines   = $this->tailFile($logFile, 150);
        $entries = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $data = json_decode($line, true);
            if (is_array($data)) {
                // Normalise level_name to uppercase string
                if (!isset($data['level_name']) && isset($data['level'])) {
                    $data['level_name'] = strtoupper((string) $data['level']);
                }
                $entries[] = $data;
            }
        }

        return $this->json(['entries' => $entries]);
    }

    /**
     * Efficiently reads the last $lines lines from a file using backward seek.
     *
     * @return string[]
     */
    private function tailFile(string $file, int $lines): array
    {
        $handle = @fopen($file, 'r');
        if (!$handle) {
            return [];
        }

        fseek($handle, 0, SEEK_END);
        $size      = ftell($handle);
        $chunkSize = 8192;
        $buffer    = '';
        $lineCount = 0;

        while ($size > 0 && $lineCount < $lines + 1) {
            $readSize  = min($chunkSize, $size);
            $size     -= $readSize;
            fseek($handle, $size);
            $buffer    = fread($handle, $readSize) . $buffer;
            $lineCount = substr_count($buffer, "\n");
        }

        fclose($handle);

        $all = explode("\n", $buffer);

        // Remove trailing empty line
        if (end($all) === '') {
            array_pop($all);
        }

        return array_slice($all, -$lines);
    }
}

