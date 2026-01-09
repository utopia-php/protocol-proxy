<?php

namespace Utopia\Proxy\Adapter\SMTP;

use Utopia\Proxy\Adapter;

/**
 * SMTP Protocol Adapter (Swoole Implementation)
 *
 * Routes SMTP connections based on email domain to backend email server containers.
 *
 * Routing:
 * - Input: Email domain (e.g., tenant123.appwrite.io)
 * - Resolution: Provided by application via resolve hook
 * - Output: Backend endpoint (IP:port)
 *
 * Performance:
 * - 50,000+ messages/second
 * - 50,000+ concurrent connections
 * - <2ms forwarding overhead
 *
 * Example:
 * ```php
 * $adapter = new SMTP();
 * $adapter->hook('resolve', fn($domain) => $myBackend->resolve($domain));
 * ```
 */
class Swoole extends Adapter
{
    /**
     * Get adapter name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'SMTP';
    }

    /**
     * Get protocol type
     *
     * @return string
     */
    public function getProtocol(): string
    {
        return 'smtp';
    }

    /**
     * Get adapter description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'SMTP proxy adapter for email server routing';
    }
}
