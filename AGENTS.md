# Utopia Proxy

High-performance, protocol-agnostic proxy library built on Swoole for HTTP, TCP (PostgreSQL, MySQL, MongoDB), and SMTP proxying. Handles 670k+ concurrent connections per server.

## Commands

| Command | Purpose |
|---------|---------|
| `composer test` | Run unit tests |
| `composer test:integration` | Run integration tests (requires Docker) |
| `composer test:all` | Run all tests (unit + integration) |
| `composer lint` | Check formatting (Pint, PSR-12) |
| `composer format` | Auto-format code |
| `composer check` | Static analysis (PHPStan, max level, 2GB) |
| `composer bench:http` | HTTP proxy benchmarks |
| `composer bench:tcp` | TCP proxy benchmarks |

Run a single test:
```bash
./vendor/bin/phpunit tests/ResolverTest.php
./vendor/bin/phpunit tests/ResolverTest.php --filter=testResolverResultStoresValues
```

## Stack

- PHP 8.4+, ext-swoole >= 6.0, ext-redis
- PHPUnit 12, Pint (PSR-12), PHPStan (max level)
- Docker Compose for integration tests (MariaDB + Redis)

## Project layout

- **src/** -- library code (PSR-4 namespace `Utopia\Proxy\`)
  - `Adapter.php` -- base adapter with routing, caching, SSRF validation, byte tracking
  - `Adapter/TCP.php` -- TCP adapter with protocol auto-detection by port
  - `Resolver.php` -- interface for backend resolution (implement to integrate)
  - `Resolver/Result.php` -- resolver result: endpoint, metadata, timeout
  - `Resolver/Exception.php` -- exceptions with HTTP codes (404/503/504/403/500)
  - `ConnectionResult.php` -- immutable result with endpoint and metadata
  - `Protocol.php` -- enum with 28 protocol types (HTTP, TCP, SMTP, PostgreSQL, MySQL, MongoDB, Redis, Kafka, GRPC, etc.)
  - `Bytes.php` -- inbound/outbound byte tracking
  - `Server/HTTP/` -- HTTP proxy server
    - `Config.php` -- typed config with ~40 Swoole settings
    - `Swoole.php` -- event-driven HTTP server
    - `Swoole/Coroutine.php` -- coroutine-based variant
    - `Swoole/Handler.php` -- shared HTTP request forwarding trait
    - `Telemetry.php` -- performance metrics
  - `Server/TCP/` -- TCP proxy server
    - `Config.php` -- TCP config (ports, TLS, buffers)
    - `TLS.php` -- TLS configuration for mTLS
    - `TLSContext.php` -- Swoole SSL context builder
    - `Swoole.php` -- event-driven TCP server
    - `Swoole/Coroutine.php` -- coroutine-based variant
  - `Server/SMTP/` -- SMTP proxy server
    - `Config.php`, `Connection.php`, `Swoole.php`

- **tests/** -- PHPUnit tests (unit + integration suites)
  - `MockResolver.php` -- test resolver implementation
- **examples/** -- working examples (http-proxy.php, http.php, tcp.php, smtp.php)
- **benchmarks/** -- performance benchmarks

## Key patterns

**Resolver interface:** Central integration point. Implement `resolve()`, `track()`, `purge()`, `getStats()`, `onConnect()`, `onDisconnect()` to route traffic to backends. Alternatively, use `Adapter::onResolve(\Closure)` for quick overrides.

**Server variants:** Each protocol (HTTP, TCP, SMTP) has an event-driven server (`Swoole.php`) and a coroutine-based variant (`Swoole/Coroutine.php`). Event-driven uses Swoole PROCESS mode, coroutine uses BASE mode.

**Config classes:** Typed, readonly config per server type. Computed defaults (e.g., `reactorNum = cpu_num * 2`). Not arrays -- structured classes.

**SSRF protection:** Endpoint validation before caching. Configurable via `skipValidation` flag.

**TLS termination:** Protocol-specific TLS handling (PostgreSQL SSLRequest, MySQL SSL handshake). mTLS support with CA certificates via TLSContext builder.

**Connection pooling:** HTTP uses channel-based pools per host:port. TCP uses direct connection cache per file descriptor.

**Caching:** Swoole Table for fast in-process cache with configurable TTL.

## Environment variables (from examples)

```bash
# HTTP proxy
HTTP_WORKERS=16               # Default: cpu_num * 2
HTTP_SERVER_MODE=process       # "process" or "base" (coroutine)
HTTP_BACKEND_POOL_SIZE=2048
HTTP_KEEPALIVE_TIMEOUT=60
HTTP_OPEN_HTTP2=false
HTTP_SKIP_VALIDATION=false     # Disable SSRF check

# TCP proxy
TCP_WORKERS=16
TCP_SERVER_IMPL=swoole         # "swoole" or "coroutine"
TCP_POSTGRES_PORT=5432
TCP_MYSQL_PORT=3306

# SMTP proxy
SMTP_BACKEND_ENDPOINT=smtp-backend:1025
```

## Testing patterns

- Tests extend `PHPUnit\Framework\TestCase`
- setUp checks `extension_loaded('swoole')` and skips if missing
- `MockResolver` for isolation (set endpoint, metadata, callbacks)
- Integration tests require Docker Compose (MariaDB + Redis)

## Conventions

- PSR-12 via Pint, PSR-4 autoloading
- Full type hints on all parameters and returns, readonly properties
- Imports: alphabetical, single per statement, grouped by const/class/function
- Fluent builder methods return `static`
- One class per file, filename matches class name

## Cross-repo context

Proxy is a direct dependency of the edge repo (`utopia-php/proxy`). Changes to the Adapter, Resolver, or Config interfaces can break edge.
