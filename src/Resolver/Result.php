<?php

namespace Utopia\Proxy\Resolver;

/**
 * Result of resource resolution
 */
class Result
{
    /**
     * @param  string  $endpoint  Backend endpoint in format "host:port"
     * @param  array<string, mixed>  $metadata  Optional metadata about the resolved backend
     * @param  int|null  $timeout  Optional connection timeout override in seconds
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly array $metadata = [],
        public readonly ?int $timeout = null
    ) {
    }
}
