# Hook System

The protocol-proxy provides a flexible hook system that allows applications to inject custom business logic into the routing lifecycle.

**Key Design**: The proxy doesn't enforce how backends are resolved. Applications provide their own resolution logic via the `resolve` hook.

## Available Hooks

### 1. `resolve` (Required)

Called to **resolve the backend endpoint** for a resource identifier.

**Parameters:**
- `string $resourceId` - The identifier to resolve (hostname, domain, etc.)

**Returns:**
- `string` - Backend endpoint (e.g., `10.0.1.5:8080` or `backend.service:80`)

**Use Cases:**
- Database lookup
- Config file mapping
- Service discovery (Consul, etcd)
- External API calls
- Kubernetes service resolution
- DNS resolution

**Example:**
```php
// Option 1: Static configuration
$adapter->hook('resolve', function (string $hostname) {
    $mapping = [
        'func-123.app.network' => '10.0.1.5:8080',
        'func-456.app.network' => '10.0.1.6:8080',
    ];
    return $mapping[$hostname] ?? throw new \Exception("Not found");
});

// Option 2: Database lookup (like Appwrite Edge)
$adapter->hook('resolve', function (string $hostname) use ($db) {
    $doc = $db->findOne('functions', [
        Query::equal('hostname', [$hostname])
    ]);
    return $doc->getAttribute('endpoint');
});

// Option 3: Service discovery
$adapter->hook('resolve', function (string $hostname) use ($consul) {
    return $consul->resolveService($hostname);
});

// Option 4: Kubernetes service
$adapter->hook('resolve', function (string $hostname) {
    return "function-{$hostname}.default.svc.cluster.local:8080";
});
```

**Important:** Only one `resolve` hook can be registered. If you try to register multiple, an exception will be thrown.

### 2. `beforeRoute`

Called **before** any routing logic executes.

**Parameters:**
- `string $resourceId` - The identifier being routed (hostname, domain, etc.)

**Use Cases:**
- Validate request format
- Check authentication/authorization
- Rate limiting
- Custom caching lookups
- Request transformation

**Example:**
```php
$adapter->hook('beforeRoute', function (string $hostname) {
    // Validate hostname format
    if (!preg_match('/^[a-z0-9-]+\.myapp\.com$/', $hostname)) {
        throw new \Exception("Invalid hostname: {$hostname}");
    }

    // Check rate limits
    if (isRateLimited($hostname)) {
        throw new \Exception("Rate limit exceeded");
    }
});
```

### 2. `afterRoute`

Called **after** successful routing.

**Parameters:**
- `string $resourceId` - The identifier that was routed
- `string $endpoint` - The backend endpoint that was resolved
- `ConnectionResult $result` - The routing result object with metadata

**Use Cases:**
- Logging and telemetry
- Metrics collection
- Response header manipulation
- Cache warming
- Audit trails

**Example:**
```php
$adapter->hook('afterRoute', function (string $hostname, string $endpoint, $result) {
    // Log to telemetry
    $telemetry->record([
        'hostname' => $hostname,
        'endpoint' => $endpoint,
        'cached' => $result->metadata['cached'],
        'latency_ms' => $result->metadata['latency_ms'],
    ]);

    // Update metrics
    $metrics->increment('proxy.routes.success');
    if ($result->metadata['cached']) {
        $metrics->increment('proxy.cache.hits');
    }
});
```

### 3. `onRoutingError`

Called when routing **fails** with an exception.

**Parameters:**
- `string $resourceId` - The identifier that failed to route
- `\Exception $e` - The exception that was thrown

**Use Cases:**
- Error logging (Sentry, etc.)
- Custom error responses
- Fallback routing
- Circuit breaker logic
- Alerting

**Example:**
```php
$adapter->hook('onRoutingError', function (string $hostname, \Exception $e) {
    // Log to Sentry
    Sentry\captureException($e, [
        'tags' => ['hostname' => $hostname],
        'level' => 'error',
    ]);

    // Try fallback region
    if ($e->getMessage() === 'Function not found') {
        tryFallbackRegion($hostname);
    }

    // Update error metrics
    $metrics->increment('proxy.routes.errors');
});
```

## Registering Multiple Hooks

You can register multiple callbacks for the same hook:

```php
// Hook 1: Validation
$adapter->hook('beforeRoute', function ($hostname) {
    validateHostname($hostname);
});

// Hook 2: Rate limiting
$adapter->hook('beforeRoute', function ($hostname) {
    checkRateLimit($hostname);
});

// Hook 3: Authentication
$adapter->hook('beforeRoute', function ($hostname) {
    validateJWT();
});
```

All registered hooks will execute in the order they were registered.

## Integration with Appwrite Edge

The protocol-proxy can replace the current edge HTTP proxy by using hooks to inject edge-specific logic:

```php
use Utopia\Proxy\Adapter\HTTP;

$adapter = new HTTP($cache, $dbPool);

// Hook 1: Resolve backend using K8s runtime registry (REQUIRED)
$adapter->hook('resolve', function (string $hostname) use ($runtimeRegistry) {
    // Edge resolves hostnames to K8s service endpoints
    $runtime = $runtimeRegistry->get($hostname);
    if (!$runtime) {
        throw new \Exception("Runtime not found: {$hostname}");
    }

    // Return K8s service endpoint
    return "{$runtime['projectId']}-{$runtime['deploymentId']}.runtimes.svc.cluster.local:8080";
});

// Hook 2: Rule resolution and caching
$adapter->hook('beforeRoute', function (string $hostname) use ($ruleCache, $sdkForManager) {
    $rule = $ruleCache->load($hostname);
    if (!$rule) {
        $rule = $sdkForManager->getRule($hostname);
        $ruleCache->save($hostname, $rule);
    }
    Context::set('rule', $rule);
});

// Hook 3: Telemetry and metrics
$adapter->hook('afterRoute', function (string $hostname, string $endpoint, $result) use ($telemetry) {
    $telemetry->record([
        'hostname' => $hostname,
        'endpoint' => $endpoint,
        'cached' => $result->metadata['cached'],
        'latency_ms' => $result->metadata['latency_ms'],
    ]);
});

// Hook 4: Error logging
$adapter->hook('onRoutingError', function (string $hostname, \Exception $e) use ($logger) {
    $logger->addLog([
        'type' => 'error',
        'hostname' => $hostname,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
});
```

## Performance Considerations

- **Hooks are synchronous** - They execute inline during routing
- **Keep hooks fast** - Slow hooks will impact overall proxy performance
- **Use async operations** - For non-critical work (logging, metrics), consider using Swoole coroutines or queues
- **Avoid heavy I/O** - Database queries and API calls in hooks should be cached or batched

## Best Practices

1. **Fail fast** - Throw exceptions early in `beforeRoute` to avoid unnecessary work
2. **Keep it simple** - Each hook should do one thing well
3. **Handle errors** - Wrap hook logic in try/catch to prevent cascading failures
4. **Document hooks** - Clearly document what each hook does and why
5. **Test hooks** - Write unit tests for hook callbacks
6. **Monitor performance** - Track hook execution time to identify bottlenecks

## Example: Complete Edge Integration

See `examples/http-edge-integration.php` for a complete example of how Appwrite Edge can integrate with the protocol-proxy using hooks.
