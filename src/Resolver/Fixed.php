<?php

namespace Utopia\Proxy\Resolver;

use Utopia\Proxy\Resolver;

/**
 * Fixed resolver that always returns the same backend endpoint.
 *
 * Used as the default resolver in the Docker image when no custom
 * resolver is mounted.
 */
class Fixed implements Resolver
{
    public function __construct(private readonly string $endpoint)
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

    public function track(string $resourceId, array $metadata = []): void
    {
    }

    public function purge(string $resourceId): void
    {
    }

    public function getStats(): array
    {
        return ['resolver' => 'fixed', 'endpoint' => $this->endpoint];
    }
}
