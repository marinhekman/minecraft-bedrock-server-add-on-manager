<?php

namespace App\Command;

use App\Server\MinecraftMonitor;
use App\Server\WebSocketServer;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:websocket-server',
    description: 'Starts the WebSocket server and Minecraft log monitor',
)]
class WebSocketServerCommand extends Command
{
    private const WS_PORT = 8082;

    public function __construct(
        private readonly MinecraftMonitor $monitor,
        private readonly WebSocketServer  $wsServer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $loop = Loop::get();

        $output->writeln('<info>Starting WebSocket server on port ' . self::WS_PORT . '</info>');

        // Wire WebSocket server onto the shared ReactPHP loop
        $socket = new \React\Socket\SocketServer('0.0.0.0:' . self::WS_PORT, [], $loop);
        $server = new IoServer(
            new HttpServer(new WsServer($this->wsServer)),
            $socket,
            $loop,
        );

        $this->monitor->setOutput($output);
        $this->wsServer->setOutput($output);

        $this->monitor->start();

        $loop->addPeriodicTimer(30, fn() => $this->monitor->scan());
        $loop->addPeriodicTimer(10, fn() => $this->monitor->refreshStats());

        $loop->run();

        return Command::SUCCESS;
    }
}
