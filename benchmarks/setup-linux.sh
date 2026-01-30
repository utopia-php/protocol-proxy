#!/bin/sh
#
# Linux Performance Tuning for TCP Proxy Benchmarks
#
# Run as root: sudo ./setup-linux.sh
#
# This script optimizes the system for high-throughput, low-latency TCP proxying.
# Changes are temporary (until reboot) unless you pass --persist
#
set -e

PERSIST=0
if [ "$1" = "--persist" ]; then
    PERSIST=1
fi

# Check if running as root
if [ "$(id -u)" -ne 0 ]; then
    echo "Error: This script must be run as root (sudo)"
    exit 1
fi

echo "=== Linux TCP Proxy Performance Tuning ==="
echo ""

# -----------------------------------------------------------------------------
# 1. File Descriptor Limits
# -----------------------------------------------------------------------------
echo "[1/6] Setting file descriptor limits..."

# Current session
ulimit -n 2000000 2>/dev/null || ulimit -n 1000000 2>/dev/null || ulimit -n 500000

# System-wide
sysctl -w fs.file-max=2000000 >/dev/null
sysctl -w fs.nr_open=2000000 >/dev/null

if [ $PERSIST -eq 1 ]; then
    cat >> /etc/security/limits.conf << 'EOF'
# TCP Proxy Performance Tuning
*               soft    nofile          2000000
*               hard    nofile          2000000
root            soft    nofile          2000000
root            hard    nofile          2000000
EOF
    echo "fs.file-max = 2000000" >> /etc/sysctl.d/99-tcp-proxy.conf
    echo "fs.nr_open = 2000000" >> /etc/sysctl.d/99-tcp-proxy.conf
fi

echo "  - fs.file-max = 2000000"
echo "  - fs.nr_open = 2000000"

# -----------------------------------------------------------------------------
# 2. TCP Connection Backlog
# -----------------------------------------------------------------------------
echo "[2/6] Tuning TCP connection backlog..."

sysctl -w net.core.somaxconn=65535 >/dev/null
sysctl -w net.ipv4.tcp_max_syn_backlog=65535 >/dev/null
sysctl -w net.core.netdev_max_backlog=65535 >/dev/null

if [ $PERSIST -eq 1 ]; then
    cat >> /etc/sysctl.d/99-tcp-proxy.conf << 'EOF'
net.core.somaxconn = 65535
net.ipv4.tcp_max_syn_backlog = 65535
net.core.netdev_max_backlog = 65535
EOF
fi

echo "  - net.core.somaxconn = 65535"
echo "  - net.ipv4.tcp_max_syn_backlog = 65535"
echo "  - net.core.netdev_max_backlog = 65535"

# -----------------------------------------------------------------------------
# 3. Socket Buffer Sizes
# -----------------------------------------------------------------------------
echo "[3/6] Tuning socket buffer sizes..."

# Max buffer sizes (128MB)
sysctl -w net.core.rmem_max=134217728 >/dev/null
sysctl -w net.core.wmem_max=134217728 >/dev/null

# TCP buffer auto-tuning: min, default, max
sysctl -w net.ipv4.tcp_rmem="4096 87380 67108864" >/dev/null
sysctl -w net.ipv4.tcp_wmem="4096 65536 67108864" >/dev/null

# Default socket buffer sizes
sysctl -w net.core.rmem_default=262144 >/dev/null
sysctl -w net.core.wmem_default=262144 >/dev/null

if [ $PERSIST -eq 1 ]; then
    cat >> /etc/sysctl.d/99-tcp-proxy.conf << 'EOF'
net.core.rmem_max = 134217728
net.core.wmem_max = 134217728
net.ipv4.tcp_rmem = 4096 87380 67108864
net.ipv4.tcp_wmem = 4096 65536 67108864
net.core.rmem_default = 262144
net.core.wmem_default = 262144
EOF
fi

echo "  - net.core.rmem_max = 128MB"
echo "  - net.core.wmem_max = 128MB"
echo "  - net.ipv4.tcp_rmem = 4KB/85KB/64MB"
echo "  - net.ipv4.tcp_wmem = 4KB/64KB/64MB"

# -----------------------------------------------------------------------------
# 4. TCP Performance Optimizations
# -----------------------------------------------------------------------------
echo "[4/6] Enabling TCP performance optimizations..."

# Enable TCP Fast Open (client + server)
sysctl -w net.ipv4.tcp_fastopen=3 >/dev/null

# Reduce TIME_WAIT sockets
sysctl -w net.ipv4.tcp_fin_timeout=10 >/dev/null
sysctl -w net.ipv4.tcp_tw_reuse=1 >/dev/null

