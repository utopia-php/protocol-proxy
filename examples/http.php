<?php

require __DIR__.'/../vendor/autoload.php';

use Utopia\Proxy\Resolver\Fixed;
use Utopia\Proxy\Server\HTTP\Config as HTTPConfig;
use Utopia\Proxy\Server\HTTP\Swoole as HTTPServer;
use Utopia\Proxy\Server\HTTP\Swoole\Coroutine as HTTPCoroutineServer;

/**
 * HTTP Proxy Server Example
 *
 * Performance: 250k+ req/s
 *
 * Usage:
 *   php examples/http.php
 *
 * Test:
 *   ab -n 100000 -c 1000 http://localhost:8080/
 */
$envInt = static function (string $key, int $default): int {
    $value = getenv($key);

    return $value === false ? $default : (int) $value;
};

$envBool = static function (string $key, bool $default): bool {
    $value = getenv($key);

    return $value === false ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
};

$serverImpl = strtolower(getenv('HTTP_SERVER_IMPL') ?: 'swoole');
if (! in_array($serverImpl, ['swoole', 'coroutine', 'coro'], true)) {
    $serverImpl = 'swoole';
}
if ($serverImpl === 'coro') {
    $serverImpl = 'coroutine';
}

$backendEndpoint = getenv('HTTP_BACKEND_ENDPOINT') ?: 'http-backend:5678';

$resolver = new Fixed($backendEndpoint);

$fixedBackend = getenv('HTTP_FIXED_BACKEND') ?: null;
$directResponse = getenv('HTTP_DIRECT_RESPONSE') ?: null;

$config = new HTTPConfig(
    port: $envInt('HTTP_PORT', 8080),
    workers: $envInt('HTTP_WORKERS', swoole_cpu_num() * 2),
    reactorNum: $envInt('HTTP_REACTOR_NUM', swoole_cpu_num() * 2),
    serverMode: strtolower(getenv('HTTP_SERVER_MODE') ?: 'process') === 'base' ? SWOOLE_BASE : SWOOLE_PROCESS,
    poolSize: $envInt('HTTP_BACKEND_POOL_SIZE', 2048),
    keepaliveTimeout: $envInt('HTTP_KEEPALIVE_TIMEOUT', 60),
    http2Protocol: $envBool('HTTP_OPEN_HTTP2', false),
    skipValidation: $envBool('HTTP_SKIP_VALIDATION', false),
    fastPath: $envBool('HTTP_FAST_PATH', true),
    fastPathAssumeOk: $envBool('HTTP_FAST_ASSUME_OK', false),
    fixedBackend: $fixedBackend ?: null,
    directResponse: $directResponse ?: null,
    directResponseStatus: $envInt('HTTP_DIRECT_RESPONSE_STATUS', 200),
    rawBackend: $envBool('HTTP_RAW_BACKEND', false),
    rawBackendAssumeOk: $envBool('HTTP_RAW_BACKEND_ASSUME_OK', false),
);

echo "Starting HTTP Proxy Server...\n";
echo "Port: {$config->port}\n";
echo "Workers: {$config->workers}\n";
echo "Impl: {$serverImpl}\n";
echo "\n";

$serverClass = $serverImpl === 'swoole' ? HTTPServer::class : HTTPCoroutineServer::class;
(new $serverClass($resolver, $config))->start();
