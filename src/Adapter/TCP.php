<?php

namespace Utopia\Proxy\Adapter;

use Swoole\Coroutine\Client;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\ConnectionResult;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\Exception as ResolverException;
use Utopia\Proxy\Resolver\ReadWriteResolver;
use Utopia\Query\Parser;
use Utopia\Query\Parser\MongoDB as MongoDBParser;
use Utopia\Query\Parser\MySQL as MySQLParser;
use Utopia\Query\Parser\PostgreSQL as PostgreSQLParser;
use Utopia\Query\Type as QueryType;

/**
 * TCP Protocol Adapter
 *
 * Routes TCP connections to backend endpoints resolved by the provided Resolver.
 * The resolver receives the raw initial packet data and is responsible for
 * extracting any routing information it needs.
 *
 * Supports optional read/write split routing via QueryParser and ReadWriteResolver.
 *
 * Performance (validated on 8-core/32GB):
 * - 670k+ concurrent connections
 * - 18k connections/sec establishment rate
 * - ~33KB memory per connection
 * - Minimal-copy forwarding (128KB buffers, no payload parsing)
 *
 * Example:
 * ```php
 * $adapter = new TCP($resolver, port: 5432);
 * $adapter->setReadWriteSplit(true);
 * ```
 */
class TCP extends Adapter
{
    /** @var array<int, Client> */
    protected array $backendConnections = [];

    /** @var float Backend connection timeout in seconds */
    protected float $connectTimeout = 5.0;

    /** @var bool Whether read/write split routing is enabled */
    protected bool $readWriteSplit = false;

    /** @var Parser|null Lazy-initialized query parser */
    protected ?Parser $queryParser = null;

    /**
     * Per-connection transaction pinning state.
     * When a connection is in a transaction, all queries are routed to primary.
     *
     * @var array<int, bool>
     */
    protected array $pinnedConnections = [];

    public function __construct(
        ?Resolver $resolver = null,
        public int $port = 5432 {
            get {
                return $this->port;
            }
        }
    ) {
        parent::__construct($resolver);
    }

    /**
     * Set backend connection timeout
     */
    public function setConnectTimeout(float $timeout): static
    {
        $this->connectTimeout = $timeout;

        return $this;
    }

    /**
     * Enable or disable read/write split routing
     *
     * When enabled, the adapter inspects each data packet to classify queries
     * and route reads to replicas and writes to the primary.
     * Requires the resolver to implement ReadWriteResolver for full functionality.
     * Falls back to normal resolve() if the resolver does not implement it.
     */
    public function setReadWriteSplit(bool $enabled): static
    {
        $this->readWriteSplit = $enabled;

        return $this;
    }

    /**
     * Check if read/write split is enabled
     */
    public function isReadWriteSplit(): bool
    {
        return $this->readWriteSplit;
    }

    /**
     * Check if a connection is pinned to primary (in a transaction)
     */
    public function isConnectionPinned(int $clientFd): bool
    {
        return $this->pinnedConnections[$clientFd] ?? false;
    }

    /**
     * Get adapter name
     */
    public function getName(): string
    {
        return 'TCP';
    }

    /**
     * Get protocol type
     */
    public function getProtocol(): Protocol
    {
        return match ($this->port) {
            5432 => Protocol::PostgreSQL,
            27017 => Protocol::MongoDB,
            3306 => Protocol::MySQL,
            default => throw new \Exception('Unsupported protocol on port: ' . $this->port),
        };
    }

    /**
     * Get adapter description
     */
    public function getDescription(): string
    {
        return 'TCP proxy adapter';
    }

    /**
     * Classify a data packet for read/write routing
     *
     * Determines whether a query packet should be routed to a read replica
     * or the primary writer. Handles transaction pinning automatically.
     *
     * @param  string  $data  Raw protocol data packet
     * @param  int  $clientFd  Client file descriptor for transaction tracking
     * @return QueryType QueryType::Read or QueryType::Write
     */
    public function classifyQuery(string $data, int $clientFd): QueryType
    {
        if (!$this->readWriteSplit) {
            return QueryType::Write;
        }

        // If connection is pinned to primary (in transaction), everything goes to primary
        if ($this->isConnectionPinned($clientFd)) {
            $classification = $this->getQueryParser()->parse($data);

            // Transaction end unpins
            if ($classification === QueryType::TransactionEnd) {
                unset($this->pinnedConnections[$clientFd]);
            }

            return QueryType::Write;
        }

        $classification = $this->getQueryParser()->parse($data);

        // Transaction begin pins to primary
        if ($classification === QueryType::TransactionBegin) {
            $this->pinnedConnections[$clientFd] = true;

            return QueryType::Write;
        }

        // Other transaction commands and unknown go to primary for safety
        if ($classification === QueryType::Transaction
            || $classification === QueryType::TransactionEnd
            || $classification === QueryType::Unknown
        ) {
            return QueryType::Write;
        }

        return $classification;
    }

