# Benchmarks

High-load benchmark suite for HTTP and TCP proxies.

## Validated Performance (8-core, 32GB RAM)

| Metric | Result |
|--------|--------|
| **Peak concurrent connections** | 672,348 |
| **Memory at peak** | 23 GB |
| **Memory per connection** | ~33 KB |
| **Connection rate (sustained)** | 18,067/sec |
| **CPU at peak** | ~60% |

## One-Shot Benchmark (Fresh Linux Droplet)

```bash
curl -sL https://raw.githubusercontent.com/utopia-php/protocol-proxy/dev/benchmarks/bootstrap-droplet.sh | sudo bash
```

This installs PHP 8.3 + Swoole, tunes the kernel, and runs all benchmarks automatically.

## Maximum Connection Stress Test

```bash
./benchmarks/stress-max.sh
```

Pushes the system to maximum concurrent connections. Requires root for kernel tuning.

## Quick start (HTTP)

Run the PHP benchmark:
```bash
php benchmarks/http.php
```

Run wrk:
```bash
benchmarks/wrk.sh
```

Run wrk2 (fixed rate):
```bash
benchmarks/wrk2.sh
```

Compare Swoole HTTP servers (evented vs coroutine):
```bash
benchmarks/compare-http-servers.sh
```

## Quick start (TCP)

Run the TCP benchmark:
```bash
php benchmarks/tcp.php
```

Compare Swoole TCP servers (evented vs coroutine):
```bash
benchmarks/compare-tcp-servers.sh
```

## Presets (HTTP)

Max throughput, burst:
```bash
WRK_THREADS=16 WRK_CONNECTIONS=5000 WRK_DURATION=30s WRK_URL=http://127.0.0.1:8080/ benchmarks/wrk.sh
```

Fixed rate (wrk2):
```bash
WRK2_THREADS=16 WRK2_CONNECTIONS=5000 WRK2_DURATION=30s WRK2_RATE=200000 WRK2_URL=http://127.0.0.1:8080/ benchmarks/wrk2.sh
```

PHP benchmark, moderate:
```bash
BENCH_CONCURRENCY=500 BENCH_REQUESTS=50000 php benchmarks/http.php
```

## Presets (TCP)

Connection rate only:
```bash
BENCH_PROTOCOL=mysql BENCH_PORT=15433 BENCH_PAYLOAD_BYTES=0 BENCH_CONCURRENCY=500 BENCH_CONNECTIONS=50000 php benchmarks/tcp.php
```

Throughput heavy (payload enabled):
```bash
BENCH_PROTOCOL=mysql BENCH_PORT=15433 BENCH_PAYLOAD_BYTES=65536 BENCH_TARGET_BYTES=17179869184 BENCH_CONCURRENCY=2000 php benchmarks/tcp.php
```

## Sustained Load Tests

Sustained mode (continuous connection churn):
```bash
BENCH_DURATION=300 BENCH_CONCURRENCY=4000 BENCH_PAYLOAD_BYTES=0 php benchmarks/tcp-sustained.php
```

Max connections mode (hold connections open):
```bash
BENCH_MODE=max_connections BENCH_TARGET_CONNECTIONS=50000 php benchmarks/tcp-sustained.php
```

Hold forever mode (Ctrl+C to stop):
```bash
BENCH_MODE=hold_forever BENCH_TARGET_CONNECTIONS=50000 php benchmarks/tcp-sustained.php
```

## Scaling Test (Multiple Backends)

To test maximum concurrent connections, run multiple backend/client pairs:

```bash
# Start 16 backends on different ports
for p in $(seq 15432 15447); do
  BACKEND_PORT=$p php benchmarks/tcp-backend.php &
done

# Start 16 clients targeting 40k connections each (640k total)
for p in $(seq 15432 15447); do
  BENCH_PORT=$p BENCH_MODE=hold_forever BENCH_TARGET_CONNECTIONS=40000 php benchmarks/tcp-sustained.php &
done

# Monitor connections
watch -n1 'ss -s | grep estab'
```

## Environment variables

HTTP PHP benchmark (`benchmarks/http.php`):
- `BENCH_HOST` (default `localhost`)
- `BENCH_PORT` (default `8080`)
- `BENCH_CONCURRENCY` (default `max(2000, cpu*500)`)
- `BENCH_REQUESTS` (default `max(1000000, concurrency*500)`)
- `BENCH_TIMEOUT` (default `10`)
- `BENCH_KEEP_ALIVE` (default `true`)
- `BENCH_SAMPLE_TARGET` (default `200000`)
- `BENCH_SAMPLE_EVERY` (optional override)

