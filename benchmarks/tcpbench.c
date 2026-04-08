// tcpbench — high-performance TCP load generator for the proxy benchmarks.
//
// Two modes:
//
//   ./tcpbench rr -h 127.0.0.1 -p 25432 -c 50 -d 10 -s 1024
//     Request/response mode: opens C persistent connections and loops
//     send(payload) / recv(payload) for D seconds, reporting total ops/sec
//     and average per-op latency. Measures forwarding hot path throughput.
//
//   ./tcpbench rate -h 127.0.0.1 -p 25432 -c 100 -d 10
//     Connection rate mode: C worker threads, each opening a fresh TCP
//     connection, doing the postgres handshake, reading one response, and
//     closing. Reports total new-connections/sec. Measures accept path.
//
// Each worker thread uses blocking IO with its own socket. N threads =
// true kernel-level parallelism (unlike coroutine-based clients which
// serialize through one event loop).
//
// Compile:
//   gcc -O2 -pthread -o tcpbench tcpbench.c
//
// Expected to saturate the proxy far beyond what PHP bench clients can.

#define _GNU_SOURCE
#include <arpa/inet.h>
#include <errno.h>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <pthread.h>
#include <stdatomic.h>
#include <stdbool.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/time.h>
#include <time.h>
#include <unistd.h>

// Postgres startup message: protocol version 3.0 + user/database keys
static const unsigned char PG_STARTUP[] = {
    0x00, 0x00, 0x00, 0x26, // length = 38
    0x00, 0x03, 0x00, 0x00, // protocol 3.0
    'u', 's', 'e', 'r', 0, 'p', 'o', 's', 't', 'g', 'r', 'e', 's', 0,
    'd', 'a', 't', 'a', 'b', 'a', 's', 'e', 0,
    'd', 'b', '-', 'a', 'b', 'c', '1', '2', '3', 0,
    0
};

typedef struct {
    const char *host;
    int port;
    int duration;
    int payload_size;
    char *payload;
    atomic_ullong total_ops;
    atomic_ullong total_bytes;
    atomic_int done;
} shared_t;

static uint64_t now_ns(void) {
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (uint64_t)ts.tv_sec * 1000000000ULL + ts.tv_nsec;
}

static int connect_to(const char *host, int port) {
    int fd = socket(AF_INET, SOCK_STREAM, 0);
    if (fd < 0) return -1;

    int one = 1;
    setsockopt(fd, IPPROTO_TCP, TCP_NODELAY, &one, sizeof(one));

    struct sockaddr_in addr;
    memset(&addr, 0, sizeof(addr));
    addr.sin_family = AF_INET;
    addr.sin_port = htons(port);
    inet_pton(AF_INET, host, &addr.sin_addr);

    if (connect(fd, (struct sockaddr *)&addr, sizeof(addr)) < 0) {
        close(fd);
        return -1;
    }
    return fd;
}

static ssize_t send_all(int fd, const void *buf, size_t len) {
    const char *p = buf;
    size_t left = len;
    while (left > 0) {
        ssize_t n = send(fd, p, left, 0);
        if (n <= 0) return -1;
        p += n;
        left -= n;
    }
    return len;
}

static ssize_t recv_all(int fd, void *buf, size_t len) {
    char *p = buf;
    size_t left = len;
    while (left > 0) {
        ssize_t n = recv(fd, p, left, 0);
        if (n <= 0) return -1;
        p += n;
        left -= n;
    }
    return len;
}

// Request/response worker: persistent connection, tight loop.
static void *rr_worker(void *arg) {
    shared_t *s = arg;

    int fd = connect_to(s->host, s->port);
    if (fd < 0) return NULL;

    // Handshake so the proxy routes us.
    if (send_all(fd, PG_STARTUP, sizeof(PG_STARTUP)) < 0) {
        close(fd);
        return NULL;
    }
    char hs[4096];
    ssize_t hs_n = recv(fd, hs, sizeof(hs), 0);
    if (hs_n <= 0) {
        close(fd);
        return NULL;
    }

    char *recv_buf = malloc(s->payload_size);
    if (!recv_buf) {
        close(fd);
        return NULL;
    }

    unsigned long long ops = 0;
    unsigned long long bytes = 0;

    while (!atomic_load_explicit(&s->done, memory_order_relaxed)) {
        if (send_all(fd, s->payload, s->payload_size) < 0) break;
        if (recv_all(fd, recv_buf, s->payload_size) < 0) break;
        ops++;
        bytes += s->payload_size;
    }

    atomic_fetch_add_explicit(&s->total_ops, ops, memory_order_relaxed);
    atomic_fetch_add_explicit(&s->total_bytes, bytes, memory_order_relaxed);

    free(recv_buf);
    close(fd);
    return NULL;
}

