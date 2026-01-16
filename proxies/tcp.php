<?php

require __DIR__.'/../vendor/autoload.php';

use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\Result;
use Utopia\Proxy\Server\TCP\Swoole as TCPServer;
use Utopia\Proxy\Server\TCP\SwooleCoroutine as TCPCoroutineServer;

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

$config = [
    // Server settings
    'host' => '0.0.0.0',
    'workers' => $workers,

    // Performance tuning
    'max_connections' => 200_000,
    'max_coroutine' => 200_000,
    'socket_buffer_size' => 16 * 1024 * 1024, // 16MB for database traffic
    'buffer_output_size' => 16 * 1024 * 1024, // 16MB
    'log_level' => SWOOLE_LOG_ERROR,
    'reactor_num' => $reactorNum,
    'dispatch_mode' => $dispatchMode,
    'enable_reuse_port' => true,
    'backlog' => 65535,
    'package_max_length' => 32 * 1024 * 1024, // 32MB max query/result
    'tcp_keepidle' => 30,
    'tcp_keepinterval' => 10,
    'tcp_keepcount' => 3,

    // Cold-start settings
    'cold_start_timeout' => 30_000,
    'health_check_interval' => 100,

    // Backend services
    'compute_api_url' => getenv('COMPUTE_API_URL') ?: 'http://appwrite-api/v1/compute',
    'compute_api_key' => getenv('COMPUTE_API_KEY') ?: '',

    // Database connection
    'db_host' => getenv('DB_HOST') ?: 'localhost',
    'db_port' => (int) (getenv('DB_PORT') ?: 3306),
    'db_user' => getenv('DB_USER') ?: 'appwrite',
    'db_pass' => getenv('DB_PASS') ?: 'password',
    'db_name' => getenv('DB_NAME') ?: 'appwrite',

    // Redis cache
    'redis_host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'redis_port' => (int) (getenv('REDIS_PORT') ?: 6379),

    // Skip SSRF validation for trusted backends (e.g., Docker internal networks)
    'skip_validation' => $skipValidation,
];

$postgresPort = $envInt('TCP_POSTGRES_PORT', 5432);
$mysqlPort = $envInt('TCP_MYSQL_PORT', 3306);
$ports = array_values(array_filter([$postgresPort, $mysqlPort], static fn (int $port): bool => $port > 0)); // PostgreSQL, MySQL
if ($ports === []) {
    $ports = [5432, 3306];
}

echo "Starting TCP Proxy Server...\n";
echo "Host: {$config['host']}\n";
echo 'Ports: '.implode(', ', $ports)."\n";
echo "Workers: {$config['workers']}\n";
echo "Max connections: {$config['max_connections']}\n";
echo "Server impl: {$serverImpl}\n";
echo "\n";

$serverClass = $serverImpl === 'swoole' ? TCPServer::class : TCPCoroutineServer::class;
$server = new $serverClass(
    $resolver,
    $config['host'],
    $ports,
    $config['workers'],
    $config
);

$server->start();
