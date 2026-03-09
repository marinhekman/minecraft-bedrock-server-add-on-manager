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

    private function request(string $method, string $path, array $query = []): ?array
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

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('Docker socket error: ' . $error);
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Docker API error %d on %s %s: %s', $httpCode, $method, $path, $body
            ));
        }

        return $body ? json_decode($body, true) : null;
    }
}
