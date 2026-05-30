<?php

namespace App\Server;

use App\Service\RedisClient;
use App\Service\ResourceBudgetChecker;
use App\Service\VoteManager;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class WebSocketServer implements MessageComponentInterface
{
    /** @var \SplObjectStorage<ConnectionInterface, array> */
    private \SplObjectStorage $clients;

    private OutputInterface $output;

    public function __construct(
        private readonly RedisClient $redisClient,
        private readonly VoteManager $voteManager,
        private readonly ResourceBudgetChecker $budgetChecker,
    ) {
        $this->clients = new \SplObjectStorage();
        $this->output  = new NullOutput();
    }

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);
        $this->output->writeln('<info>WebSocket client connected: ' . ($conn->resourceId ?? '?') . '</info>');

        $this->sendToConnection($conn, [
            'type'    => 'init',
            'servers' => $this->buildServerState(),
            'chat'    => $this->redisClient->getChatHistory(),
        ]);
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode($msg, true);
        if (!$data || !isset($data['type'])) {
            return;
        }

        match ($data['type']) {
            'chat'      => $this->handleChat($from, $data),
            'heartbeat' => $this->handleHeartbeat($from, $data),
            default     => null,
        };
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->clients->detach($conn);
        $this->output->writeln('<info>WebSocket client disconnected: ' . ($conn->resourceId ?? '?') . '</info>');
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $this->output->writeln('<error>WebSocket error: ' . $e->getMessage() . '</error>');
        $conn->close();
    }

    public function broadcastServerUpdate(string $serverName): void
    {
        $this->broadcast([
            'type'   => 'server_update',
            'server' => $serverName,
            'data'   => $this->buildSingleServerState($serverName),
        ]);
    }

    public function broadcastChat(array $message): void
    {
        $this->broadcast([
            'type'    => 'chat',
            'message' => $message,
        ]);
    }

    private function handleChat(ConnectionInterface $from, array $data): void
    {
        $text = trim($data['text'] ?? '');
        if ($text === '' || strlen($text) > 500) {
            return;
        }

        $message = [
            'gamertag'  => $data['gamertag'] ?? 'Anonymous',
            'text'      => $text,
            'timestamp' => time(),
        ];

        $this->redisClient->pushChatMessage($message);
        $this->broadcastChat($message);
    }

    private function handleHeartbeat(ConnectionInterface $from, array $data): void
    {
        $gamertag = $data['gamertag'] ?? null;
        if (!$gamertag) {
            return;
        }
        $this->redisClient->setHeartbeat($gamertag);
    }

    private function broadcast(array $data): void
    {
        $json = json_encode($data);
        foreach ($this->clients as $client) {
            $client->send($json);
        }
    }

    private function sendToConnection(ConnectionInterface $conn, array $data): void
    {
        $conn->send(json_encode($data));
    }

    private function buildServerState(): array
    {
        $state = [];
        foreach ($this->redisClient->getAllServerNames() as $name) {
            $state[$name] = $this->buildSingleServerState($name);
        }
        return $state;
    }

    private function buildSingleServerState(string $name): array
    {
        $server    = $this->redisClient->getServer($name);
        $countdown = $this->redisClient->getCountdown($name);

        return [
            'server'        => $server,
            'playerCount'   => $this->redisClient->getPlayerCount($name),
            'loadedUuids'   => $this->redisClient->getLoadedUuids($name),
            'stats'         => $this->redisClient->getStats($name),
            'memoryProfile' => $server['memoryProfile'] ?? 'medium',
            'votes'         => [
                'count'   => $this->voteManager->getActiveVoteCount($name),
                'voters'  => $this->voteManager->getActiveVoters($name),
            ],
            'countdownUntil' => $countdown !== null
                ? $countdown + RedisClient::COUNTDOWN_TTL
                : null,
            'blocked' => $this->getBlockingReason($name, $server),
        ];
    }

    private function getBlockingReason(string $name, ?array $server): ?string
    {
        // Only relevant for stopped servers
        if ($server['running'] ?? false) {
            return null;
        }

        // No votes — no point showing a blocking message
        if ($this->voteManager->getActiveVoteCount($name) === 0) {
            return null;
        }

        // Check players on any running server
        foreach ($this->redisClient->getAllServerNames() as $otherName) {
            if ($otherName === $name) {
                continue;
            }
            $other = $this->redisClient->getServer($otherName);
            if (($other['running'] ?? false) && $this->redisClient->getPlayerCount($otherName) > 0) {
                return 'players';
            }
        }

        // Check resource budget
        $profile = $server['memoryProfile'] ?? 'medium';
        if (!$this->budgetChecker->canStart($profile)) {
            return 'resources';
        }

        return null;
    }
}
