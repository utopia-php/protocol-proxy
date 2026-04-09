<?php

require __DIR__.'/../vendor/autoload.php';

use Utopia\Proxy\Resolver\Fixed;
use Utopia\Proxy\Server\TCP\Config as TCPConfig;
use Utopia\Proxy\Server\TCP\Swoole as TCPServer;
use Utopia\Proxy\Server\TCP\Swoole\Coroutine as TCPCoroutineServer;
use Utopia\Proxy\Server\TCP\TLS;

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
 *
 * TLS environment variables:
 *   PROXY_TLS_ENABLED=true             Enable TLS termination
 *   PROXY_TLS_CERT=/certs/server.crt   Path to TLS certificate
 *   PROXY_TLS_KEY=/certs/server.key    Path to TLS private key
 *   PROXY_TLS_CA=/certs/ca.crt         Path to CA certificate (for mTLS)
 *   PROXY_TLS_REQUIRE_CLIENT_CERT=true Require client certificates (mTLS)
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

$envBool = static function (string $key, bool $default): bool {
    $value = getenv($key);

    return $value === false ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
};

$workers = $envInt('TCP_WORKERS', swoole_cpu_num());
$reactorNum = $envInt('TCP_REACTOR_NUM', swoole_cpu_num());
$serverModeName = strtolower(getenv('TCP_SERVER_MODE') ?: 'base');
$serverMode = $serverModeName === 'process' ? SWOOLE_PROCESS : SWOOLE_BASE;

$backendEndpoint = getenv('TCP_BACKEND_ENDPOINT') ?: 'tcp-backend:15432';
$skipValidation = $envBool('TCP_SKIP_VALIDATION', false);

// TLS configuration from environment variables
$tlsEnabled = $envBool('PROXY_TLS_ENABLED', false);
$tlsCert = getenv('PROXY_TLS_CERT') ?: '';
$tlsKey = getenv('PROXY_TLS_KEY') ?: '';
$tlsCa = getenv('PROXY_TLS_CA') ?: '';
$tlsRequireClientCert = $envBool('PROXY_TLS_REQUIRE_CLIENT_CERT', false);

$tls = null;
if ($tlsEnabled) {
    if ($tlsCert === '' || $tlsKey === '') {
        echo "ERROR: PROXY_TLS_ENABLED=true but PROXY_TLS_CERT and PROXY_TLS_KEY are required\n";
        exit(1);
    }

    $tls = new TLS(
        certificate: $tlsCert,
        key: $tlsKey,
        ca: $tlsCa,
        requireClientCert: $tlsRequireClientCert,
    );
}

$resolver = new Fixed($backendEndpoint);

$postgresPort = $envInt('TCP_POSTGRES_PORT', 5432);
$mysqlPort = $envInt('TCP_MYSQL_PORT', 3306);
$ports = array_values(array_filter([$postgresPort, $mysqlPort], static fn (int $port): bool => $port > 0));
if ($ports === []) {
    $ports = [5432, 3306];
}

$sockmapEnabled = $envBool('TCP_SOCKMAP_ENABLED', false);
$sockmapBpfObject = getenv('TCP_SOCKMAP_BPF_OBJECT') ?: '';

$config = new TCPConfig(
    host: '0.0.0.0',
    ports: $ports,
    workers: $workers,
    reactorNum: $reactorNum,
    serverMode: $serverMode,
    skipValidation: $skipValidation,
    sockmapEnabled: $sockmapEnabled,
    sockmapBpfObject: $sockmapBpfObject,
    tls: $tls,
);

echo "Starting TCP Proxy Server...\n";
echo "Host: {$config->host}\n";
echo 'Ports: '.implode(', ', $config->ports)."\n";
echo "Workers: {$config->workers}\n";
echo "Max connections: {$config->maxConnections}\n";
echo "Server impl: {$serverImpl}\n";
if ($tls !== null) {
    echo "TLS: enabled (certificate: {$tls->certificate})\n";
    if ($tls->isMutual()) {
        echo "mTLS: enabled (ca: {$tls->ca})\n";
    }
}
echo "\n";

$serverClass = $serverImpl === 'swoole' ? TCPServer::class : TCPCoroutineServer::class;
$server = new $serverClass($resolver, $config);

$server->start();
