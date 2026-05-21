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

        // WebSocket server for browser clients
        $server = IoServer::factory(
            new HttpServer(new WsServer($this->wsServer)),
            self::WS_PORT,
            '0.0.0.0',
            $loop
        );

        // Pass output to monitor (needed for writeln)
        $this->monitor->setOutput($output);
        $this->wsServer->setOutput($output);

        // Initial scan + start log streams
        $this->monitor->start();

        // Re-scan for new/removed servers every 30 seconds
        $loop->addPeriodicTimer(30, fn() => $this->monitor->scan());

        // Refresh container stats every 10 seconds
        $loop->addPeriodicTimer(10, fn() => $this->monitor->refreshStats());

        $loop->run();

        return Command::SUCCESS;
    }
}
