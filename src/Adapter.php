<?php

namespace Utopia\Proxy;

use Swoole\Table;

/**
 * Protocol Proxy Adapter
 *
 * Base class for protocol-specific proxy implementations.
 * Focuses on routing and forwarding traffic - NOT container orchestration.
 *
 * Responsibilities:
 * - Route incoming requests to backend endpoints
 * - Cache routing decisions for performance (optional)
 * - Provide connection statistics
 * - Execute lifecycle hooks
 *
 * Non-responsibilities (handled by application layer):
 * - Backend endpoint resolution (provided via resolve hook)
 * - Container cold-starts and lifecycle management
 * - Health checking and orchestration
 * - Business logic (authentication, authorization, etc.)
 */
abstract class Adapter
{
    protected Table $routingTable;

    /** @var array<string, int> Connection pool stats */
    protected array $stats = [
        'connections' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'routing_errors' => 0,
    ];

    /** @var array<string, array<callable>> Registered hooks */
    protected array $hooks = [
        'resolve' => [],
        'beforeRoute' => [],
        'afterRoute' => [],
        'onRoutingError' => [],
    ];

    public function __construct()
    {
        $this->initRoutingTable();
    }

    /**
     * Register a hook callback
     *
     * Available hooks:
     * - resolve: Called to resolve backend endpoint, receives ($resourceId), returns string endpoint
     * - beforeRoute: Called before routing logic, receives ($resourceId)
     * - afterRoute: Called after routing, receives ($resourceId, $endpoint)
     * - onRoutingError: Called on routing errors, receives ($resourceId, $exception)
     *
     * @param string $name Hook name
     * @param callable $callback Callback function
     * @return $this
     */
    public function hook(string $name, callable $callback): static
    {
        if (!isset($this->hooks[$name])) {
            throw new \InvalidArgumentException("Unknown hook: {$name}");
        }

        // For resolve hook, only allow one callback
        if ($name === 'resolve' && !empty($this->hooks['resolve'])) {
            throw new \InvalidArgumentException("Only one resolve hook can be registered");
        }

        $this->hooks[$name][] = $callback;
        return $this;
    }

    /**
     * Execute registered hooks
     *
     * @param string $name Hook name
     * @param mixed ...$args Arguments to pass to callbacks
     * @return void
     */
    protected function executeHooks(string $name, mixed ...$args): void
    {
        foreach ($this->hooks[$name] ?? [] as $callback) {
            $callback(...$args);
        }
    }

    /**
     * Get adapter name
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Get protocol type
     *
     * @return string
     */
    abstract public function getProtocol(): string;

    /**
     * Get adapter description
     *
     * @return string
     */
    abstract public function getDescription(): string;

    /**
     * Get backend endpoint for a resource identifier
     *
     * First tries the resolve hook if registered, otherwise falls back to
     * the protocol-specific implementation.
     *
     * @param string $resourceId Protocol-specific identifier (hostname, connection string, etc.)
     * @return string Backend endpoint (host:port or IP:port)
     * @throws \Exception If resource not found or backend unavailable
     */
    protected function getBackendEndpoint(string $resourceId): string
    {
        // If resolve hook is registered, use it
        if (!empty($this->hooks['resolve'])) {
            $resolver = $this->hooks['resolve'][0];
            $endpoint = $resolver($resourceId);

            if (empty($endpoint)) {
                throw new \Exception("Resolve hook returned empty endpoint for: {$resourceId}");
            }

            return $endpoint;
        }

        // Otherwise use the default implementation (if provided by subclass)
        return $this->resolveBackend($resourceId);
    }

    /**
     * Default backend resolution (not implemented - hook required)
     *
     * Applications MUST register a resolve hook to provide backend endpoints.
     * There is no default implementation.
     *
     * @param string $resourceId Protocol-specific identifier
     * @return string Backend endpoint
     * @throws \Exception Always - resolve hook is required
     */
    protected function resolveBackend(string $resourceId): string
    {
        throw new \Exception(
            "No resolve hook registered. You must register a resolve hook to provide backend endpoints:\n" .
            "\$adapter->hook('resolve', fn(\$resourceId) => \$backendEndpoint);"
        );
    }

    /**
     * Initialize Swoole shared memory table for routing cache
     *
     * 100k entries = ~10MB memory, O(1) lookups
     */
    protected function initRoutingTable(): void
    {
        $this->routingTable = new Table(100_000);
        $this->routingTable->column('endpoint', Table::TYPE_STRING, 64);
        $this->routingTable->column('updated', Table::TYPE_INT, 8);
        $this->routingTable->create();
    }

    /**
     * Route connection to backend
     *
     * Performance: <1ms for cache hit, <10ms for cache miss
     *
     * @param string $resourceId Protocol-specific identifier
     * @return ConnectionResult Backend endpoint and metadata
     * @throws \Exception If routing fails
     */
    public function route(string $resourceId): ConnectionResult
    {
        $startTime = microtime(true);

        // Execute beforeRoute hooks
        $this->executeHooks('beforeRoute', $resourceId);

        // Check routing cache first (O(1) lookup)
        $cached = $this->routingTable->get($resourceId);
        if ($cached && (\time() - $cached['updated']) < 1) {
            $this->stats['cache_hits']++;
            $this->stats['connections']++;

            $result = new ConnectionResult(
                endpoint: $cached['endpoint'],
                protocol: $this->getProtocol(),
                metadata: [
                    'cached' => true,
                    'latency_ms' => \round((\microtime(true) - $startTime) * 1000, 2),
                ]
            );

            // Execute afterRoute hooks
            $this->executeHooks('afterRoute', $resourceId, $cached['endpoint'], $result);

            return $result;
        }

        $this->stats['cache_misses']++;

        try {
            // Get backend endpoint from protocol-specific logic
            $endpoint = $this->getBackendEndpoint($resourceId);

            // Update routing cache
            $this->routingTable->set($resourceId, [
                'endpoint' => $endpoint,
                'updated' => \time(),
            ]);

            $this->stats['connections']++;

            $result = new ConnectionResult(
                endpoint: $endpoint,
                protocol: $this->getProtocol(),
                metadata: [
                    'cached' => false,
                    'latency_ms' => \round((\microtime(true) - $startTime) * 1000, 2),
                ]
            );

            // Execute afterRoute hooks
            $this->executeHooks('afterRoute', $resourceId, $endpoint, $result);

            return $result;
        } catch (\Exception $e) {
            $this->stats['routing_errors']++;

            // Execute error hooks
            $this->executeHooks('onRoutingError', $resourceId, $e);

            throw $e;
        }
    }

    /**
     * Get routing and connection stats for monitoring
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $totalRequests = $this->stats['cache_hits'] + $this->stats['cache_misses'];

        return [
            'adapter' => $this->getName(),
            'protocol' => $this->getProtocol(),
            'connections' => $this->stats['connections'],
            'cache_hits' => $this->stats['cache_hits'],
            'cache_misses' => $this->stats['cache_misses'],
            'cache_hit_rate' => $totalRequests > 0
                ? \round($this->stats['cache_hits'] / $totalRequests * 100, 2)
                : 0,
            'routing_errors' => $this->stats['routing_errors'],
            'routing_table_memory' => $this->routingTable->memorySize,
            'routing_table_size' => $this->routingTable->count(),
        ];
    }
}
