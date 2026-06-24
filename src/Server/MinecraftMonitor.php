<?php

namespace App\Server;

use App\Service\DockerClient;
use App\Service\RedisClient;
use App\Service\ServerContainerManager;
use App\Service\VoteManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Http\Browser as ReactBrowser;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class MinecraftMonitor
{
    private const PLAYER_JOIN_REGEX  = '/Player connected:\s+([^,]+),\s+xuid:\s*(\d+)/i';
    private const PLAYER_LEAVE_REGEX = '/Player disconnected:\s+([^,]+),\s+xuid:\s*(\d+)/i';
    private const PACK_STACK_REGEX   = '/Pack Stack\s+-\s+\[\d+]\s+.+\(id:\s*([a-f0-9-]+),/i';

    /** @var array<string, array> Known servers */
    private array $servers = [];

    /** @var array<string, \React\Stream\ReadableStreamInterface> Open stream bodies per server */
    private array $streams = [];

    /** @var array<string, int> Current player counts per server */
    private array $playerCounts = [];

    /** @var array<string, string> Incomplete line buffer per server */
    private array $lineBuffers = [];

    /** @var array<string, array<string, string[]>> Pack name → UUIDs index per server */
    private array $packNameIndex = [];

    /** @var TimerInterface|null Active start countdown timer */
    private ?TimerInterface $countdownTimer = null;

    /** @var string|null Server name currently in start countdown */
    private ?string $countdownServer = null;

    /** @var array<string, TimerInterface> Active stop countdown timers keyed by server name */
    private array $stopCountdownTimers = [];

    private LoopInterface   $loop;
    private ReactBrowser    $browser;
    private OutputInterface $output;

    public function __construct(
        private readonly DockerClient    $dockerClient,
        private readonly RedisClient     $redisClient,
        private readonly ServerContainerManager $serverContainerManager,
        private readonly WebSocketServer $wsServer,
        private readonly VoteManager     $voteManager,
        private readonly string          $mcDataPath,
        private readonly string          $dockerApiUrl,
        #[Autowire(service: 'monolog.logger.server')]
        private readonly LoggerInterface $logger,
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
        $this->dockerClient->clearInspectCache();

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

        $this->evaluateCountdown();
    }

    public function refreshStats(): void
    {
        foreach ($this->servers as $name => $server) {
            if (empty($server['containerId'])) {
                continue;
            }

            if (!($server['running'] ?? false)) {
                continue;
            }

            // Refresh player count TTL so it doesn't expire between log events
            $this->redisClient->setPlayerCount($name, $this->playerCounts[$name] ?? 0);

            try {
                $stats = $this->dockerClient->getContainerStats($server['containerId']);
                $this->redisClient->setStats($name, $stats);
                $this->wsServer->broadcastServerUpdate($name);
            } catch (\RuntimeException) {
                // Container may have stopped
            }
        }
    }

    // ── Countdown management ──────────────────────────────────────────────────

    /**
     * Evaluates whether a countdown should start, continue, or be cancelled.
     * Called after any state change that could affect vote conditions.
     */
    public function evaluateCountdown(): void
    {
        $candidate = $this->voteManager->checkAndTrigger();

        if ($candidate === null) {
            // No server qualifies for start — cancel any active start countdown
            if ($this->countdownServer !== null) {
                $this->output->writeln("<comment>Countdown cancelled for {$this->countdownServer}</comment>");
                $this->logger->info('Start countdown cancelled', ['server' => $this->countdownServer]);
                $this->cancelCountdown();
            }

            // Check if we need to auto-stop servers to free resources
            $this->evaluateAutoStop();
            return;
        }

        // Cancel any pending auto-stop countdowns — not needed if start can proceed
        $this->cancelAllStopCountdowns();

        if ($this->countdownServer === $candidate) {
            return;
        }

        if ($this->countdownServer !== null) {
            $this->output->writeln("<comment>Countdown switched from {$this->countdownServer} to {$candidate}</comment>");
            $this->logger->info('Start countdown switched', ['from' => $this->countdownServer, 'to' => $candidate]);
            $this->cancelCountdown();
        }

        $this->startCountdown($candidate);
    }

    private function evaluateAutoStop(): void
    {
        $toStop = $this->voteManager->getServersToAutoStop();

        if (empty($toStop)) {
            // Nothing to stop — cancel any existing stop countdowns for servers
            // that no longer need stopping
            foreach (array_keys($this->stopCountdownTimers) as $name) {
                if (!in_array($name, $toStop, true)) {
                    $this->cancelStopCountdown($name);
                }
            }
            return;
        }

        foreach ($toStop as $serverName) {
            if (isset($this->stopCountdownTimers[$serverName])) {
                continue; // Already counting down
            }
            $this->startStopCountdown($serverName);
        }

        // Cancel stop countdowns for servers no longer in the list
        foreach (array_keys($this->stopCountdownTimers) as $name) {
            if (!in_array($name, $toStop, true)) {
                $this->cancelStopCountdown($name);
            }
        }
    }

    private function startCountdown(string $serverName): void
    {
        $this->output->writeln("<info>Starting " . RedisClient::COUNTDOWN_TTL . "s countdown for $serverName</info>");
        $this->logger->info('Start countdown begun', ['server' => $serverName, 'ttl' => RedisClient::COUNTDOWN_TTL]);

        $this->countdownServer = $serverName;

        // Calculate timer duration based on whether countdown is already set
        $existingCountdown = $this->redisClient->getCountdown($serverName);
        if ($existingCountdown === null) {
            // First time setting the countdown
            $this->redisClient->setCountdown($serverName);
            $timerDuration = RedisClient::COUNTDOWN_TTL;
        } else {
            // Countdown was already set (e.g., by VoteController)
            // Calculate remaining time to fire the timer appropriately
            $elapsed = time() - $existingCountdown;
            $timerDuration = max(1, RedisClient::COUNTDOWN_TTL - $elapsed);
            $this->logger->debug('Countdown already active, scheduling timer for remaining time', [
                'server' => $serverName,
                'elapsed' => $elapsed,
                'remaining' => $timerDuration,
            ]);
        }

        $this->wsServer->broadcastServerUpdate($serverName);

        $this->countdownTimer = $this->loop->addTimer(
            $timerDuration,
            function () use ($serverName) {
                $this->fireCountdown($serverName);
            }
        );
    }

    private function cancelCountdown(): void
    {
        if ($this->countdownTimer !== null) {
            $this->loop->cancelTimer($this->countdownTimer);
            $this->countdownTimer = null;
        }

        if ($this->countdownServer !== null) {
            $this->redisClient->clearCountdown($this->countdownServer);
            $this->wsServer->broadcastServerUpdate($this->countdownServer);
            $this->countdownServer = null;
        }
    }

    private function startStopCountdown(string $serverName): void
    {
        $this->output->writeln("<info>Starting " . RedisClient::COUNTDOWN_TTL . "s stop countdown for $serverName</info>");
        $this->logger->info('Stop countdown begun (auto-stop)', ['server' => $serverName, 'ttl' => RedisClient::COUNTDOWN_TTL]);

        $this->redisClient->setStopCountdown($serverName);
        $this->wsServer->broadcastServerUpdate($serverName);

        $this->stopCountdownTimers[$serverName] = $this->loop->addTimer(
            RedisClient::COUNTDOWN_TTL,
            function () use ($serverName) {
                $this->fireStopCountdown($serverName);
            }
        );
    }

    private function cancelStopCountdown(string $serverName): void
    {
        if (isset($this->stopCountdownTimers[$serverName])) {
            $this->loop->cancelTimer($this->stopCountdownTimers[$serverName]);
            unset($this->stopCountdownTimers[$serverName]);
        }

        $this->redisClient->clearStopCountdown($serverName);
        $this->wsServer->broadcastServerUpdate($serverName);
        $this->output->writeln("<comment>Stop countdown cancelled for $serverName</comment>");
        $this->logger->info('Stop countdown cancelled', ['server' => $serverName]);
    }

    private function cancelAllStopCountdowns(): void
    {
        foreach (array_keys($this->stopCountdownTimers) as $name) {
            $this->cancelStopCountdown($name);
        }
    }

    private function fireStopCountdown(string $serverName): void
    {
        unset($this->stopCountdownTimers[$serverName]);

        $this->output->writeln("<info>Stop countdown fired for $serverName</info>");
        $this->logger->info('Stop countdown fired', ['server' => $serverName]);

        $data        = $this->redisClient->getServer($serverName);
        $containerId = $data['containerId'] ?? null;

        if ($containerId === null) {
            $this->output->writeln("<error>No containerId for $serverName — cannot stop</error>");
            $this->logger->error('Auto-stop failed: no containerId', ['server' => $serverName]);
            $this->redisClient->clearStopCountdown($serverName);
            return;
        }

        // Safety check — don't stop if players joined during countdown
        if ($this->redisClient->getPlayerCount($serverName) > 0) {
            $this->output->writeln("<comment>Players joined $serverName during stop countdown — aborting stop</comment>");
            $this->logger->warning('Auto-stop aborted: players joined during countdown', ['server' => $serverName]);
            $this->redisClient->clearStopCountdown($serverName);
            $this->wsServer->broadcastServerUpdate($serverName);
            return;
        }

        try {
            $this->output->writeln("<info>Auto-stopping $serverName to free resources</info>");
            $this->logger->info('Auto-stopping server to free resources', ['server' => $serverName, 'containerId' => $containerId]);
            $this->dockerClient->stopContainer($containerId);
            $this->voteManager->onServerAutoStopped($serverName);
            $this->wsServer->broadcastServerUpdate($serverName);
            // Re-evaluate after stop — may now trigger start countdown
            $this->loop->addTimer(2, fn() => $this->evaluateCountdown());
        } catch (\RuntimeException $e) {
            $this->output->writeln("<error>Failed to stop $serverName: {$e->getMessage()}</error>");
            $this->logger->error('Failed to auto-stop server', ['server' => $serverName, 'error' => $e->getMessage()]);
            $this->redisClient->clearStopCountdown($serverName);
        }
    }

    private function fireCountdown(string $serverName): void
    {
        $this->countdownTimer  = null;
        $this->countdownServer = null;

        $this->output->writeln("<info>Countdown fired for $serverName — validating...</info>");
        $this->logger->info('Start countdown fired, validating', ['server' => $serverName]);

        if (!$this->voteManager->confirmStart($serverName)) {
            $this->output->writeln("<comment>Countdown validation failed for $serverName — aborting start</comment>");
            $this->logger->warning('Start countdown validation failed — aborting', ['server' => $serverName]);
            $this->redisClient->clearCountdown($serverName);
            $this->wsServer->broadcastServerUpdate($serverName);
            $this->evaluateCountdown();
            return;
        }

        $data        = $this->redisClient->getServer($serverName) ?? [];
        $containerId = $data['containerId'] ?? null;
        $profile     = $data['memoryProfile'] ?? 'medium';

        try {
            if ($containerId === null) {
                $containerId = $this->serverContainerManager->ensureContainerExists(
                    $serverName,
                    $this->mcDataPath . '/' . $serverName,
                    $profile,
                );
                $this->logger->info('Auto-start created missing container before start', [
                    'server' => $serverName,
                    'containerId' => $containerId,
                    'profile' => $profile,
                ]);
            }

            $this->output->writeln("<info>Auto-starting $serverName</info>");
            $this->logger->info('Auto-starting server', ['server' => $serverName, 'containerId' => $containerId]);
            $this->dockerClient->startContainer($containerId);
            $this->voteManager->onServerStarted($serverName);
            $this->redisClient->setStarting($serverName);
            $this->wsServer->broadcastServerUpdate($serverName);
            $this->loop->addTimer(1, fn() => $this->syncServer($serverName));
        } catch (\RuntimeException $e) {
            $this->output->writeln("<error>Failed to start $serverName: {$e->getMessage()}</error>");
            $this->logger->error('Failed to auto-start server', ['server' => $serverName, 'error' => $e->getMessage()]);
            $this->redisClient->clearCountdown($serverName);
        }
    }

    // ── Server sync ───────────────────────────────────────────────────────────

    private function syncServer(string $name): void
    {
        $container = $this->findContainer($name);
        $existing  = $this->servers[$name] ?? null;

        $memoryProfile = 'medium';
        if ($container !== null) {
            $inspect       = $this->dockerClient->inspectContainer($container['Id']);
            $memoryProfile = $inspect !== null
                ? $this->dockerClient->getMemoryProfile($inspect)
                : 'medium';
        }

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
            'memoryProfile'   => $memoryProfile,
        ];

        $this->servers[$name] = $serverData;
        $this->redisClient->setServer($name, $serverData);

        if ($serverData['running'] && $serverData['containerId']) {
            $prevStartedAt = $existing['startedAt'] ?? null;
            if ($prevStartedAt !== $serverData['startedAt']) {
                $this->openLogStream($name, $serverData);
            }
        } elseif (!$serverData['running']) {
            $wasRunning = $existing !== null && ($existing['running'] ?? false);
            $this->closeLogStream($name);
            $this->redisClient->setLoadedUuids($name, []);
            $this->updatePlayerCount($name, 0);

            // Server just stopped — re-evaluate countdown
            if ($wasRunning) {
                $this->loop->addTimer(1, fn() => $this->evaluateCountdown());
            }
        }

        $this->wsServer->broadcastServerUpdate($name);
    }

    private function openLogStream(string $name, array $server): void
    {
        // Log stream opening confirms the server is running — clear starting state
        $this->redisClient->clearStarting($name);

        $this->packNameIndex[$name] = $this->buildPackNameIndex($name);
        $this->closeLogStream($name);

        $containerId = $server['containerId'];
        $startedAt   = $server['startedAt'];

        $this->output->writeln("<info>Opening log stream for $name (container: $containerId, since: $startedAt)</info>");
        $this->logger->info('Opening Docker log stream', ['server' => $name, 'containerId' => $containerId]);

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
                                $this->logger->info('Log stream opened', ['server' => $name]);
                                $body = $response->getBody();

                                $body->on('data', function (string $chunk) use ($name) {
                                    $this->processLogChunk($name, $chunk);
                                });

                                $body->on('close', function () use ($name) {
                                    $this->output->writeln("<comment>Log stream closed for $name, rescanning...</comment>");
                                    $this->logger->warning('Log stream closed unexpectedly, rescanning', ['server' => $name]);
                                    unset($this->streams[$name]);
                                    $this->loop->addTimer(3, fn() => $this->syncServer($name));
                                });

                                $this->streams[$name] = $body;
                            },
                            function (\Exception $e) use ($name) {
                                $this->output->writeln("<error>STREAM ERROR for $name: " . get_class($e) . ': ' . $e->getMessage() . "</error>");
                                $this->logger->error('Log stream error', ['server' => $name, 'error' => $e->getMessage(), 'class' => get_class($e)]);
                                $this->loop->addTimer(5, fn() => $this->syncServer($name));
                            }
                        );
                },
                function (\Exception $e) use ($name) {
                    $this->output->writeln("<error>Connectivity FAILED: " . $e->getMessage() . "</error>");
                    $this->logger->error('Docker API connectivity failed', ['server' => $name, 'error' => $e->getMessage()]);
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
                $this->logger->info('Player joined', ['server' => $serverName, 'player' => $m[1], 'xuid' => $m[2]]);
                // A player joining a running server may cancel a countdown
                $this->evaluateCountdown();
                continue;
            }

            if (preg_match(self::PLAYER_LEAVE_REGEX, $line, $m)) {
                $count = max(0, ($this->playerCounts[$serverName] ?? 0) - 1);
                $this->updatePlayerCount($serverName, $count);
                $this->output->writeln("<comment>[$serverName] Player left: {$m[1]}</comment>");
                $this->logger->info('Player left', ['server' => $serverName, 'player' => $m[1], 'xuid' => $m[2], 'remaining' => $count]);
                // A player leaving may allow a countdown to start
                $this->evaluateCountdown();
                continue;
            }
        }
    }

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
        $this->logger->info('Server removed from monitor', ['server' => $name]);
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

        // Fast path: running containers report ports in list response
        foreach ($container['Ports'] ?? [] as $port) {
            if (($port['PrivatePort'] ?? null) === 19132) {
                return $port['PublicPort'] ?? null;
            }
        }

        // Fallback: stopped containers have empty Ports but HostConfig still has bindings
        $inspect  = $this->dockerClient->inspectContainer($container['Id']);
        $bindings = $inspect['HostConfig']['PortBindings']['19132/udp'] ?? [];
        foreach ($bindings as $binding) {
            if (isset($binding['HostPort']) && $binding['HostPort'] !== '') {
                return (int) $binding['HostPort'];
            }
        }

        return null;
    }

    private function isMinecraftDataFolder(string $path): bool
    {
        return is_dir($path . '/worlds')
            || file_exists($path . '/server.properties')
            || file_exists($path . '/mc-server-manager/meta.yaml');
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
