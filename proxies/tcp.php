<?php

require __DIR__.'/../vendor/autoload.php';

use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\Result;
use Utopia\Proxy\Server\TCP\Swoole as TCPServer;
use Utopia\Proxy\Server\TCP\SwooleCoroutine as TCPCoroutineServer;
use Utopia\Proxy\Server\TCP\Config as TCPConfig;

/**
 * TCP Proxy Server Example (PostgreSQL + MySQL)
 *
 * Performance: 100k+ conn/s, 10GB/s throughput
 *
 * Usage:
 *   php examples/tcp.php
 *
 * Test PostgreSQL:
 *   psql -h localhost -p 5432 -U postgres -d db-abc123
 *
 * Test MySQL:
 *   mysql -h localhost -P 3306 -u root -D db-abc123
 */
$serverImpl = strtolower(getenv('TCP_SERVER_IMPL') ?: 'swoole');
if (! in_array($serverImpl, ['swoole', 'coroutine', 'coro'], true)) {
    $serverImpl = 'swoole';
}
if ($serverImpl === 'coro') {
    $serverImpl = 'coroutine';
}

$envInt = static function (string $key, int $default): int {
    $value = getenv($key);

    return $value === false ? $default : (int) $value;
};

$workers = $envInt('TCP_WORKERS', swoole_cpu_num() * 2);
$reactorNum = $envInt('TCP_REACTOR_NUM', swoole_cpu_num() * 2);
$dispatchMode = $envInt('TCP_DISPATCH_MODE', 2);

$backendEndpoint = getenv('TCP_BACKEND_ENDPOINT') ?: 'tcp-backend:15432';
$skipValidation = filter_var(getenv('TCP_SKIP_VALIDATION') ?: 'false', FILTER_VALIDATE_BOOLEAN);

// Create a simple resolver that returns the configured backend endpoint
$resolver = new class ($backendEndpoint) implements Resolver {
    public function __construct(private string $endpoint)
    {
    }

    public function resolve(string $resourceId): Result
    {
        return new Result(endpoint: $this->endpoint);
    }

    public function onConnect(string $resourceId, array $metadata = []): void
    {
    }

    public function onDisconnect(string $resourceId, array $metadata = []): void
    {
    }

    public function trackActivity(string $resourceId, array $metadata = []): void
    {
    }

    public function invalidateCache(string $resourceId): void
    {
    }

    public function getStats(): array
    {
        return ['resolver' => 'static', 'endpoint' => $this->endpoint];
    }
};

$postgresPort = $envInt('TCP_POSTGRES_PORT', 5432);
$mysqlPort = $envInt('TCP_MYSQL_PORT', 3306);
$ports = array_values(array_filter([$postgresPort, $mysqlPort], static fn (int $port): bool => $port > 0));
if ($ports === []) {
    $ports = [5432, 3306];
}

$config = new TCPConfig(
    host: '0.0.0.0',
    ports: $ports,
    workers: $workers,
    reactorNum: $reactorNum,
    dispatchMode: $dispatchMode,
    skipValidation: $skipValidation,
);

echo "Starting TCP Proxy Server...\n";
echo "Host: {$config->host}\n";
echo 'Ports: '.implode(', ', $config->ports)."\n";
echo "Workers: {$config->workers}\n";
echo "Max connections: {$config->maxConnections}\n";
echo "Server impl: {$serverImpl}\n";
echo "\n";

$serverClass = $serverImpl === 'swoole' ? TCPServer::class : TCPCoroutineServer::class;
$server = new $serverClass($resolver, $config);

$server->start();
