<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\Protocol;

class TCPAdapterExtendedTest extends TestCase
{
    protected MockResolver $resolver;

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }

        $this->resolver = new MockResolver();
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

}
