# Action System

The protocol-proxy uses Utopia Platform actions to inject custom business logic into the routing lifecycle.

**Key Design**: The proxy doesn't enforce how backends are resolved. Applications provide their own resolution logic via a `resolve` action.

## Action Registration

Each adapter initializes a protocol-specific service by default. Use it directly or replace it with your own.

```php
use Utopia\Platform\Action;
use Utopia\Proxy\Adapter\HTTP\Swoole as HTTPAdapter;
use Utopia\Proxy\Service\HTTP as HTTPService;

$adapter = new HTTPAdapter();
$service = $adapter->getService() ?? new HTTPService();

// Required: resolve backend endpoint
$service->addAction('resolve', (new class extends Action {})
    ->callback(function (string $hostname): string {
        return "runtime-{$hostname}.runtimes.svc.cluster.local:8080";
    }));

// Optional: beforeRoute actions (TYPE_INIT)
$service->addAction('validateHost', (new class extends Action {})
    ->setType(Action::TYPE_INIT)
    ->callback(function (string $hostname) {
        if (!preg_match('/^[a-z0-9-]+\.myapp\.com$/', $hostname)) {
            throw new \Exception("Invalid hostname: {$hostname}");
        }
    }));

// Optional: afterRoute actions (TYPE_SHUTDOWN)
$service->addAction('logRoute', (new class extends Action {})
    ->setType(Action::TYPE_SHUTDOWN)
    ->callback(function (string $hostname, string $endpoint, $result) {
        error_log("Routed {$hostname} -> {$endpoint}");
    }));

// Optional: onRoutingError actions (TYPE_ERROR)
$service->addAction('logError', (new class extends Action {})
    ->setType(Action::TYPE_ERROR)
    ->callback(function (string $hostname, \Exception $e) {
        error_log("Routing error for {$hostname}: {$e->getMessage()}");
    }));

$adapter->setService($service);
```

Actions execute in the order they were added to the service.

## Protocol Services

Use the protocol-specific service classes to keep configuration aligned with each adapter:

- `Utopia\Proxy\Service\HTTP`
- `Utopia\Proxy\Service\TCP`
- `Utopia\Proxy\Service\SMTP`

## Action Types and Parameters

### 1. `resolve` (Required)

Action key: `resolve` (type is `Action::TYPE_DEFAULT` by default)

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

### 2. `beforeRoute` (TYPE_INIT)

Run actions with `Action::TYPE_INIT` **before** routing.

**Parameters:**
- `string $resourceId` - The identifier being routed (hostname, domain, etc.)

**Use Cases:**
- Validate request format
- Check authentication/authorization
- Rate limiting
- Custom caching lookups
- Request transformation

### 3. `afterRoute` (TYPE_SHUTDOWN)

Run actions with `Action::TYPE_SHUTDOWN` **after** successful routing.

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

### 4. `onRoutingError` (TYPE_ERROR)

Run actions with `Action::TYPE_ERROR` when routing fails.

**Parameters:**
- `string $resourceId` - The identifier that failed to route
- `\Exception $e` - The exception that was thrown

**Use Cases:**
- Error logging (Sentry, etc.)
- Custom error responses
- Fallback routing
- Circuit breaker logic
- Alerting

## Integration with Appwrite Edge

The protocol-proxy can replace the current edge HTTP proxy by using actions to inject edge-specific logic:

```php
use Utopia\Platform\Action;
use Utopia\Proxy\Adapter\HTTP\Swoole as HTTPAdapter;
use Utopia\Proxy\Service\HTTP as HTTPService;

$adapter = new HTTPAdapter();
$service = $adapter->getService() ?? new HTTPService();

// Resolve backend using K8s runtime registry (REQUIRED)
$service->addAction('resolve', (new class extends Action {})
    ->callback(function (string $hostname) use ($runtimeRegistry): string {
        $runtime = $runtimeRegistry->get($hostname);
        if (!$runtime) {
            throw new \Exception("Runtime not found: {$hostname}");
        }
        return "{$runtime['projectId']}-{$runtime['deploymentId']}.runtimes.svc.cluster.local:8080";
    }));

// Rule resolution and caching
$service->addAction('resolveRule', (new class extends Action {})
    ->setType(Action::TYPE_INIT)
    ->callback(function (string $hostname) use ($ruleCache, $sdkForManager) {
        $rule = $ruleCache->load($hostname);
        if (!$rule) {
            $rule = $sdkForManager->getRule($hostname);
            $ruleCache->save($hostname, $rule);
        }
        Context::set('rule', $rule);
    }));

// Telemetry and metrics
$service->addAction('telemetry', (new class extends Action {})
    ->setType(Action::TYPE_SHUTDOWN)
    ->callback(function (string $hostname, string $endpoint, $result) use ($telemetry) {
        $telemetry->record([
            'hostname' => $hostname,
            'endpoint' => $endpoint,
            'cached' => $result->metadata['cached'],
            'latency_ms' => $result->metadata['latency_ms'],
        ]);
    }));

// Error logging
$service->addAction('routeError', (new class extends Action {})
    ->setType(Action::TYPE_ERROR)
    ->callback(function (string $hostname, \Exception $e) use ($logger) {
        $logger->addLog([
            'type' => 'error',
            'hostname' => $hostname,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }));

$adapter->setService($service);
```

## Performance Considerations

- **Actions are synchronous** - They execute inline during routing
- **Keep actions fast** - Slow actions will impact overall proxy performance
- **Use async operations** - For non-critical work (logging, metrics), consider using Swoole coroutines or queues
- **Avoid heavy I/O** - Database queries and API calls in actions should be cached or batched

## Best Practices

1. **Fail fast** - Throw exceptions early in init actions to avoid unnecessary work
2. **Keep it simple** - Each action should do one thing well
3. **Handle errors** - Wrap action logic in try/catch to prevent cascading failures
4. **Document actions** - Clearly document what each action does and why
5. **Test actions** - Write unit tests for action callbacks
6. **Monitor performance** - Track action execution time to identify bottlenecks

## Example: Complete Edge Integration

See `examples/http-edge-integration.php` for a complete example of how Appwrite Edge can integrate with the protocol-proxy using actions.
