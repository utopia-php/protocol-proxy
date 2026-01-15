<?php

namespace Utopia\Proxy;

use Swoole\Table;
use Utopia\Platform\Action;
use Utopia\Platform\Service;

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
 * - Execute lifecycle actions
 *
 * Non-responsibilities (handled by application layer):
 * - Backend endpoint resolution (provided via resolve action)
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

    protected ?Service $service = null;

    /** @var bool Skip validation for trusted backends */
    protected bool $skipValidation = false;

    /** @var callable|null Cached resolve callback */
    protected $resolveCallback = null;

    public function __construct(?Service $service = null)
    {
        $this->service = $service ?? $this->defaultService();
        $this->initRoutingTable();
    }

    /**
     * Provide a default service for the adapter.
     *
     * @return Service|null
     */
    protected function defaultService(): ?Service
    {
        return null;
    }

    /**
     * Set action service
     *
     * @param Service $service
     * @return $this
     */
    public function setService(Service $service): static
    {
        $this->service = $service;

        return $this;
    }

    /**
     * Get action service
     *
     * @return Service|null
     */
    public function getService(): ?Service
    {
        return $this->service;
    }

    /**
     * Enable fast routing mode (skip SSRF validation for trusted backends)
     *
     * Only use this when you control the backend endpoint resolution
     * and trust that it returns safe endpoints.
     *
     * @param bool $skip
     * @return $this
     */
    public function setSkipValidation(bool $skip): static
    {
        $this->skipValidation = $skip;
        return $this;
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
     * Uses the resolve action registered on the action service.
     *
     * @param string $resourceId Protocol-specific identifier (hostname, connection string, etc.)
     * @return string Backend endpoint (host:port or IP:port)
     * @throws \Exception If resource not found or backend unavailable
     */
    protected function getBackendEndpoint(string $resourceId): string
    {
        $resolver = $this->getActionCallback($this->getResolveAction());
        $endpoint = $resolver($resourceId);

        if (empty($endpoint)) {
            throw new \Exception("Resolve action returned empty endpoint for: {$resourceId}");
        }

        // Validate the resolved endpoint to prevent SSRF
        $this->validateEndpoint($endpoint);

        return $endpoint;
    }

    /**
     * Validate backend endpoint to prevent SSRF attacks
     *
     * @param string $endpoint
     * @return void
     * @throws \Exception If endpoint is invalid or points to restricted address
     */
    protected function validateEndpoint(string $endpoint): void
    {
        // Parse host and port
        $parts = explode(':', $endpoint);
        if (count($parts) < 1 || count($parts) > 2) {
            throw new \Exception("Invalid endpoint format: {$endpoint}");
        }

        $host = $parts[0];
        $port = isset($parts[1]) ? (int)$parts[1] : 0;

        // Validate port range (if specified)
        if ($port > 0 && ($port < 1 || $port > 65535)) {
            throw new \Exception("Invalid port number: {$port}");
        }

        // Resolve hostname to IP
        $ip = gethostbyname($host);
        if ($ip === $host && !filter_var($ip, FILTER_VALIDATE_IP)) {
            // DNS resolution failed and it's not a valid IP
            throw new \Exception("Cannot resolve hostname: {$host}");
        }

        // Check for private/reserved IP ranges (SSRF protection)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $longIp = ip2long($ip);
            if ($longIp === false) {
                throw new \Exception("Invalid IP address: {$ip}");
            }

            // Block private and reserved ranges
            $blockedRanges = [
                ['10.0.0.0', '10.255.255.255'],          // Private: 10.0.0.0/8
                ['172.16.0.0', '172.31.255.255'],        // Private: 172.16.0.0/12
                ['192.168.0.0', '192.168.255.255'],      // Private: 192.168.0.0/16
                ['127.0.0.0', '127.255.255.255'],        // Loopback: 127.0.0.0/8
                ['169.254.0.0', '169.254.255.255'],      // Link-local: 169.254.0.0/16
                ['224.0.0.0', '239.255.255.255'],        // Multicast: 224.0.0.0/4
                ['240.0.0.0', '255.255.255.255'],        // Reserved: 240.0.0.0/4
                ['0.0.0.0', '0.255.255.255'],            // Current network: 0.0.0.0/8
            ];

            foreach ($blockedRanges as [$rangeStart, $rangeEnd]) {
                $rangeStartLong = ip2long($rangeStart);
                $rangeEndLong = ip2long($rangeEnd);
                if ($longIp >= $rangeStartLong && $longIp <= $rangeEndLong) {
                    throw new \Exception("Access to private/reserved IP address is forbidden: {$ip}");
                }
            }
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Block IPv6 loopback and link-local
            if ($ip === '::1' || strpos($ip, 'fe80:') === 0 || strpos($ip, 'fc00:') === 0 || strpos($ip, 'fd00:') === 0) {
                throw new \Exception("Access to private/reserved IPv6 address is forbidden: {$ip}");
            }
        }
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
        // Fast path: check cache first (O(1) lookup)
        $cached = $this->routingTable->get($resourceId);
        $now = \time();

        if ($cached && ($now - $cached['updated']) < 1) {
            $this->stats['cache_hits']++;
            $this->stats['connections']++;

            return new ConnectionResult(
                endpoint: $cached['endpoint'],
                protocol: $this->getProtocol(),
                metadata: ['cached' => true]
            );
        }

        $this->stats['cache_misses']++;

        try {
            // Get backend endpoint - use cached callback for speed
            $endpoint = $this->getBackendEndpointFast($resourceId);

            // Update routing cache
            $this->routingTable->set($resourceId, [
                'endpoint' => $endpoint,
                'updated' => $now,
            ]);

            $this->stats['connections']++;

            return new ConnectionResult(
                endpoint: $endpoint,
                protocol: $this->getProtocol(),
                metadata: ['cached' => false]
            );
        } catch (\Exception $e) {
            $this->stats['routing_errors']++;
            throw $e;
        }
    }

    /**
     * Fast endpoint resolution with cached callback
     *
     * @param string $resourceId
     * @return string
     * @throws \Exception
     */
    protected function getBackendEndpointFast(string $resourceId): string
    {
        // Cache the resolve callback
        if ($this->resolveCallback === null) {
            $this->resolveCallback = $this->getActionCallback($this->getResolveAction());
        }

        $endpoint = ($this->resolveCallback)($resourceId);

        if (empty($endpoint)) {
            throw new \Exception("Resolve action returned empty endpoint for: {$resourceId}");
        }

        // Skip validation if configured (for trusted backends)
        if (!$this->skipValidation) {
            $this->validateEndpoint($endpoint);
        }

        return $endpoint;
    }

    /**
     * Get the resolve action
     *
     * @return Action
     * @throws \Exception
     */
    protected function getResolveAction(): Action
    {
        $service = $this->service;
        if ($service === null) {
            throw new \Exception(
                "No action service registered. You must register a resolve action:\n" .
                "\$service->addAction('resolve', (new class extends \\Utopia\\Platform\\Action {})\n" .
                "    ->callback(fn(\$resourceId) => \$backendEndpoint));"
            );
        }

        $action = $this->getServiceAction($service, 'resolve');
        if ($action === null) {
            throw new \Exception(
                "No resolve action registered. You must register a resolve action:\n" .
                "\$service->addAction('resolve', (new class extends \\Utopia\\Platform\\Action {})\n" .
                "    ->callback(fn(\$resourceId) => \$backendEndpoint));"
            );
        }

        return $action;
    }

    /**
     * Execute actions by type.
     *
     * @param string $type
     * @param mixed ...$args
     * @return void
     */
    protected function executeActions(string $type, mixed ...$args): void
    {
        if ($this->service === null) {
            return;
        }

        foreach ($this->getServiceActions($this->service) as $action) {
            if ($action->getType() !== $type) {
                continue;
            }

            $callback = $this->getActionCallback($action);
            $callback(...$args);
        }
    }

    /**
     * Resolve action callback.
     *
     * @param Action $action
     * @return callable
     */
    protected function getActionCallback(Action $action): callable
    {
        $callback = $action->getCallback();
        if (!\is_callable($callback)) {
            throw new \InvalidArgumentException('Action callback must be callable.');
        }

        return $callback;
    }

    /**
     * Safely read actions from the service.
     *
     * @param Service $service
     * @return array<string, Action>
     */
    protected function getServiceActions(Service $service): array
    {
        try {
            return $service->getActions();
        } catch (\Error) {
            return [];
        }
    }

    /**
     * Safely read a single action from the service.
     *
     * @param Service $service
     * @param string $key
     * @return Action|null
     */
    protected function getServiceAction(Service $service, string $key): ?Action
    {
        try {
            return $service->getAction($key);
        } catch (\Error) {
            return null;
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
