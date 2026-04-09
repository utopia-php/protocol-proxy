<?php

require __DIR__.'/../vendor/autoload.php';

use Utopia\Proxy\Resolver\Fixed;
use Utopia\Proxy\Server\SMTP\Config as SMTPConfig;
use Utopia\Proxy\Server\SMTP\Swoole as SMTPServer;

/**
 * SMTP Proxy Server Example
 *
 * Performance: 50k+ messages/sec
 *
 * Usage:
 *   php examples/smtp.php
 *
 * Test:
 *   telnet localhost 25
 *   EHLO test.com
 *   MAIL FROM:<sender@test.com>
 *   RCPT TO:<recipient@test.com>
 *   DATA
 *   Subject: Test
 *
 *   Hello World
 *   .
 *   QUIT
 */
$backendEndpoint = getenv('SMTP_BACKEND_ENDPOINT') ?: 'smtp-backend:1025';
$skipValidation = filter_var(getenv('SMTP_SKIP_VALIDATION') ?: 'false', FILTER_VALIDATE_BOOLEAN);

$resolver = new Fixed($backendEndpoint);

$config = new SMTPConfig(
    port: (int) (getenv('SMTP_PORT') ?: 25),
    workers: (int) (getenv('SMTP_WORKERS') ?: swoole_cpu_num() * 2),
    skipValidation: $skipValidation,
);

echo "Starting SMTP Proxy Server...\n";
echo "Port: {$config->port}\n";
echo "Workers: {$config->workers}\n";
echo "\n";

$server = new SMTPServer($resolver, $config);
$server->start();
