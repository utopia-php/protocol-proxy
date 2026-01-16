<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter\TCP\Swoole as TCPAdapter;

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

    public function test_postgres_database_id_parsing(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);
        $data = "user\x00appwrite\x00database\x00db-abc123\x00";

        $this->assertSame('abc123', $adapter->parseDatabaseId($data, 1));
        $this->assertSame('postgresql', $adapter->getProtocol());
    }

    public function test_my_sql_database_id_parsing(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 3306);
        $data = "\x00\x00\x00\x00\x02db-xyz789";

        $this->assertSame('xyz789', $adapter->parseDatabaseId($data, 1));
        $this->assertSame('mysql', $adapter->getProtocol());
    }

    public function test_postgres_database_id_parsing_fails_on_invalid_data(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 5432);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid PostgreSQL database name');

        $adapter->parseDatabaseId('invalid', 1);
    }

    public function test_my_sql_database_id_parsing_fails_on_invalid_data(): void
    {
        $adapter = new TCPAdapter($this->resolver, port: 3306);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid MySQL database name');

        $adapter->parseDatabaseId("\x00\x00\x00\x00\x01db-xyz", 1);
    }
}
