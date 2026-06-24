<?php

namespace App\Server;

use App\Service\RedisClient;
use App\Service\ServerStateBuilder;
use App\Service\VoteManager;
use Psr\Log\LoggerInterface;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class WebSocketServer implements MessageComponentInterface
{
    /** @var \SplObjectStorage<ConnectionInterface, array> */
    private \SplObjectStorage $clients;

    private OutputInterface $output;

    public function __construct(
        private readonly RedisClient        $redisClient,
        private readonly VoteManager        $voteManager,
        private readonly ServerStateBuilder $serverStateBuilder,
        #[Autowire(service: 'monolog.logger.websocket')]
        private readonly LoggerInterface    $logger,
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
        $this->logger->info('WebSocket client connected', ['resourceId' => $conn->resourceId ?? null, 'total' => count($this->clients)]);

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
        $this->logger->info('WebSocket client disconnected', ['resourceId' => $conn->resourceId ?? null, 'remaining' => count($this->clients)]);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $this->output->writeln('<error>WebSocket error: ' . $e->getMessage() . '</error>');
        $this->logger->error('WebSocket error', ['error' => $e->getMessage(), 'resourceId' => $conn->resourceId ?? null]);
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
        return $this->serverStateBuilder->buildAll();
    }

    private function buildSingleServerState(string $name): array
    {
        return $this->serverStateBuilder->buildOne($name);
    }

    private function resolveStopCountdownUntil(string $name, ?array $server): ?int
    {
        // Kept for interface compatibility — delegated in ServerStateBuilder
        return null;
    }
}
