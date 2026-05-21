<?php

namespace App\Server;

use App\Service\DockerClient;
use App\Service\RedisClient;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\Browser as ReactBrowser;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class MinecraftMonitor
{
    private const PLAYER_JOIN_REGEX  = '/Player connected:\s+([^,]+),\s+xuid:\s*(\d+)/i';
    private const PLAYER_LEAVE_REGEX = '/Player disconnected:\s+([^,]+),\s+xuid:\s*(\d+)/i';
    private const PACK_STACK_REGEX   = '/Pack Stack\s+-\s+\[\d+\]\s+.+\(id:\s*([a-f0-9\-]+),/i';

    /** @var array<string, array> Known servers */
    private array $servers = [];

    /** @var array<string, \React\Stream\ReadableStreamInterface> Open stream bodies per server */
    private array $streams = [];

    /** @var array<string, int> Current player counts per server */
    private array $playerCounts = [];

    /** @var array<string, string> Incomplete line buffer per server */
    private array $lineBuffers = [];

    private LoopInterface   $loop;
    private ReactBrowser    $browser;
    private OutputInterface $output;

    /** @var array<string, array<string, string[]>> Pack name → UUIDs index per server */
    private array $packNameIndex = [];

    public function __construct(
        private readonly DockerClient    $dockerClient,
        private readonly RedisClient     $redisClient,
        private readonly WebSocketServer $wsServer,
        private readonly string          $mcDataPath,
        private readonly string          $dockerApiUrl,
    ) {
        $this->loop    = Loop::get();
        $this->browser = new ReactBrowser($this->loop);
        $this->output  = new NullOutput();
    }

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function start(): void
    {
        $this->scan();
    }

    public function scan(): void
    {
        if (!is_dir($this->mcDataPath)) {
            return;
        }

        $found = [];

        foreach (new \DirectoryIterator($this->mcDataPath) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }
            if (!$this->isMinecraftDataFolder($entry->getPathname())) {
                continue;
            }

            $name    = $entry->getFilename();
            $found[] = $name;
            $this->syncServer($name);
        }

        foreach (array_keys($this->servers) as $name) {
            if (!in_array($name, $found, true)) {
                $this->removeServer($name);
            }
        }
    }

    public function refreshStats(): void
    {
        foreach ($this->servers as $name => $server) {
            if (empty($server['containerId'])) {
                continue;
            }
            try {
                $stats = $this->dockerClient->getContainerStats($server['containerId']);
                $this->redisClient->setStats($name, $stats);
            } catch (\RuntimeException) {
                // Container may have stopped
            }
        }
    }

    private function syncServer(string $name): void
    {
        $container = $this->findContainer($name);
        $existing  = $this->servers[$name] ?? null;

        $serverData = [
            'name'            => $name,
            'containerId'     => $container['Id'] ?? null,
            'containerName'   => isset($container['Names'][0])
                                    ? ltrim($container['Names'][0], '/')
                                    : null,
            'containerStatus' => $container['State'] ?? null,
            'port'            => $this->resolvePort($container),
            'startedAt'       => $this->resolveStartedAt($container),
            'running'         => ($container['State'] ?? '') === 'running',
        ];

        $this->servers[$name] = $serverData;
        $this->redisClient->setServer($name, $serverData);

        if ($serverData['running'] && $serverData['containerId']) {
            $prevStartedAt = $existing['startedAt'] ?? null;
            if ($prevStartedAt !== $serverData['startedAt']) {
                $this->openLogStream($name, $serverData);
            }
        } elseif (!$serverData['running']) {
            $this->closeLogStream($name);
            $this->redisClient->setLoadedUuids($name, []);
            $this->updatePlayerCount($name, 0);
        }

        $this->wsServer->broadcastServerUpdate($name);
    }

    private function openLogStream(string $name, array $server): void
    {
        $this->packNameIndex[$name] = $this->buildPackNameIndex($name);
        $this->closeLogStream($name);

        $containerId = $server['containerId'];
        $startedAt   = $server['startedAt'];

        $this->output->writeln("<info>Opening log stream for $name (container: $containerId, since: $startedAt)</info>");

        $this->redisClient->setLoadedUuids($name, []);
        $this->updatePlayerCount($name, 0);
        $this->lineBuffers[$name] = '';

        $url = $this->dockerApiUrl
             . '/v1.41/containers/' . $containerId
             . '/logs?stdout=1&stderr=0&follow=1&since=' . $startedAt;

        $testUrl = $this->dockerApiUrl . '/version';
        $this->output->writeln("<comment>Testing connectivity to: $testUrl</comment>");
        $this->browser->get($testUrl)
            ->then(
                function ($response) use ($name, $url) {
                    $this->output->writeln("<info>Connectivity OK, opening stream...</info>");
                    $this->browser->requestStreaming('GET', $url)
                        ->then(
                            function (ResponseInterface $response) use ($name) {
                                $this->output->writeln("<info>Log stream opened for $name</info>");
                                $body = $response->getBody();

                                $body->on('data', function (string $chunk) use ($name) {
                                    $this->processLogChunk($name, $chunk);
                                });

                                $body->on('close', function () use ($name) {
                                    $this->output->writeln("<comment>Log stream closed for $name, rescanning...</comment>");
                                    unset($this->streams[$name]);
                                    $this->loop->addTimer(3, fn() => $this->syncServer($name));
                                });

                                $this->streams[$name] = $body;
                            },
                            function (\Exception $e) use ($name) {
                                $this->output->writeln("<error>STREAM ERROR for $name: " . get_class($e) . ': ' . $e->getMessage() . "</error>");
                                $this->loop->addTimer(5, fn() => $this->syncServer($name));
                            }
                        );
                },
                function (\Exception $e) use ($name) {
                    $this->output->writeln("<error>Connectivity FAILED: " . $e->getMessage() . "</error>");
                    $this->loop->addTimer(5, fn() => $this->syncServer($name));
                }
            );
    }

    private function closeLogStream(string $name): void
    {
        if (isset($this->streams[$name])) {
            $this->streams[$name]->close();
            unset($this->streams[$name]);
        }
        unset($this->lineBuffers[$name]);
    }

    private function processLogChunk(string $serverName, string $data): void
    {
        $data = $this->stripDockerFrameHeaders($data);
        $data = str_replace("\r", '', $data);

        $buffer = ($this->lineBuffers[$serverName] ?? '') . $data;
        $lines  = explode("\n", $buffer);

        $this->lineBuffers[$serverName] = array_pop($lines);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match(self::PACK_STACK_REGEX, $line, $m)) {
                $uuid    = trim($m[1]);
                $current = $this->redisClient->getLoadedUuids($serverName);

                // Find the pack name for this UUID, then mark all packs with that name as loaded
                $uuidsToMark = [$uuid];
                foreach ($this->packNameIndex[$serverName] ?? [] as $packName => $uuids) {
                    if (in_array($uuid, $uuids, true)) {
                        $uuidsToMark = array_unique(array_merge($uuidsToMark, $uuids));
                        break;
                    }
                }

                $changed = false;
                foreach ($uuidsToMark as $u) {
                    if (!in_array($u, $current, true)) {
                        $current[] = $u;
                        $changed   = true;
                    }
                }

                if ($changed) {
                    $this->redisClient->setLoadedUuids($serverName, $current);
                    $this->wsServer->broadcastServerUpdate($serverName);
                    $this->output->writeln("<info>[$serverName] Pack loaded: $uuid</info>");
                }
                continue;
            }

            if (preg_match(self::PLAYER_JOIN_REGEX, $line, $m)) {
                $this->updatePlayerCount($serverName, ($this->playerCounts[$serverName] ?? 0) + 1);
                $this->output->writeln("<comment>[$serverName] Player joined: {$m[1]}</comment>");
                continue;
            }

            if (preg_match(self::PLAYER_LEAVE_REGEX, $line, $m)) {
                $count = max(0, ($this->playerCounts[$serverName] ?? 0) - 1);
                $this->updatePlayerCount($serverName, $count);
                $this->output->writeln("<comment>[$serverName] Player left: {$m[1]}</comment>");
                continue;
            }
        }
    }

    /**
     * Docker log streams use a multiplexed frame format when the container
     * doesn't have a TTY. Each frame starts with an 8-byte header:
     *   Byte 0:   stream type (1 = stdout, 2 = stderr)
     *   Bytes 1-3: zero padding
     *   Bytes 4-7: frame payload size (big-endian uint32)
     */
    private function stripDockerFrameHeaders(string $data): string
    {
        $output = '';
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            if ($offset + 8 > $length) {
                $output .= substr($data, $offset);
                break;
            }

            $streamType = ord($data[$offset]);

            if ($streamType !== 1 && $streamType !== 2) {
                $output .= substr($data, $offset);
                break;
            }

            $size = unpack('N', substr($data, $offset + 4, 4))[1];
            $offset += 8;

            if ($size > 0) {
                $output .= substr($data, $offset, $size);
                $offset += $size;
            }
        }

        return $output;
    }

    private function updatePlayerCount(string $serverName, int $count): void
    {
        $this->playerCounts[$serverName] = $count;
        $this->redisClient->setPlayerCount($serverName, $count);
        $this->wsServer->broadcastServerUpdate($serverName);
    }

    private function removeServer(string $name): void
    {
        $this->closeLogStream($name);
        unset($this->servers[$name]);
        $this->output->writeln("<comment>Server removed: $name</comment>");
    }

    private function findContainer(string $serverName): ?array
    {
        $hostPath = $this->dockerClient->resolveHostPath($this->mcDataPath . '/' . $serverName);
        if (!$hostPath) {
            return null;
        }

        foreach ($this->dockerClient->listMinecraftContainers() as $container) {
            $inspect = $this->dockerClient->inspectContainer($container['Id']);
            foreach ($inspect['Mounts'] ?? [] as $mount) {
                if ($mount['Destination'] === '/data' && $mount['Source'] === $hostPath) {
                    return $container;
                }
            }
        }

        return null;
    }

    private function resolveStartedAt(?array $container): ?int
    {
        if ($container === null) {
            return null;
        }
        $inspect   = $this->dockerClient->inspectContainer($container['Id']);
        $startedAt = $inspect['State']['StartedAt'] ?? null;
        return $startedAt ? (new \DateTimeImmutable($startedAt))->getTimestamp() : null;
    }

    private function resolvePort(?array $container): ?int
    {
        if ($container === null) {
            return null;
        }
        foreach ($container['Ports'] ?? [] as $port) {
            if (($port['PrivatePort'] ?? null) === 19132) {
                return $port['PublicPort'] ?? null;
            }
        }
        return null;
    }

    private function isMinecraftDataFolder(string $path): bool
    {
        return is_dir($path . '/worlds') || file_exists($path . '/server.properties');
    }

    private function buildPackNameIndex(string $serverName): array
    {
        $index    = [];
        $dataPath = $this->mcDataPath . '/' . $serverName;

        foreach (['/behavior_packs', '/resource_packs'] as $subDir) {
            $dir = $dataPath . $subDir;
            if (!is_dir($dir)) {
                continue;
            }
            foreach (new \DirectoryIterator($dir) as $entry) {
                if (!$entry->isDir() || $entry->isDot()) {
                    continue;
                }
                $manifestPath = $entry->getPathname() . '/manifest.json';
                if (!file_exists($manifestPath)) {
                    continue;
                }
                $data = json_decode(file_get_contents($manifestPath), true);
                $uuid = $data['header']['uuid'] ?? null;
                $name = trim(preg_replace('/§./', '', $data['header']['name'] ?? ''));
                if ($uuid && $name) {
                    $index[$name][] = $uuid;
                }
            }
        }
        $this->output->writeln("<comment>[$serverName] Pack name index built: " . count($index) . " entries</comment>");
        return $index;
    }
}