TCP PHP benchmark (`benchmarks/tcp.php`):
- `BENCH_HOST` (default `localhost`)
- `BENCH_PORT` (default `5432`)
- `BENCH_PROTOCOL` (`postgres` or `mysql`, default based on port)
- `BENCH_CONCURRENCY` (default `max(2000, cpu*500)`)
- `BENCH_CONNECTIONS` (default derived from payload/target)
- `BENCH_PAYLOAD_BYTES` (default `65536`)
- `BENCH_TARGET_BYTES` (default `8GB`)
- `BENCH_TIMEOUT` (default `10`)
- `BENCH_SAMPLE_TARGET` (default `200000`)
- `BENCH_SAMPLE_EVERY` (optional override)
- `BENCH_PERSISTENT` (default `false`)
- `BENCH_STREAM_BYTES` (default `0`, uses `BENCH_TARGET_BYTES` when persistent)
- `BENCH_STREAM_DURATION` (default `0`)
- `BENCH_ECHO_NEWLINE` (default `false`)

wrk (`benchmarks/wrk.sh`):
- `WRK_THREADS` (default `cpu`)
- `WRK_CONNECTIONS` (default `1000`)
- `WRK_DURATION` (default `30s`)
- `WRK_URL` (default `http://127.0.0.1:8080/`)
- `WRK_EXTRA` (extra flags)

wrk2 (`benchmarks/wrk2.sh`):
- `WRK2_THREADS` (default `cpu`)
- `WRK2_CONNECTIONS` (default `1000`)
- `WRK2_DURATION` (default `30s`)
- `WRK2_RATE` (default `50000`)
- `WRK2_URL` (default `http://127.0.0.1:8080/`)
- `WRK2_EXTRA` (extra flags)

Swoole HTTP compare (`benchmarks/compare-http-servers.sh`):
- `COMPARE_HOST` (default `127.0.0.1`)
- `COMPARE_PORT` (default `8080`)
- `COMPARE_CONCURRENCY` (default `1000`)
- `COMPARE_REQUESTS` (default `100000`)
- `COMPARE_SAMPLE_EVERY` (default `5`)
- `COMPARE_RUNS` (default `1`)
- `COMPARE_BENCH_KEEP_ALIVE` (default `true`)
- `COMPARE_BENCH_TIMEOUT` (default `10`)
- `COMPARE_BACKEND_HOST` (default `127.0.0.1`)
- `COMPARE_BACKEND_PORT` (default `5678`)
- `COMPARE_BACKEND_WORKERS` (optional)
- `COMPARE_WORKERS` (default `8`)
- `COMPARE_DISPATCH_MODE` (default `3`)
- `COMPARE_REACTOR_NUM` (default `16`)
- `COMPARE_BACKEND_POOL_SIZE` (default `2048`)
- `COMPARE_KEEPALIVE_TIMEOUT` (default `10`)
- `COMPARE_OPEN_HTTP2` (default `false`)
- `COMPARE_FAST_ASSUME_OK` (default `true`)
- `COMPARE_SERVER_MODE` (default `base`)

Swoole TCP compare (`benchmarks/compare-tcp-servers.sh`):
- `COMPARE_HOST` (default `127.0.0.1`)
- `COMPARE_PORT` (default `15433`)
- `COMPARE_PROTOCOL` (default `mysql`)
- `COMPARE_CONCURRENCY` (default `2000`)
- `COMPARE_CONNECTIONS` (default `100000`)
- `COMPARE_PAYLOAD_BYTES` (default `0`)
- `COMPARE_TARGET_BYTES` (default `0`)
- `COMPARE_PERSISTENT` (default `false`)
- `COMPARE_STREAM_BYTES` (default `0`)
- `COMPARE_STREAM_DURATION` (default `0`)
- `COMPARE_ECHO_NEWLINE` (default `false`)
- `COMPARE_TIMEOUT` (default `10`)
- `COMPARE_SAMPLE_EVERY` (default `5`)
- `COMPARE_RUNS` (default `1`)
- `COMPARE_MODE` (`single` or `match`, default `single`)
- `COMPARE_CORO_PROCESSES` (optional override)
- `COMPARE_CORO_REACTOR_NUM` (optional override)
- `COMPARE_BACKEND_HOST` (default `127.0.0.1`)
- `COMPARE_BACKEND_PORT` (default `15432`)
- `COMPARE_BACKEND_WORKERS` (optional)
- `COMPARE_BACKEND_START` (default `true`)
- `COMPARE_WORKERS` (default `8`)
- `COMPARE_REACTOR_NUM` (default `16`)
- `COMPARE_DISPATCH_MODE` (default `2`)

## Notes

- For realistic max numbers, run on a tuned Linux host (see `PERFORMANCE.md`).
- Running in Docker on macOS will be bottlenecked by the VM and host networking.
