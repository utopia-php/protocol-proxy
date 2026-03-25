<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver\Exception as ResolverException;
use Utopia\Query\Type as QueryType;

class TCPAdapterExtendedTest extends TestCase
{
    protected MockResolver $resolver;

    protected MockReadWriteResolver $rwResolver;

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }

        $this->resolver = new MockResolver();
        $this->rwResolver = new MockReadWriteResolver();
    }

    public function testProtocolForPostgresPort(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $this->assertSame(Protocol::PostgreSQL, $adapter->getProtocol());
    }

    public function testProtocolForMysqlPort(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 3306);
        $this->assertSame(Protocol::MySQL, $adapter->getProtocol());
    }

    public function testProtocolForMongoPort(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 27017);
        $this->assertSame(Protocol::MongoDB, $adapter->getProtocol());
    }

    public function testProtocolThrowsForUnsupportedPort(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 8080);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unsupported protocol on port: 8080');

        $adapter->getProtocol();
    }

    public function testPortProperty(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $this->assertSame(5432, $adapter->port);
    }

    public function testNameIsAlwaysTCP(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $this->assertSame('TCP', $adapter->getName());
    }

    public function testDescription(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $this->assertSame('TCP proxy adapter', $adapter->getDescription());
    }

    public function testSetConnectTimeoutReturnsSelf(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $result = $adapter->setTimeout(10.0);
        $this->assertSame($adapter, $result);
    }

    public function testSetReadWriteSplitReturnsSelf(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $result = $adapter->setReadWriteSplit(true);
        $this->assertSame($adapter, $result);
    }

    public function testClearConnectionStateForNonExistentFd(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $adapter->setReadWriteSplit(true);

        // Should not throw
        $adapter->clearState(999);
        $this->assertFalse($adapter->isPinned(999));
    }

    public function testIsConnectionPinnedDefaultFalse(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $this->assertFalse($adapter->isPinned(1));
    }

    public function testRouteQueryReadThrowsWhenNoReadEndpoint(): void
    {
        $this->rwResolver->setEndpoint('primary.db:5432');
        $this->rwResolver->setWriteEndpoint('primary.db:5432');

        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $this->expectException(ResolverException::class);

        $adapter->routeQuery('test-db', QueryType::Read);
    }

    public function testRouteQueryWriteThrowsWhenNoWriteEndpoint(): void
    {
        $this->rwResolver->setEndpoint('primary.db:5432');
        $this->rwResolver->setReadEndpoint('replica.db:5432');

        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $this->expectException(ResolverException::class);

        $adapter->routeQuery('test-db', QueryType::Write);
    }

    public function testRouteQueryReadEmptyEndpointThrows(): void
    {
        $this->rwResolver->setEndpoint('primary.db:5432');
        $this->rwResolver->setReadEndpoint('');
        $this->rwResolver->setWriteEndpoint('primary.db:5432');

        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('empty read endpoint');

        $adapter->routeQuery('test-db', QueryType::Read);
    }

    public function testRouteQueryWriteEmptyEndpointThrows(): void
    {
        $this->rwResolver->setEndpoint('primary.db:5432');
        $this->rwResolver->setReadEndpoint('replica.db:5432');
        $this->rwResolver->setWriteEndpoint('');

        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('empty write endpoint');

        $adapter->routeQuery('test-db', QueryType::Write);
    }

    public function testRouteQueryReadIncrementsErrorStatsOnFailure(): void
    {
        $this->rwResolver->setEndpoint('primary.db:5432');

        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        try {
            $adapter->routeQuery('test-db', QueryType::Read);
            $this->fail('Expected exception');
        } catch (ResolverException $e) {
            // expected
        }

        $stats = $adapter->getStats();
        $this->assertSame(1, $stats['routingErrors']);
    }

    public function testRouteQueryWriteIncrementsErrorStatsOnFailure(): void
    {
        $this->rwResolver->setEndpoint('primary.db:5432');

        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        try {
            $adapter->routeQuery('test-db', QueryType::Write);
            $this->fail('Expected exception');
        } catch (ResolverException $e) {
            // expected
        }

        $stats = $adapter->getStats();
        $this->assertSame(1, $stats['routingErrors']);
    }

    public function testRouteQueryReadMetadataIncludesRouteType(): void
    {
        $this->rwResolver->setReadEndpoint('replica.db:5432');
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $result = $adapter->routeQuery('test-db', QueryType::Read);
        $this->assertSame('read', $result->metadata['route']);
        $this->assertFalse($result->metadata['cached']);
    }

    public function testRouteQueryWriteMetadataIncludesRouteType(): void
    {
        $this->rwResolver->setWriteEndpoint('primary.db:5432');
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $result = $adapter->routeQuery('test-db', QueryType::Write);
        $this->assertSame('write', $result->metadata['route']);
        $this->assertFalse($result->metadata['cached']);
    }

    public function testRouteQueryReadPreservesResolverMetadata(): void
    {
        $this->rwResolver->setReadEndpoint('replica.db:5432');
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $result = $adapter->routeQuery('test-db', QueryType::Read);
        $this->assertSame('test-db', $result->metadata['resourceId']);
    }

    public function testRouteQueryReadValidatesEndpoint(): void
    {
        $this->rwResolver->setReadEndpoint('10.0.0.1:5432');
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IP');

        $adapter->routeQuery('test-db', QueryType::Read);
    }

    public function testRouteQueryWriteValidatesEndpoint(): void
    {
        $this->rwResolver->setWriteEndpoint('192.168.1.1:5432');
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('private/reserved IP');

        $adapter->routeQuery('test-db', QueryType::Write);
    }

    public function testRouteQuerySkipsValidationWhenDisabled(): void
    {
        $this->rwResolver->setReadEndpoint('10.0.0.1:5432');
        $adapter = new TCPAdapter($this->rwResolver, port: 5432);
        $adapter->setReadWriteSplit(true);
        $adapter->setSkipValidation(true);

        $result = $adapter->routeQuery('test-db', QueryType::Read);
        $this->assertSame('10.0.0.1:5432', $result->endpoint);
    }
}
