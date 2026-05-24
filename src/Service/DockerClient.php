<?php

namespace App\Service;

class DockerClient
{
    private const API_VERSION = 'v1.41';

    /** @var array<string, array> In-memory cache for inspectContainer(), cleared per scan cycle. */
    private array $inspectCache = [];

    public function __construct(
        private readonly string $dockerApiUrl = 'http://docker-api:2375',
    ) {}

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
        if (!isset($this->inspectCache[$id])) {
            $this->inspectCache[$id] = $this->request('GET', sprintf('/containers/%s/json', $id));
        }

        return $this->inspectCache[$id];
    }

    public function clearInspectCache(): void
    {
        $this->inspectCache = [];
    }

    public function restartContainer(string $id): void
    {
        $this->request('POST', sprintf('/containers/%s/restart', $id));
    }

    public function sendCommand(string $containerId, string $command): void
    {
        $exec = $this->request('POST', sprintf('/containers/%s/exec', $containerId), [], [
            'AttachStdout' => true,
            'AttachStderr' => true,
            'Cmd'          => ['send-command', $command],
        ]);

        if (!isset($exec['Id'])) {
            throw new \RuntimeException('Failed to create exec instance.');
        }

        $this->request('POST', sprintf('/exec/%s/start', $exec['Id']), [], [
            'Detach' => true,
        ]);
    }

    public function getContainerStats(string $containerId): array
    {
        $url = $this->baseUrl() . sprintf('/containers/%s/stats?stream=false', $containerId);

        $raw      = $this->curl('GET', $url);
        $data     = json_decode($raw, true);

        $cpuDelta    = $data['cpu_stats']['cpu_usage']['total_usage']
                     - $data['precpu_stats']['cpu_usage']['total_usage'];
        $systemDelta = $data['cpu_stats']['system_cpu_usage']
                     - $data['precpu_stats']['system_cpu_usage'];
        $numCpus     = $data['cpu_stats']['online_cpus'] ?? 1;
        $cpuPercent  = $systemDelta > 0
            ? round(($cpuDelta / $systemDelta) * $numCpus * 100, 1)
            : 0.0;

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
        $url = $this->baseUrl() . sprintf('/containers/%s/logs', $containerId)
            . '?' . http_build_query([
                'stdout' => '1',
                'stderr' => '0',
                'since'  => $since,
                'until'  => $until,
            ]);

        return $this->curl('GET', $url);
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

    private function baseUrl(): string
    {
        return rtrim($this->dockerApiUrl, '/') . '/' . self::API_VERSION;
    }

    /**
     * General JSON request — returns decoded array or null.
     */
    private function request(string $method, string $path, array $query = [], array $body = []): ?array
    {
        $url = $this->baseUrl() . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [];
        $raw     = $this->curl($method, $url, $body, $headers);

        return $raw !== '' ? json_decode($raw, true) : null;
    }

    /**
     * Low-level curl call over plain HTTP (no Unix socket).
     */
    private function curl(string $method, string $url, array $body = [], array $extraHeaders = []): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $headers = $extraHeaders;

        if (!empty($body)) {
            $json    = json_encode($body);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('Docker API error: ' . $error);
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Docker API error %d on %s %s: %s', $httpCode, $method, $url, $raw
            ));
        }

        return $raw ?: '';
    }

    public function stopContainer(string $id): void
    {
        $this->request('POST', sprintf('/containers/%s/stop', $id));
    }

    /**
     * Extracts the MEMORY_PROFILE env var from inspect data.
     * Returns 'medium' if the variable is absent.
     *
     * @param array $inspectData Return value of inspectContainer()
     */
    public function getMemoryProfile(array $inspectData): string
    {
        foreach ($inspectData['Config']['Env'] ?? [] as $env) {
            if (str_starts_with($env, 'MEMORY_PROFILE=')) {
                $value = substr($env, strlen('MEMORY_PROFILE='));
                if (in_array($value, ['low', 'medium', 'high'], true)) {
                    return $value;
                }
            }
        }

        return 'medium';
    }
}
