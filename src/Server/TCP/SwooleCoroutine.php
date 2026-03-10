<?php

namespace Utopia\Proxy\Server\TCP;

use Swoole\Coroutine;
use Swoole\Coroutine\Server as CoroutineServer;
use Swoole\Coroutine\Server\Connection;
use Utopia\Proxy\Adapter\TCP\Swoole as TCPAdapter;
use Utopia\Proxy\Resolver;

/**
 * High-performance TCP proxy server (Swoole Coroutine Implementation)
 *
 * Example:
 * ```php
 * $resolver = new MyDatabaseResolver();
 * $config = new Config(host: '0.0.0.0', ports: [5432, 3306]);
 * $server = new SwooleCoroutine($resolver, $config);
 * $server->start();
 * ```
 */
class SwooleCoroutine
{
    /** @var array<int, CoroutineServer> */
    protected array $servers = [];

    /** @var array<int, TCPAdapter> */
    protected array $adapters = [];

    protected Config $config;

    public function __construct(
        protected Resolver $resolver,
        ?Config $config = null,
    ) {
        $this->config = $config ?? new Config();

        $this->initAdapters();
        $this->configureServers();
    }

    protected function initAdapters(): void
    {
        foreach ($this->config->ports as $port) {
            $adapter = new TCPAdapter($this->resolver, port: $port);

            if ($this->config->skipValidation) {
                $adapter->setSkipValidation(true);
            }

            $adapter->setConnectTimeout($this->config->backendConnectTimeout);

            $this->adapters[$port] = $adapter;
        }
    }

    protected function configureServers(): void
    {
        // Global coroutine settings
        Coroutine::set([
            'max_coroutine' => $this->config->maxCoroutine,
            'socket_buffer_size' => $this->config->socketBufferSize,
            'log_level' => $this->config->logLevel,
        ]);

        foreach ($this->config->ports as $port) {
            $server = new CoroutineServer($this->config->host, $port, false, $this->config->enableReusePort);

            // Only socket-protocol settings are applicable to Coroutine\Server
            $server->set([
                'open_tcp_nodelay' => true,
                'open_tcp_keepalive' => true,
                'tcp_keepidle' => $this->config->tcpKeepidle,
                'tcp_keepinterval' => $this->config->tcpKeepinterval,
                'tcp_keepcount' => $this->config->tcpKeepcount,
                'open_length_check' => false,
                'package_max_length' => $this->config->packageMaxLength,
                'buffer_output_size' => $this->config->bufferOutputSize,
            ]);

            // Coroutine\Server::start() already spawns a coroutine per connection
            $server->handle(function (Connection $connection) use ($port): void {
                $this->handleConnection($connection, $port);
            });

            $this->servers[$port] = $server;
        }
    }

    public function onStart(): void
    {
        echo "TCP Proxy Server started at {$this->config->host}\n";
        echo 'Ports: '.implode(', ', $this->config->ports)."\n";
        echo "Workers: {$this->config->workers}\n";
        echo "Max connections: {$this->config->maxConnections}\n";
    }

    public function onWorkerStart(int $workerId = 0): void
    {
        echo "Worker #{$workerId} started\n";
    }

    protected function handleConnection(Connection $connection, int $port): void
    {
        $clientSocket = $connection->exportSocket();
        $clientId = spl_object_id($connection);
        $adapter = $this->adapters[$port];
        $bufferSize = $this->config->recvBufferSize;

        if ($this->config->logConnections) {
            echo "Client #{$clientId} connected to port {$port}\n";
        }

        // Wait for first packet to establish backend connection
        $data = $clientSocket->recv($bufferSize);
        if ($data === false || $data === '') {
            $clientSocket->close();

            return;
        }

        try {
            $databaseId = $adapter->parseDatabaseId($data, $clientId);
            $backendClient = $adapter->getBackendConnection($databaseId, $clientId);
            $backendSocket = $backendClient->exportSocket();

            // Start backend -> client forwarding in separate coroutine
            Coroutine::create(function () use ($clientSocket, $backendSocket, $bufferSize): void {
                while (true) {
                    $data = $backendSocket->recv($bufferSize);
                    if ($data === false || $data === '') {
                        break;
                    }
                    if ($clientSocket->sendAll($data) === false) {
                        break;
                    }
                }
                $clientSocket->close();
            });

            // Forward initial packet
            $backendSocket->sendAll($data);
        } catch (\Exception $e) {
            echo "Error handling data from #{$clientId}: {$e->getMessage()}\n";
            $clientSocket->close();

            return;
        }

        // Client -> backend forwarding in current coroutine
        while (true) {
            $data = $clientSocket->recv($bufferSize);
            if ($data === false || $data === '') {
                break;
            }
            $backendSocket->sendAll($data);
        }

        $backendSocket->close();
        $adapter->closeBackendConnection($databaseId, $clientId);

        if ($this->config->logConnections) {
            echo "Client #{$clientId} disconnected\n";
        }
    }

    public function start(): void
    {
        $runner = function (): void {
            $this->onStart();
            $this->onWorkerStart(0);

            foreach ($this->servers as $server) {
                Coroutine::create(function () use ($server): void {
                    $server->start();
                });
            }
        };

        if (Coroutine::getCid() > 0) {
            $runner();

            return;
        }

        \Swoole\Coroutine\run($runner);
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $adapterStats = [];
        foreach ($this->adapters as $port => $adapter) {
            $adapterStats[$port] = $adapter->getStats();
        }

        /** @var array<string, mixed> $coroutineStats */
        $coroutineStats = Coroutine::stats();

        return [
            'connections' => 0,
            'workers' => 1,
            'coroutines' => $coroutineStats['coroutine_num'] ?? 0,
            'adapters' => $adapterStats,
        ];
    }
}