    /**
     * Route a query to the appropriate backend (read replica or primary)
     *
     * @param  string  $resourceId  Resource identifier
     * @param  QueryType  $queryType  QueryType::Read or QueryType::Write
     * @return ConnectionResult Resolved backend endpoint
     *
     * @throws ResolverException
     */
    public function routeQuery(string $resourceId, QueryType $queryType): ConnectionResult
    {
        // If read/write split is disabled or resolver doesn't support it, use default routing
        if (!$this->readWriteSplit || !($this->resolver instanceof ReadWriteResolver)) {
            return $this->route($resourceId);
        }

        if ($queryType === QueryType::Read) {
            return $this->routeRead($resourceId);
        }

        return $this->routeWrite($resourceId);
    }

    /**
     * Clear transaction pinning state for a connection
     *
     * Should be called when a client disconnects to clean up state.
     */
    public function clearConnectionState(int $clientFd): void
    {
        unset($this->pinnedConnections[$clientFd]);
    }

    /**
     * Get or create backend connection for a client.
     *
     * On first call for a given fd, routes via the resolver and establishes the
     * backend connection. Subsequent calls return the cached connection.
     *
     * @param  string  $initialData  Raw initial packet data (used for routing on first call only)
     * @param  int  $clientFd  Client file descriptor
     *
     * @throws \Exception
     */
    public function getBackendConnection(string $initialData, int $clientFd): Client
    {
        if (isset($this->backendConnections[$clientFd])) {
            return $this->backendConnections[$clientFd];
        }

        $result = $this->route($initialData);

        [$host, $port] = \explode(':', $result->endpoint.':'.$this->port);
        $port = (int) $port;

        $client = new Client(SWOOLE_SOCK_TCP);

        $client->set([
            'timeout' => $this->connectTimeout,
            'connect_timeout' => $this->connectTimeout,
            'open_tcp_nodelay' => true,
            'socket_buffer_size' => 2 * 1024 * 1024,
        ]);

        if (!$client->connect($host, $port, $this->connectTimeout)) {
            throw new \Exception("Failed to connect to backend: {$host}:{$port}");
        }

        $this->backendConnections[$clientFd] = $client;

        return $client;
    }

    /**
     * Close backend connection for a client
     */
    public function closeBackendConnection(int $clientFd): void
    {
        if (isset($this->backendConnections[$clientFd])) {
            $this->backendConnections[$clientFd]->close();
            unset($this->backendConnections[$clientFd]);
        }
    }

    /**
     * Get or create the query parser instance (lazy initialization)
     */
    protected function getQueryParser(): Parser
    {
        if ($this->queryParser === null) {
            $this->queryParser = match ($this->getProtocol()) {
                Protocol::PostgreSQL => new PostgreSQLParser(),
                Protocol::MySQL => new MySQLParser(),
                Protocol::MongoDB => new MongoDBParser(),
                default => throw new \Exception('No query parser for protocol: ' . $this->getProtocol()->value),
            };
        }

        return $this->queryParser;
    }

    /**
     * Route to a read replica backend
     *
     * @throws ResolverException
     */
    protected function routeRead(string $resourceId): ConnectionResult
    {
        /** @var ReadWriteResolver $resolver */
        $resolver = $this->resolver;

        try {
            $result = $resolver->resolveRead($resourceId);
            $endpoint = $result->endpoint;

            if (empty($endpoint)) {
                throw new ResolverException(
                    "Resolver returned empty read endpoint for: {$resourceId}",
                    ResolverException::NOT_FOUND
                );
            }

            if (!$this->skipValidation) {
                $this->validateEndpoint($endpoint);
            }

            return new ConnectionResult(
                endpoint: $endpoint,
                protocol: $this->getProtocol(),
                metadata: \array_merge(['cached' => false, 'route' => 'read'], $result->metadata)
            );
        } catch (\Exception $e) {
            $this->stats['routingErrors']++;
            throw $e;
        }
    }

    /**
     * Route to the primary/writer backend
     *
     * @throws ResolverException
     */
    protected function routeWrite(string $resourceId): ConnectionResult
    {
        /** @var ReadWriteResolver $resolver */
        $resolver = $this->resolver;

        try {
            $result = $resolver->resolveWrite($resourceId);
            $endpoint = $result->endpoint;

            if (empty($endpoint)) {
                throw new ResolverException(
                    "Resolver returned empty write endpoint for: {$resourceId}",
                    ResolverException::NOT_FOUND
                );
            }

            if (!$this->skipValidation) {
                $this->validateEndpoint($endpoint);
            }

            return new ConnectionResult(
                endpoint: $endpoint,
                protocol: $this->getProtocol(),
                metadata: \array_merge(['cached' => false, 'route' => 'write'], $result->metadata)
            );
        } catch (\Exception $e) {
            $this->stats['routingErrors']++;
            throw $e;
        }
    }
}