// Connection rate worker: open, handshake, read response, close, repeat.
static void *rate_worker(void *arg) {
    shared_t *s = arg;
    unsigned long long ops = 0;
    char hs[4096];

    while (!atomic_load_explicit(&s->done, memory_order_relaxed)) {
        int fd = connect_to(s->host, s->port);
        if (fd < 0) continue;
        if (send_all(fd, PG_STARTUP, sizeof(PG_STARTUP)) < 0) {
            close(fd);
            continue;
        }
        ssize_t n = recv(fd, hs, sizeof(hs), 0);
        if (n > 0) ops++;
        close(fd);
    }

    atomic_fetch_add_explicit(&s->total_ops, ops, memory_order_relaxed);
    return NULL;
}

static void usage(const char *argv0) {
    fprintf(stderr,
            "usage: %s rr|rate [-h host] [-p port] [-c concurrency] "
            "[-d duration] [-s payload_size]\n",
            argv0);
    exit(1);
}

int main(int argc, char **argv) {
    if (argc < 2) usage(argv[0]);

    const char *mode = argv[1];
    bool is_rr = strcmp(mode, "rr") == 0;
    bool is_rate = strcmp(mode, "rate") == 0;
    if (!is_rr && !is_rate) usage(argv[0]);

    shared_t s = {
        .host = "127.0.0.1",
        .port = 25432,
        .duration = 10,
        .payload_size = 1024,
    };
    int concurrency = 50;

    for (int i = 2; i < argc; i++) {
        if (strcmp(argv[i], "-h") == 0 && i + 1 < argc) {
            s.host = argv[++i];
        } else if (strcmp(argv[i], "-p") == 0 && i + 1 < argc) {
            s.port = atoi(argv[++i]);
        } else if (strcmp(argv[i], "-c") == 0 && i + 1 < argc) {
            concurrency = atoi(argv[++i]);
        } else if (strcmp(argv[i], "-d") == 0 && i + 1 < argc) {
            s.duration = atoi(argv[++i]);
        } else if (strcmp(argv[i], "-s") == 0 && i + 1 < argc) {
            s.payload_size = atoi(argv[++i]);
        } else {
            usage(argv[0]);
        }
    }

    if (is_rr) {
        s.payload = malloc(s.payload_size);
        if (!s.payload) {
            perror("malloc");
            return 1;
        }
        memset(s.payload, 'a', s.payload_size);
    }

    atomic_store(&s.total_ops, 0);
    atomic_store(&s.total_bytes, 0);
    atomic_store(&s.done, 0);

    pthread_t *threads = calloc(concurrency, sizeof(pthread_t));
    if (!threads) {
        perror("calloc");
        return 1;
    }

    uint64_t t0 = now_ns();
    for (int i = 0; i < concurrency; i++) {
        pthread_create(&threads[i], NULL, is_rr ? rr_worker : rate_worker, &s);
    }

    sleep(s.duration);
    atomic_store(&s.done, 1);

    for (int i = 0; i < concurrency; i++) {
        pthread_join(threads[i], NULL);
    }
    uint64_t t1 = now_ns();

    double elapsed = (t1 - t0) / 1e9;
    unsigned long long ops = atomic_load(&s.total_ops);
    unsigned long long bytes = atomic_load(&s.total_bytes);

    printf("mode=%s host=%s:%d concurrency=%d duration=%.2fs\n",
           mode, s.host, s.port, concurrency, elapsed);
    printf("total_ops=%llu\n", ops);
    printf("ops_per_sec=%.0f\n", ops / elapsed);
    if (is_rr) {
        printf("bytes=%llu\n", bytes);
        printf("throughput_gbps=%.3f\n", (double)bytes / elapsed / (1024.0 * 1024.0 * 1024.0));
        printf("avg_latency_us=%.2f\n", (elapsed / (ops / (double)concurrency)) * 1e6);
    }

    free(threads);
    if (s.payload) free(s.payload);
    return 0;
}
