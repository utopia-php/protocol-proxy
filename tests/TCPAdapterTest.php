<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\Protocol;

class TCPAdapterTest extends TestCase
{
    protected MockResolver $resolver;

    protected function setUp(): void
    {
        if (! \extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }

        $this->resolver = new MockResolver();
    }

    public function testProtocolDetection(): void
    {
        $pg = new TCPAdapter($this->resolver, port: 5432);
        $this->assertSame(Protocol::PostgreSQL, $pg->getProtocol());

        $mysql = new TCPAdapter($this->resolver, port: 3306);
        $this->assertSame(Protocol::MySQL, $mysql->getProtocol());

        $mongo = new TCPAdapter($this->resolver, port: 27017);
        $this->assertSame(Protocol::MongoDB, $mongo->getProtocol());
    }

    public function testDescription(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $this->assertSame('TCP proxy adapter', $adapter->getDescription());
    }

    public function testName(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $this->assertSame('TCP', $adapter->getName());
    }

    public function testPort(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 3306);
        $this->assertSame(3306, $adapter->port);
    }
}
