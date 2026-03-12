<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\Protocol;

class AdapterMetadataTest extends TestCase
{
    protected MockResolver $resolver;

    protected function setUp(): void
    {
        if (! \extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }

        $this->resolver = new MockResolver();
    }

    public function test_http_adapter_metadata(): void
    {
        $adapter = new Adapter($this->resolver, name: 'HTTP', protocol: Protocol::HTTP, description: 'HTTP proxy adapter');

        $this->assertSame('HTTP', $adapter->getName());
        $this->assertSame(Protocol::HTTP, $adapter->getProtocol());
        $this->assertSame('HTTP proxy adapter', $adapter->getDescription());
    }

    public function test_smtp_adapter_metadata(): void
    {
        $adapter = new Adapter($this->resolver, name: 'SMTP', protocol: Protocol::SMTP, description: 'SMTP proxy adapter');

        $this->assertSame('SMTP', $adapter->getName());
        $this->assertSame(Protocol::SMTP, $adapter->getProtocol());
        $this->assertSame('SMTP proxy adapter', $adapter->getDescription());
    }

    public function test_tcp_adapter_metadata(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);

        $this->assertSame('TCP', $adapter->getName());
        $this->assertSame(Protocol::PostgreSQL, $adapter->getProtocol());
        $this->assertSame('TCP proxy adapter for database connections (PostgreSQL, MySQL, MongoDB)', $adapter->getDescription());
        $this->assertSame(5432, $adapter->port);
    }
}
