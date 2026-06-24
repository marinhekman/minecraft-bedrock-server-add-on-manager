<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class DockerClient
{
    private const API_VERSION = 'v1.41';

    /** @var array<string, array> In-memory cache for inspectContainer(), cleared per scan cycle. */
    private array $inspectCache = [];

    public function __construct(
        #[Autowire(service: 'monolog.logger.docker')]
        private readonly LoggerInterface $logger,
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

    public function listAllContainers(): array
    {
        return $this->request('GET', '/containers/json', [
            'all' => 'true',
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

    public function stopContainer(string $id): void
    {
        $this->request('POST', sprintf('/containers/%s/stop', $id));
    }

    public function removeContainer(string $id, bool $force = false): void
    {
        $this->request('DELETE', sprintf('/containers/%s', $id), [
            'force' => $force ? 'true' : 'false',
        ]);
    }

    public function createContainer(string $name, array $config): array
    {
        $result = $this->request('POST', '/containers/create', [
            'name' => $name,
        ], $config);

        return $result ?? ['Id' => null];
    }

    public function startContainer(string $id): void
    {
        $this->request('POST', sprintf('/containers/%s/start', $id));
    }

    /**
     * Pull an image from the registry.
     * This is a synchronous call that blocks until the pull is complete.
     */
    public function pullImage(string $image, string $tag = 'latest'): void
    {
        $this->logger->info('Pulling Docker image', ['image' => $image, 'tag' => $tag]);
        $url = $this->baseUrl() . '/images/create?' . http_build_query([
            'fromImage' => $image,
            'tag'       => $tag,
        ]);

        // Pull can take a while — use a longer timeout
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes for large images
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($error) {
            $this->logger->error('Docker API pull error', ['image' => $image, 'tag' => $tag, 'error' => $error]);
            throw new \RuntimeException('Docker API pull error: ' . $error);
        }

        if ($httpCode >= 400) {
            $this->logger->error('Docker API pull returned HTTP error', ['image' => $image, 'tag' => $tag, 'status' => $httpCode, 'body' => $raw]);
            throw new \RuntimeException(sprintf(
                'Docker API pull error %d for image %s:%s: %s',
                $httpCode, $image, $tag, $raw
            ));
        }

        $this->logger->info('Docker image pull completed', ['image' => $image, 'tag' => $tag]);
    }

    /**
     * Check if an image exists locally.
     */
    public function imageExists(string $image, string $tag = 'latest'): bool
    {
        try {
            $result = $this->request(
                'GET',
                sprintf('/images/%s:%s/json', $image, $tag),
                [],
                [],
                true,
            );
            return $result !== null;
        } catch (\RuntimeException) {
            return false;
        }
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
        $url  = $this->baseUrl() . sprintf('/containers/%s/stats?stream=false', $containerId);
        $raw  = $this->curl('GET', $url);
        $data = json_decode($raw, true);

        $cpuDelta    = ($data['cpu_stats']['cpu_usage']['total_usage'] ?? 0)
                     - ($data['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
        $systemDelta = ($data['cpu_stats']['system_cpu_usage'] ?? 0)
                     - ($data['precpu_stats']['system_cpu_usage'] ?? 0);
        $numCpus     = $data['cpu_stats']['online_cpus'] ?? 1;
        $cpuPercent  = $systemDelta > 0
            ? round(($cpuDelta / $systemDelta) * $numCpus * 100, 1)
            : 0.0;

        $memUsage   = ($data['memory_stats']['usage'] ?? 0)
                    - ($data['memory_stats']['stats']['cache'] ?? 0);
        $memLimit   = $data['memory_stats']['limit'] ?? 0;
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
        $containerPath = rtrim($containerPath, '/');

        foreach ($info['Mounts'] ?? [] as $mount) {
            $destination = rtrim($mount['Destination'] ?? '', '/');
            $source      = rtrim($mount['Source'] ?? '', '/');

            // Exact mount target match
            if ($destination === $containerPath) {
                return $source;
            }

            // Subpath inside mounted directory (e.g. /mc-data/server3)
            if ($destination !== '' && str_starts_with($containerPath, $destination . '/')) {
                $suffix = substr($containerPath, strlen($destination));
                return $source . $suffix;
            }
        }

        return null;
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

    private function request(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        bool $suppressHttpErrorLog = false,
    ): ?array
    {
        $url = $this->baseUrl() . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [];
        $raw     = $this->curl($method, $url, $body, $headers, $suppressHttpErrorLog);

        return $raw !== '' ? json_decode($raw, true) : null;
    }

    private function curl(
        string $method,
        string $url,
        array $body = [],
        array $extraHeaders = [],
        bool $suppressHttpErrorLog = false,
    ): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $headers = $extraHeaders;

        if (!empty($body)) {
            $json      = json_encode($body);
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
            $this->logger->error('Docker API request failed', ['method' => $method, 'url' => $url, 'error' => $error]);
            throw new \RuntimeException('Docker API error: ' . $error);
        }

        if ($httpCode >= 400) {
            if (!$suppressHttpErrorLog) {
                $this->logger->error('Docker API returned HTTP error', ['method' => $method, 'url' => $url, 'status' => $httpCode, 'body' => $raw]);
            }
            throw new \RuntimeException(sprintf(
                'Docker API error %d on %s %s: %s', $httpCode, $method, $url, $raw
            ));
        }

        return $raw ?: '';
    }
}
