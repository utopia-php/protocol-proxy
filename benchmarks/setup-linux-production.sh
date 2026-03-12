#!/bin/sh
#
# Linux Production Tuning for TCP Proxy
#
# Run as root: sudo ./setup-linux-production.sh
#
# Conservative settings safe for production database proxies.
# Optimizes for reliability + performance, not max benchmark numbers.
#
set -e

PERSIST=0
if [ "$1" = "--persist" ]; then
    PERSIST=1
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "Error: This script must be run as root (sudo)"
    exit 1
fi

echo "=== Linux TCP Proxy Production Tuning ==="
echo ""

SYSCTL_FILE="/etc/sysctl.d/99-tcp-proxy-prod.conf"

# -----------------------------------------------------------------------------
# 1. File Descriptor Limits (safe, just capacity)
# -----------------------------------------------------------------------------
echo "[1/5] Setting file descriptor limits..."

sysctl -w fs.file-max=1000000 >/dev/null
sysctl -w fs.nr_open=1000000 >/dev/null

if [ $PERSIST -eq 1 ]; then
    cat >> /etc/security/limits.conf << 'EOF'
# TCP Proxy Production Tuning
*               soft    nofile          1000000
*               hard    nofile          1000000
root            soft    nofile          1000000
root            hard    nofile          1000000
EOF
    echo "fs.file-max = 1000000" >> "$SYSCTL_FILE"
    echo "fs.nr_open = 1000000" >> "$SYSCTL_FILE"
fi

echo "  - fs.file-max = 1000000"

# -----------------------------------------------------------------------------
# 2. TCP Connection Backlog (safe, prevents SYN drops)
# -----------------------------------------------------------------------------
echo "[2/5] Tuning TCP connection backlog..."

sysctl -w net.core.somaxconn=32768 >/dev/null
sysctl -w net.ipv4.tcp_max_syn_backlog=32768 >/dev/null
sysctl -w net.core.netdev_max_backlog=32768 >/dev/null

if [ $PERSIST -eq 1 ]; then
    cat >> "$SYSCTL_FILE" << 'EOF'
net.core.somaxconn = 32768
net.ipv4.tcp_max_syn_backlog = 32768
net.core.netdev_max_backlog = 32768
EOF
fi

echo "  - net.core.somaxconn = 32768"

# -----------------------------------------------------------------------------
# 3. Socket Buffer Sizes (safe, just memory)
# -----------------------------------------------------------------------------
echo "[3/5] Tuning socket buffer sizes..."

sysctl -w net.core.rmem_max=67108864 >/dev/null
sysctl -w net.core.wmem_max=67108864 >/dev/null
sysctl -w net.ipv4.tcp_rmem="4096 87380 33554432" >/dev/null
sysctl -w net.ipv4.tcp_wmem="4096 65536 33554432" >/dev/null

if [ $PERSIST -eq 1 ]; then
    cat >> "$SYSCTL_FILE" << 'EOF'
net.core.rmem_max = 67108864
net.core.wmem_max = 67108864
net.ipv4.tcp_rmem = 4096 87380 33554432
net.ipv4.tcp_wmem = 4096 65536 33554432
EOF
fi

echo "  - Buffer max = 64MB"

# -----------------------------------------------------------------------------
# 4. TCP Optimizations (conservative, production-safe)
# -----------------------------------------------------------------------------
echo "[4/5] Enabling TCP optimizations..."

# TCP Fast Open - safe, optional feature
sysctl -w net.ipv4.tcp_fastopen=3 >/dev/null

# TIME_WAIT handling - conservative
sysctl -w net.ipv4.tcp_fin_timeout=30 >/dev/null      # Default is 60, 30 is safe
sysctl -w net.ipv4.tcp_tw_reuse=1 >/dev/null          # Safe for proxies

# Keep defaults for these (safer):
# tcp_slow_start_after_idle = 1 (default) - prevents burst on congested networks
# tcp_no_metrics_save = 0 (default) - keeps learned route metrics

# Standard optimizations
sysctl -w net.ipv4.tcp_window_scaling=1 >/dev/null
sysctl -w net.ipv4.tcp_sack=1 >/dev/null

# Port range
sysctl -w net.ipv4.ip_local_port_range="1024 65535" >/dev/null

# Orphan/TIME_WAIT limits
sysctl -w net.ipv4.tcp_max_orphans=65536 >/dev/null
sysctl -w net.ipv4.tcp_max_tw_buckets=500000 >/dev/null

# Keepalive - detect dead connections faster
sysctl -w net.ipv4.tcp_keepalive_time=300 >/dev/null
sysctl -w net.ipv4.tcp_keepalive_intvl=30 >/dev/null
sysctl -w net.ipv4.tcp_keepalive_probes=5 >/dev/null

if [ $PERSIST -eq 1 ]; then
    cat >> "$SYSCTL_FILE" << 'EOF'
net.ipv4.tcp_fastopen = 3
net.ipv4.tcp_fin_timeout = 30
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_window_scaling = 1
net.ipv4.tcp_sack = 1
net.ipv4.ip_local_port_range = 1024 65535
net.ipv4.tcp_max_orphans = 65536
net.ipv4.tcp_max_tw_buckets = 500000
net.ipv4.tcp_keepalive_time = 300
net.ipv4.tcp_keepalive_intvl = 30
net.ipv4.tcp_keepalive_probes = 5
EOF
fi

echo "  - tcp_fastopen = 3"
echo "  - tcp_fin_timeout = 30s"
echo "  - tcp_tw_reuse = 1"
echo "  - tcp_keepalive = 300s/30s/5 probes"

# -----------------------------------------------------------------------------
# 5. Memory (conservative)
# -----------------------------------------------------------------------------
echo "[5/5] Tuning memory settings..."

sysctl -w net.ipv4.tcp_mem="524288 786432 1048576" >/dev/null
sysctl -w vm.max_map_count=262144 >/dev/null

if [ $PERSIST -eq 1 ]; then
    cat >> "$SYSCTL_FILE" << 'EOF'
net.ipv4.tcp_mem = 524288 786432 1048576
vm.max_map_count = 262144
EOF
fi

echo "  - tcp_mem = 2GB/3GB/4GB"

# -----------------------------------------------------------------------------
# Summary
# -----------------------------------------------------------------------------
echo ""
echo "=== Production Tuning Complete ==="
echo ""
echo "Current limits:"
echo "  - File descriptors: $(ulimit -n)"
echo "  - Max connections: $(sysctl -n net.core.somaxconn)"
echo "  - Local ports: $(sysctl -n net.ipv4.ip_local_port_range)"
echo ""

if [ $PERSIST -eq 1 ]; then
    echo "Settings persisted to $SYSCTL_FILE"
else
    echo "Settings are temporary. Run with --persist for permanent."
fi

echo ""
echo "Production-safe settings applied."
echo "For benchmarking, use setup-linux.sh instead."
echo ""
