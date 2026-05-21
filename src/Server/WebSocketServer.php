<?php

namespace App\Server;

use App\Service\RedisClient;
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
        $this->output->writeln('<info>WebSocket client connected: ' . $conn->resourceId . '</info>');

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
        $this->output->writeln('<info>WebSocket client disconnected: ' . $conn->resourceId . '</info>');
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
        if ($gamertag) {
            $this->redisClient->setHeartbeat($gamertag);
        }
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
        return [
            'server'      => $this->redisClient->getServer($name),
            'playerCount' => $this->redisClient->getPlayerCount($name),
            'loadedUuids' => $this->redisClient->getLoadedUuids($name),
            'stats'       => $this->redisClient->getStats($name),
        ];
    }
}
