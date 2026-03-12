<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Query\Type as QueryType;

class ReadWriteSplitTest extends TestCase
{
    protected MockReadWriteResolver $rwResolver;

    protected MockResolver $basicResolver;

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }

        $this->rwResolver = new MockReadWriteResolver();
        $this->basicResolver = new MockResolver();
    }

    // ---------------------------------------------------------------
    // Read/Write Split Configuration
    // ---------------------------------------------------------------

    public function testReadWriteSplitDisabledByDefault(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $this->assertFalse($adapter->isReadWriteSplit());
    }

    public function testReadWriteSplitCanBeEnabled(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $this->assertTrue($adapter->isReadWriteSplit());
    }

    public function testReadWriteSplitCanBeDisabled(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setReadWriteSplit(false);
        $this->assertFalse($adapter->isReadWriteSplit());
    }

    // ---------------------------------------------------------------
    // Query Classification via Adapter
    // ---------------------------------------------------------------

    /**
     * Build a PostgreSQL Simple Query message
     */
    private function buildPgQuery(string $sql): string
    {
        $body = $sql . "\x00";
        $length = \strlen($body) + 4;

        return 'Q' . \pack('N', $length) . $body;
    }

    /**
     * Build a MySQL COM_QUERY packet
     */
    private function buildMySQLQuery(string $sql): string
    {
        $payloadLen = 1 + \strlen($sql);
        $header = \pack('V', $payloadLen);
        $header[3] = "\x00";

        return $header . "\x03" . $sql;
    }

    public function testClassifyPgSelectAsRead(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);

        $data = $this->buildPgQuery('SELECT * FROM users');
        $this->assertSame(QueryType::Read, $adapter->classifyQuery($data, 1));
    }

    public function testClassifyPgInsertAsWrite(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);

        $data = $this->buildPgQuery("INSERT INTO users (name) VALUES ('x')");
        $this->assertSame(QueryType::Write, $adapter->classifyQuery($data, 1));
    }

    public function testClassifyMysqlSelectAsRead(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 3306);
        $adapter->setReadWriteSplit(true);

        $data = $this->buildMySQLQuery('SELECT * FROM users');
        $this->assertSame(QueryType::Read, $adapter->classifyQuery($data, 1));
    }

    public function testClassifyMysqlInsertAsWrite(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 3306);
        $adapter->setReadWriteSplit(true);

        $data = $this->buildMySQLQuery("INSERT INTO users (name) VALUES ('x')");
        $this->assertSame(QueryType::Write, $adapter->classifyQuery($data, 1));
    }

    public function testClassifyReturnsWriteWhenSplitDisabled(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        // Read/write split is disabled by default

        $data = $this->buildPgQuery('SELECT * FROM users');
        $this->assertSame(QueryType::Write, $adapter->classifyQuery($data, 1));
    }

    // ---------------------------------------------------------------
    // Transaction Pinning
    // ---------------------------------------------------------------

    public function testBeginPinsConnectionToPrimary(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);

        $clientFd = 42;

        // Not pinned initially
        $this->assertFalse($adapter->isConnectionPinned($clientFd));

        // BEGIN pins
        $data = $this->buildPgQuery('BEGIN');
        $result = $adapter->classifyQuery($data, $clientFd);
        $this->assertSame(QueryType::Write, $result);
        $this->assertTrue($adapter->isConnectionPinned($clientFd));
    }

    public function testPinnedConnectionRoutesSelectToWrite(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);

        $clientFd = 42;

        // Begin transaction
        $adapter->classifyQuery($this->buildPgQuery('BEGIN'), $clientFd);
        $this->assertTrue($adapter->isConnectionPinned($clientFd));

        // SELECT should still route to WRITE when pinned
        $data = $this->buildPgQuery('SELECT * FROM users');
        $this->assertSame(QueryType::Write, $adapter->classifyQuery($data, $clientFd));
    }

    public function testCommitUnpinsConnection(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);

        $clientFd = 42;

        // Begin transaction
        $adapter->classifyQuery($this->buildPgQuery('BEGIN'), $clientFd);
        $this->assertTrue($adapter->isConnectionPinned($clientFd));

        // COMMIT unpins
        $adapter->classifyQuery($this->buildPgQuery('COMMIT'), $clientFd);
        $this->assertFalse($adapter->isConnectionPinned($clientFd));

        // Now SELECT should route to READ again
        $data = $this->buildPgQuery('SELECT * FROM users');
        $this->assertSame(QueryType::Read, $adapter->classifyQuery($data, $clientFd));
    }

    public function testRollbackUnpinsConnection(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);

        $clientFd = 42;

        // Begin transaction
        $adapter->classifyQuery($this->buildPgQuery('BEGIN'), $clientFd);
        $this->assertTrue($adapter->isConnectionPinned($clientFd));

        // ROLLBACK unpins
        $adapter->classifyQuery($this->buildPgQuery('ROLLBACK'), $clientFd);
        $this->assertFalse($adapter->isConnectionPinned($clientFd));
    }

    public function testStartTransactionPinsConnection(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);

        $clientFd = 42;

        $adapter->classifyQuery($this->buildPgQuery('START TRANSACTION'), $clientFd);
        $this->assertTrue($adapter->isConnectionPinned($clientFd));
    }

    public function testMysqlBeginPinsConnection(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 3306);
        $adapter->setReadWriteSplit(true);

        $clientFd = 42;

        $adapter->classifyQuery($this->buildMySQLQuery('BEGIN'), $clientFd);
        $this->assertTrue($adapter->isConnectionPinned($clientFd));
    }

    public function testMysqlCommitUnpinsConnection(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 3306);
        $adapter->setReadWriteSplit(true);

        $clientFd = 42;

        $adapter->classifyQuery($this->buildMySQLQuery('BEGIN'), $clientFd);
        $this->assertTrue($adapter->isConnectionPinned($clientFd));

        $adapter->classifyQuery($this->buildMySQLQuery('COMMIT'), $clientFd);
        $this->assertFalse($adapter->isConnectionPinned($clientFd));
    }

    public function testClearConnectionStateRemovesPin(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);

        $clientFd = 42;

        $adapter->classifyQuery($this->buildPgQuery('BEGIN'), $clientFd);
        $this->assertTrue($adapter->isConnectionPinned($clientFd));

        $adapter->clearConnectionState($clientFd);
        $this->assertFalse($adapter->isConnectionPinned($clientFd));
    }

    // ---------------------------------------------------------------
    // Multiple Connections Independence
    // ---------------------------------------------------------------

    public function testPinningIsPerConnection(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);

        $fd1 = 1;
        $fd2 = 2;

        // Pin fd1
        $adapter->classifyQuery($this->buildPgQuery('BEGIN'), $fd1);
        $this->assertTrue($adapter->isConnectionPinned($fd1));
        $this->assertFalse($adapter->isConnectionPinned($fd2));

        // fd2 can still read
        $this->assertSame(QueryType::Read, $adapter->classifyQuery($this->buildPgQuery('SELECT 1'), $fd2));

        // fd1 is pinned to write
        $this->assertSame(QueryType::Write, $adapter->classifyQuery($this->buildPgQuery('SELECT 1'), $fd1));
    }

    // ---------------------------------------------------------------
    // Route Query Integration (with ReadWriteResolver)
    // ---------------------------------------------------------------

    public function testRouteQueryReadUsesReadEndpoint(): void
    {
        $this->rwResolver->setEndpoint('primary.db:5432');
        $this->rwResolver->setReadEndpoint('replica.db:5432');
        $this->rwResolver->setWriteEndpoint('primary.db:5432');

        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $result = $adapter->routeQuery('test-db', QueryType::Read);
        $this->assertSame('replica.db:5432', $result->endpoint);
        $this->assertSame('read', $result->metadata['route']);
    }

    public function testRouteQueryWriteUsesWriteEndpoint(): void
    {
        $this->rwResolver->setEndpoint('primary.db:5432');
        $this->rwResolver->setReadEndpoint('replica.db:5432');
        $this->rwResolver->setWriteEndpoint('primary.db:5432');

        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $result = $adapter->routeQuery('test-db', QueryType::Write);
        $this->assertSame('primary.db:5432', $result->endpoint);
        $this->assertSame('write', $result->metadata['route']);
    }

    public function testRouteQueryFallsBackWhenSplitDisabled(): void
    {
        $this->rwResolver->setEndpoint('default.db:5432');
        $this->rwResolver->setReadEndpoint('replica.db:5432');
        $this->rwResolver->setWriteEndpoint('primary.db:5432');

        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        // read/write split is disabled
        $adapter->setSkipValidation(true);

        $result = $adapter->routeQuery('test-db', QueryType::Read);
        $this->assertSame('default.db:5432', $result->endpoint);
    }

    public function testRouteQueryFallsBackWithBasicResolver(): void
    {
        $this->basicResolver->setEndpoint('default.db:5432');

        $adapter = new TCPAdapter($this->basicResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        // Even with read/write split enabled, basic resolver uses default route()
        $result = $adapter->routeQuery('test-db', QueryType::Read);
        $this->assertSame('default.db:5432', $result->endpoint);
    }

    // ---------------------------------------------------------------
    // Transaction State with SET Command
    // ---------------------------------------------------------------

    public function testSetCommandRoutesToPrimaryButDoesNotPin(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);

        $clientFd = 42;

        // SET is a transaction-class command, routes to primary
        $result = $adapter->classifyQuery($this->buildPgQuery("SET search_path = 'public'"), $clientFd);
        $this->assertSame(QueryType::Write, $result);

        // But SET should not pin the connection (only BEGIN/START pin)
        $this->assertFalse($adapter->isConnectionPinned($clientFd));
    }

    // ---------------------------------------------------------------
    // Unknown Queries Route to Primary
    // ---------------------------------------------------------------

    public function testUnknownQueryRoutesToWrite(): void
    {
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);

        // Use an unknown PG message type
        $data = 'X' . \pack('N', 5) . "\x00";
        $result = $adapter->classifyQuery($data, 1);
        $this->assertSame(QueryType::Write, $result);
    }
}
