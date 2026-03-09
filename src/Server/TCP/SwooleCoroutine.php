<?php

namespace Utopia\Proxy\Server\TCP;

use Swoole\Coroutine;
use Swoole\Coroutine\Client;
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
 * $server = new SwooleCoroutine($resolver, host: '0.0.0.0', ports: [5432, 3306]);
 * $server->start();
 * ```
 */
class SwooleCoroutine
{
    /** @var array<int, CoroutineServer> */
    protected array $servers = [];

    /** @var array<int, TCPAdapter> */
    protected array $adapters = [];

    /** @var array<int, CoroutineServer> */
    protected array $servers = [];

    /** @var array<int, TCPAdapter> */
    protected array $adapters = [];

    protected SwooleCoroutineConfig $config;

    public function __construct(
        protected Resolver $resolver,
        ?SwooleCoroutineConfig $config = null,
    ) {
        $this->config = $config ?? new SwooleCoroutineConfig();

        $this->initAdapters();
        $this->configureServers();
    }

    protected function initAdapters(): void
    {
        foreach ($this->ports as $port) {
            $adapter = new TCPAdapter($this->resolver, port: $port);

            // Apply skip_validation config if set
            if (! empty($this->config['skip_validation'])) {
                $adapter->setSkipValidation(true);
            }

            // Apply backend connection timeout
            if (isset($this->config['backend_connect_timeout'])) {
                /** @var float $timeout */
                $timeout = $this->config['backend_connect_timeout'];
                $adapter->setConnectTimeout($timeout);
            }

            $this->adapters[$port] = $adapter;
        }
    }

    protected function configureServers(string $host): void
    {
        foreach ($this->ports as $port) {
            $server = new CoroutineServer($host, $port, false, (bool) $this->config['enable_reuse_port']);
            $server->set([
                'worker_num' => $this->config['workers'],
                'reactor_num' => $this->config['reactor_num'],
                'max_connection' => $this->config['max_connections'],
                'max_coroutine' => $this->config['max_coroutine'],
                'socket_buffer_size' => $this->config['socket_buffer_size'],
                'buffer_output_size' => $this->config['buffer_output_size'],
                'enable_coroutine' => $this->config['enable_coroutine'],
                'max_wait_time' => $this->config['max_wait_time'],
                'log_level' => $this->config['log_level'],
                'dispatch_mode' => $this->config['dispatch_mode'],
                'enable_reuse_port' => $this->config['enable_reuse_port'],
                'backlog' => $this->config['backlog'],

                // TCP performance tuning
                'open_tcp_nodelay' => true,
                'tcp_fastopen' => true,
                'open_cpu_affinity' => true,
                'tcp_defer_accept' => 5,
                'open_tcp_keepalive' => true,
                'tcp_keepidle' => $this->config['tcp_keepidle'],
                'tcp_keepinterval' => $this->config['tcp_keepinterval'],
                'tcp_keepcount' => $this->config['tcp_keepcount'],

                // Package settings for database protocols
                'open_length_check' => false, // Let database handle framing
                'package_max_length' => $this->config['package_max_length'],

                // Enable stats
                'task_enable_coroutine' => true,
            ]);

            $server->handle(function (Connection $connection) use ($port): void {
                $this->handleConnection($connection, $port);
            });

            $this->servers[$port] = $server;
        }
    }

    public function onStart(): void
    {
        /** @var string $host */
        $host = $this->config['host'];
        /** @var int $workers */
        $workers = $this->config['workers'];
        /** @var int $maxConnections */
        $maxConnections = $this->config['max_connections'];
        echo "TCP Proxy Server started at {$host}\n";
        echo 'Ports: '.implode(', ', $this->ports)."\n";
        echo "Workers: {$workers}\n";
        echo "Max connections: {$maxConnections}\n";
    }

    public function onWorkerStart(int $workerId = 0): void
    {
        echo "Worker #{$workerId} started\n";
    }

    protected function handleConnection(Connection $connection, int $port): void
    {
        $clientId = spl_object_id($connection);
        $adapter = $this->adapters[$port];

        if (! empty($this->config['log_connections'])) {
            echo "Client #{$clientId} connected to port {$port}\n";
        }

        $backendClient = null;
        $databaseId = null;

        // Wait for first packet to establish backend connection
        $data = $connection->recv();
        if (! is_string($data) || $data === '') {
            $connection->close();

            return;
        }

        try {
            $databaseId = $adapter->parseDatabaseId($data, $clientId);
            $backendClient = $adapter->getBackendConnection($databaseId, $clientId);
            $this->startForwarding($connection, $backendClient);
            $backendClient->send($data);
        } catch (\Exception $e) {
            echo "Error handling data from #{$clientId}: {$e->getMessage()}\n";
            $connection->close();

            return;
        }

        // Fast path: forward subsequent packets directly
        while (true) {
            $data = $connection->recv();
            if ($data === '' || $data === false) {
                break;
            }
            $backendClient->send($data);
        }

        $backendClient->close();
        $adapter->closeBackendConnection($databaseId, $clientId);
        $connection->close();

        if (! empty($this->config['log_connections'])) {
            echo "Client #{$clientId} disconnected\n";
        }
    }

    protected function startForwarding(Connection $connection, Client $backendClient): void
    {
        $bufferSize = $this->recvBufferSize;

        Coroutine::create(function () use ($connection, $backendClient, $bufferSize): void {
            // Forward backend -> client with larger buffer for fewer syscalls
            while ($backendClient->isConnected()) {
                $data = $backendClient->recv($bufferSize);
                if ($data === false || $data === '') {
                    break;
                }

                /** @var string $dataStr */
                $dataStr = $data;
                if ($connection->send($dataStr) === false) {
                    break;
                }
            }

            $connection->close();
        });
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
