# Benchmarks

## Quick Start

Start a backend, then run the benchmark against it:

```bash
# HTTP
php benchmarks/http-backend.php &
php benchmarks/http.php

# TCP
php benchmarks/tcp-backend.php &
php benchmarks/tcp.php
```

## HTTP Benchmark

```bash
BENCH_CONCURRENCY=5000 BENCH_REQUESTS=2000000 php benchmarks/http.php
```

| Variable | Default | Description |
|----------|---------|-------------|
| `BENCH_HOST` | `localhost` | Target host |
| `BENCH_PORT` | `8080` | Target port |
| `BENCH_CONCURRENCY` | `cpu*500` | Concurrent workers |
| `BENCH_REQUESTS` | `concurrency*500` | Total requests |
| `BENCH_KEEP_ALIVE` | `true` | Reuse connections |
| `BENCH_TIMEOUT` | `10` | Request timeout (seconds) |

## TCP Benchmark (C client — preferred)

The PHP bench client has its own per-process ceiling (~10-11k ops/sec) that
caps measured throughput well below the proxy's actual capacity. For realistic
numbers use the C load generator, which pins the proxy workers at 80-90% CPU:

```bash
composer bench:build                      # gcc -O2 -pthread -o benchmarks/tcpbench benchmarks/tcpbench.c

# Request/response throughput (1 KB payloads)
./benchmarks/tcpbench rr   -h 127.0.0.1 -p 5432 -c 200 -d 10 -s 1024

# Connection rate
./benchmarks/tcpbench rate -h 127.0.0.1 -p 5432 -c 200 -d 10

# Bulk throughput (64 KB payloads)
./benchmarks/tcpbench rr   -h 127.0.0.1 -p 5432 -c 200 -d 10 -s 65536
```

Flags: `-h host`, `-p port`, `-c concurrency`, `-d duration`, `-s payload_size`.
Each thread owns one TCP connection, uses blocking IO, and counts ops via
atomic counters. Modes: `rr` (persistent req/resp loop), `rate` (connect →
handshake → close loop).

## TCP Benchmark (PHP client — legacy)

Functional testing only; numbers are latency-bound, not proxy-bound.

```bash
# Connection rate (no payload)
BENCH_PAYLOAD_BYTES=0 BENCH_CONNECTIONS=400000 php benchmarks/tcp.php

# Throughput (64KB payload)
BENCH_PAYLOAD_BYTES=65536 BENCH_TARGET_BYTES=17179869184 php benchmarks/tcp.php

# Sustained streaming
BENCH_PERSISTENT=true BENCH_STREAM_DURATION=60 php benchmarks/tcp.php
```

| Variable | Default | Description |
|----------|---------|-------------|
| `BENCH_HOST` | `localhost` | Target host |
| `BENCH_PORT` | `5432` | Target port |
| `BENCH_PROTOCOL` | auto | `postgres` or `mysql` (based on port) |
| `BENCH_CONCURRENCY` | `cpu*500` | Concurrent workers |
| `BENCH_CONNECTIONS` | derived | Total connections |
| `BENCH_PAYLOAD_BYTES` | `65536` | Bytes per connection |
| `BENCH_TARGET_BYTES` | `8GB` | Total bytes target |
| `BENCH_PERSISTENT` | `false` | Keep connections open |
| `BENCH_STREAM_DURATION` | `0` | Stream duration in seconds |
| `BENCH_TIMEOUT` | `10` | Connection timeout (seconds) |

## Kernel Tuning

```bash
sudo ./benchmarks/setup.sh              # Aggressive (benchmarks)
sudo ./benchmarks/setup.sh --production # Conservative (production)
sudo ./benchmarks/setup.sh --persist    # Survive reboots
```

## Reference Numbers (8-core, 32GB RAM)

| Metric | Result |
|--------|--------|
| Peak concurrent connections | 672,348 |
| Memory per connection | ~33 KB |
| Connection rate (sustained) | 18,067/sec |
| CPU at peak | ~60% |
