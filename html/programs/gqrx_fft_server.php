z<?php
/**
 * GQRX FFT WebSocket Bridge
 * 
 * Listens to Gqrx UDP FFT data (default port 7355/7356) and pushes
 * it to all connected WebSocket clients.
 * 
 * Requirements:
 *   composer require cboden/ratchet
 * Run with:
 *   php gqrx_fft_server.php
 */

require __DIR__ . '/vendor/autoload.php';
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\Factory as LoopFactory;
use React\Datagram\Factory as DatagramFactory;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;


class FFTWebSocketServer implements MessageComponentInterface {
    protected $clients;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
       echo "WebSocket server ready.\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        // Clients don’t send data; ignore
           echo "Received WS message: " . strlen($msg) . " bytes\n";

    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} closed\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }

    public function broadcast($data) {
        foreach ($this->clients as $client) {
            $client->send($data);
        }
        echo "Broadcasted " . strlen($data) . " bytes to " . count($this->clients) . " clients\n";

    }
}

$loop = LoopFactory::create();
$wsServer = new FFTWebSocketServer();


// Create UDP listener for Gqrx FFT data
$datagram = new DatagramFactory($loop);
$datagram->createServer('0.0.0.0:7355')->then(function (\React\Datagram\Socket $server) use ($wsServer) {
    echo "Listening for Gqrx FFT on UDP :7355\n";
    $server->on('message', function($message) use ($wsServer) {
        // Gqrx FFT is binary float32 data; forward raw to browser
        $wsServer->broadcast($message);
    });
}, function (Exception $e) {
    echo "Unable to create UDP server: " . $e->getMessage() . "\n";

});

// Start WebSocket server on port 8080
$socketServer = new IoServer(
    new HttpServer(
        new WsServer($wsServer)
    ),
    new React\Socket\Server('0.0.0.0:8080', $loop)
);

echo "WebSocket server running on ws://0.0.0.0:8080\n";
$loop->run();
