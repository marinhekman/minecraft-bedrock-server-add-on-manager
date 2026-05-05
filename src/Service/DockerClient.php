<?php

namespace App\Service;

class DockerClient
{
    private const SOCKET   = '/var/run/docker.sock';
    private const BASE_URL = 'http://docker/v1.41';

    public function listMinecraftContainers(): array
    {
        return $this->request('GET', '/containers/json', [
            'all'     => 'true',
            'filters' => json_encode([
                'ancestor' => ['itzg/minecraft-bedrock-server'],
            ]),
        ]) ?? [];
    }

    public function inspectContainer(string $id): ?array
    {
        return $this->request('GET', sprintf('/containers/%s/json', $id));
    }

    public function restartContainer(string $id): void
    {
        $this->request('POST', sprintf('/containers/%s/restart', $id));
    }

    public function sendCommand(string $containerId, string $command): void
    {
        // Create exec instance
        $exec = $this->request('POST', sprintf('/containers/%s/exec', $containerId), [], [
            'AttachStdout' => true,
            'AttachStderr' => true,
            'Cmd'          => ['send-command', $command],
        ]);

        if (!isset($exec['Id'])) {
            throw new \RuntimeException('Failed to create exec instance.');
        }

        // Start exec instance (detached)
        $this->request('POST', sprintf('/exec/%s/start', $exec['Id']), [], [
            'Detach' => true,
        ]);
    }

    public function getContainerStats(string $containerId): array
    {
        $url = self::BASE_URL . sprintf('/containers/%s/stats', $containerId)
            . '?stream=false';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, self::SOCKET);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('Docker socket error: ' . $error);
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException(sprintf('Docker API error %d fetching stats', $httpCode));
        }

        $data = json_decode($raw, true);

        // Calculate CPU percentage
        $cpuDelta    = $data['cpu_stats']['cpu_usage']['total_usage']
                     - $data['precpu_stats']['cpu_usage']['total_usage'];
        $systemDelta = $data['cpu_stats']['system_cpu_usage']
                     - $data['precpu_stats']['system_cpu_usage'];
        $numCpus     = $data['cpu_stats']['online_cpus'] ?? 1;
        $cpuPercent  = $systemDelta > 0
            ? round(($cpuDelta / $systemDelta) * $numCpus * 100, 1)
            : 0.0;

        // Calculate memory
        $memUsage   = $data['memory_stats']['usage']
                    - ($data['memory_stats']['stats']['cache'] ?? 0);
        $memLimit   = $data['memory_stats']['limit'];
        $memPercent = $memLimit > 0 ? round($memUsage / $memLimit * 100, 1) : 0.0;

        return [
            'cpu'        => $cpuPercent,
            'memUsageMb' => round($memUsage / 1024 / 1024, 1),
            'memLimitMb' => round($memLimit / 1024 / 1024, 1),
            'memPercent' => $memPercent,
        ];
    }

    public function getLogsBetween(string $containerId, int $since, int $until): string
    {
        $url = self::BASE_URL . sprintf('/containers/%s/logs', $containerId)
            . '?' . http_build_query([
                'stdout' => '1',
                'stderr' => '0',
                'since'  => $since,
                'until'  => $until,
            ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, self::SOCKET);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('Docker socket error: ' . $error);
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException(sprintf('Docker API error %d fetching logs', $httpCode));
        }

        return $raw ?: '';
    }

    public function resolveHostPath(string $containerPath): ?string
    {
        $selfId = $this->getSelfContainerId();
        if ($selfId === null) {
            return null;
        }

        $info = $this->inspectContainer($selfId);
        foreach ($info['Mounts'] ?? [] as $mount) {
            if (rtrim($mount['Destination'], '/') === rtrim($containerPath, '/')) {
                return $mount['Source'];
            }
        }

        return null;
    }

    private function getSelfContainerId(): ?string
    {
        $hostname = gethostname();
        if (!$hostname) {
            return null;
        }

        $containers = $this->request('GET', '/containers/json', [
            'filters' => json_encode(['id' => [$hostname]]),
        ]) ?? [];

        return $containers[0]['Id'] ?? null;
    }

    private function request(string $method, string $path, array $query = [], array $body = []): ?array
    {
        $url = self::BASE_URL . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, self::SOCKET);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (!empty($body)) {
            $json = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('Docker socket error: ' . $error);
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Docker API error %d on %s %s: %s', $httpCode, $method, $path, $raw
            ));
        }

        return $raw ? json_decode($raw, true) : null;
    }
}