# Disable slow start after idle (keep cwnd high)
sysctl -w net.ipv4.tcp_slow_start_after_idle=0 >/dev/null

# Don't cache TCP metrics (each connection starts fresh)
sysctl -w net.ipv4.tcp_no_metrics_save=1 >/dev/null

# Enable TCP window scaling
sysctl -w net.ipv4.tcp_window_scaling=1 >/dev/null

# Enable selective acknowledgments
sysctl -w net.ipv4.tcp_sack=1 >/dev/null

# Increase local port range
sysctl -w net.ipv4.ip_local_port_range="1024 65535" >/dev/null

# Allow more orphan sockets
sysctl -w net.ipv4.tcp_max_orphans=262144 >/dev/null

# Increase max TIME_WAIT sockets
sysctl -w net.ipv4.tcp_max_tw_buckets=2000000 >/dev/null

if [ $PERSIST -eq 1 ]; then
    cat >> /etc/sysctl.d/99-tcp-proxy.conf << 'EOF'
net.ipv4.tcp_fastopen = 3
net.ipv4.tcp_fin_timeout = 10
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_slow_start_after_idle = 0
net.ipv4.tcp_no_metrics_save = 1
net.ipv4.tcp_window_scaling = 1
net.ipv4.tcp_sack = 1
net.ipv4.ip_local_port_range = 1024 65535
net.ipv4.tcp_max_orphans = 262144
net.ipv4.tcp_max_tw_buckets = 2000000
EOF
fi

echo "  - tcp_fastopen = 3 (client+server)"
echo "  - tcp_fin_timeout = 10s"
echo "  - tcp_tw_reuse = 1"
echo "  - tcp_slow_start_after_idle = 0"
echo "  - ip_local_port_range = 1024-65535"

# -----------------------------------------------------------------------------
# 5. Memory Optimizations
# -----------------------------------------------------------------------------
echo "[5/6] Tuning memory settings..."

# TCP memory limits: min, pressure, max (in pages, 4KB each)
sysctl -w net.ipv4.tcp_mem="786432 1048576 1572864" >/dev/null

# Disable swap for consistent performance (optional, be careful)
# sysctl -w vm.swappiness=0 >/dev/null

# Increase max memory map areas
sysctl -w vm.max_map_count=262144 >/dev/null

if [ $PERSIST -eq 1 ]; then
    cat >> /etc/sysctl.d/99-tcp-proxy.conf << 'EOF'
net.ipv4.tcp_mem = 786432 1048576 1572864
vm.max_map_count = 262144
EOF
fi

echo "  - tcp_mem = 3GB/4GB/6GB"
echo "  - vm.max_map_count = 262144"

# -----------------------------------------------------------------------------
# 6. Optional: Disable CPU Frequency Scaling (for benchmarks)
# -----------------------------------------------------------------------------
echo "[6/6] Checking CPU governor..."

if [ -f /sys/devices/system/cpu/cpu0/cpufreq/scaling_governor ]; then
    CURRENT_GOV=$(cat /sys/devices/system/cpu/cpu0/cpufreq/scaling_governor)
    echo "  - Current governor: $CURRENT_GOV"

    if [ "$CURRENT_GOV" != "performance" ]; then
        echo "  - Setting governor to 'performance' for all CPUs..."
        for cpu in /sys/devices/system/cpu/cpu*/cpufreq/scaling_governor; do
            echo "performance" > "$cpu" 2>/dev/null || true
        done
        echo "  - Done (temporary, resets on reboot)"
    fi
else
    echo "  - CPU frequency scaling not available"
fi

# -----------------------------------------------------------------------------
# Summary
# -----------------------------------------------------------------------------
echo ""
echo "=== Tuning Complete ==="
echo ""
echo "Current limits:"
echo "  - File descriptors: $(ulimit -n)"
echo "  - Max connections: $(sysctl -n net.core.somaxconn)"
echo "  - Local ports: $(sysctl -n net.ipv4.ip_local_port_range)"
echo ""

if [ $PERSIST -eq 1 ]; then
    echo "Settings persisted to /etc/sysctl.d/99-tcp-proxy.conf"
    echo "Run 'sysctl -p /etc/sysctl.d/99-tcp-proxy.conf' to reload"
else
    echo "Settings are temporary (lost on reboot)"
    echo "Run with --persist to make permanent"
fi

echo ""
echo "Ready to benchmark! Run:"
echo "  BENCH_CONCURRENCY=4000 BENCH_CONNECTIONS=400000 php benchmarks/tcp.php"
echo ""
