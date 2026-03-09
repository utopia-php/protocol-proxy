<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Adapter\HTTP\Swoole as HTTPAdapter;
use Utopia\Proxy\Adapter\SMTP\Swoole as SMTPAdapter;
use Utopia\Proxy\Adapter\TCP\Swoole as TCPAdapter;
use Utopia\Proxy\Resolver\Exception as ResolverException;

class AdapterActionsTest extends TestCase
{
    protected MockResolver $resolver;

    protected function setUp(): void
    {
        if (! \extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run adapter tests.');
        }

        $this->resolver = new MockResolver();
    }

    public function test_resolver_is_assigned_to_adapters(): void
    {
        $http = new HTTPAdapter($this->resolver);
        $tcp = new TCPAdapter($this->resolver, port: 5432);
        $smtp = new SMTPAdapter($this->resolver);

        $this->assertSame($this->resolver, $http->resolver);
        $this->assertSame($this->resolver, $tcp->resolver);
        $this->assertSame($this->resolver, $smtp->resolver);
    }

    public function test_resolve_routes_and_returns_endpoint(): void
    {
        $this->resolver->setEndpoint('127.0.0.1:8080');
        $adapter = new HTTPAdapter($this->resolver);
        $adapter->setSkipValidation(true);

        $result = $adapter->route('api.example.com');

        $this->assertSame('127.0.0.1:8080', $result->endpoint);
        $this->assertSame('http', $result->protocol);
    }

    public function test_notify_connect_delegates_to_resolver(): void
    {
        $adapter = new HTTPAdapter($this->resolver);

        $adapter->notifyConnect('resource-123', ['extra' => 'data']);

        $connects = $this->resolver->getConnects();
        $this->assertCount(1, $connects);
        $this->assertSame('resource-123', $connects[0]['resourceId']);
        $this->assertSame(['extra' => 'data'], $connects[0]['metadata']);
    }

    public function test_notify_close_delegates_to_resolver(): void
    {
        $adapter = new HTTPAdapter($this->resolver);

        $adapter->notifyClose('resource-123', ['extra' => 'data']);

        $disconnects = $this->resolver->getDisconnects();
        $this->assertCount(1, $disconnects);
        $this->assertSame('resource-123', $disconnects[0]['resourceId']);
        $this->assertSame(['extra' => 'data'], $disconnects[0]['metadata']);
    }

    public function test_track_activity_delegates_to_resolver_with_throttling(): void
    {
        $adapter = new HTTPAdapter($this->resolver);
        $adapter->setActivityInterval(1); // 1 second throttle

        // First call should trigger activity tracking
        $adapter->trackActivity('resource-123');
        $this->assertCount(1, $this->resolver->getActivities());

        // Immediate second call should be throttled
        $adapter->trackActivity('resource-123');
        $this->assertCount(1, $this->resolver->getActivities());

        // Wait for throttle interval to pass
        sleep(2);

        // Third call should trigger activity tracking
        $adapter->trackActivity('resource-123');
        $this->assertCount(2, $this->resolver->getActivities());
    }

    public function test_routing_error_throws_exception(): void
    {
        $this->resolver->setException(new ResolverException('No backend found'));
        $adapter = new HTTPAdapter($this->resolver);

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('No backend found');

        $adapter->route('api.example.com');
    }

    public function test_empty_endpoint_throws_exception(): void
    {
        $this->resolver->setEndpoint('');
        $adapter = new HTTPAdapter($this->resolver);

        $this->expectException(ResolverException::class);
        $this->expectExceptionMessage('Resolver returned empty endpoint');

        $adapter->route('api.example.com');
    }

    public function test_skip_validation_allows_private_i_ps(): void
    {
        // 10.0.0.1 is a private IP that would normally be blocked
        $this->resolver->setEndpoint('10.0.0.1:8080');
        $adapter = new HTTPAdapter($this->resolver);
        $adapter->setSkipValidation(true);

        // Should not throw exception with validation disabled
        $result = $adapter->route('api.example.com');
        $this->assertSame('10.0.0.1:8080', $result->endpoint);
    }
}
