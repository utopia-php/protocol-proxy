#!/bin/bash
#
# Maximum connection stress test
# Pushes as many concurrent connections as possible on a single node
#
# Usage: ./benchmarks/stress-max.sh
#

set -e

# Configuration
NUM_BACKENDS=16
CONNECTIONS_PER_CLIENT=40000
BASE_PORT=15432
REPORT_INTERVAL=3

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo "=============================================="
echo "  TCP Proxy Maximum Connection Stress Test"
echo "=============================================="
echo ""

# Check if running as root
if [ "$(id -u)" -ne 0 ]; then
    echo -e "${RED}Error: Run as root for kernel tuning${NC}"
    exit 1
fi

# System info
CORES=$(nproc)
RAM_GB=$(free -g | awk '/^Mem:/{print $2}')
echo "System: ${CORES} cores, ${RAM_GB}GB RAM"

# Calculate targets based on RAM (42KB per connection, leave 4GB headroom)
MAX_CONNECTIONS=$(( (RAM_GB - 4) * 1024 * 1024 / 42 ))
TARGET_CONNECTIONS=$(( NUM_BACKENDS * CONNECTIONS_PER_CLIENT ))
if [ $TARGET_CONNECTIONS -gt $MAX_CONNECTIONS ]; then
    TARGET_CONNECTIONS=$MAX_CONNECTIONS
    CONNECTIONS_PER_CLIENT=$(( TARGET_CONNECTIONS / NUM_BACKENDS ))
fi

echo "Target: ${TARGET_CONNECTIONS} connections (${NUM_BACKENDS} backends × ${CONNECTIONS_PER_CLIENT} each)"
echo ""

# Cleanup
echo "[1/4] Cleaning up..."
pkill -f 'php.*benchmark' 2>/dev/null || true
pkill -f 'php.*tcp-backend' 2>/dev/null || true
sleep 1

# Kernel tuning
echo "[2/4] Applying kernel tuning..."
sysctl -w fs.file-max=2000000 > /dev/null
sysctl -w fs.nr_open=2000000 > /dev/null
sysctl -w net.core.somaxconn=65535 > /dev/null
sysctl -w net.ipv4.tcp_max_syn_backlog=65535 > /dev/null
sysctl -w net.ipv4.ip_local_port_range="1024 65535" > /dev/null
sysctl -w net.ipv4.tcp_tw_reuse=1 > /dev/null
sysctl -w net.ipv4.tcp_fin_timeout=10 > /dev/null
sysctl -w net.core.netdev_max_backlog=65535 > /dev/null
sysctl -w net.core.rmem_max=134217728 > /dev/null
sysctl -w net.core.wmem_max=134217728 > /dev/null
ulimit -n 1000000

# Start backends
echo "[3/4] Starting ${NUM_BACKENDS} backend servers..."
cd "$(dirname "$0")/.."

for i in $(seq 0 $((NUM_BACKENDS - 1))); do
    port=$((BASE_PORT + i))
    BACKEND_PORT=$port php benchmarks/tcp-backend.php > /dev/null 2>&1 &
done
sleep 2

# Verify backends started
RUNNING_BACKENDS=$(pgrep -f tcp-backend | wc -l)
if [ "$RUNNING_BACKENDS" -lt "$NUM_BACKENDS" ]; then
    echo -e "${RED}Warning: Only ${RUNNING_BACKENDS}/${NUM_BACKENDS} backends started${NC}"
fi

# Start benchmark clients
echo "[4/4] Starting ${NUM_BACKENDS} benchmark clients..."
for i in $(seq 0 $((NUM_BACKENDS - 1))); do
    port=$((BASE_PORT + i))
    BENCH_PORT=$port \
    BENCH_MODE=max_connections \
    BENCH_TARGET_CONNECTIONS=$CONNECTIONS_PER_CLIENT \
    BENCH_REPORT_INTERVAL=60 \
    php benchmarks/tcp-sustained.php > /dev/null 2>&1 &
done

echo ""
echo "=============================================="
echo "  Live Stats (Ctrl+C to stop)"
echo "=============================================="
echo ""

# Monitor loop
START_TIME=$(date +%s)
PEAK_CONNECTIONS=0

cleanup() {
    echo ""
    echo ""
    echo "=============================================="
    echo "  Final Results"
    echo "=============================================="
    echo ""
    echo "Peak connections: ${PEAK_CONNECTIONS}"
    echo "Memory used: $(free -h | awk '/^Mem:/{print $3}')"
    echo ""
    echo "Cleaning up..."
    pkill -f 'php.*benchmark' 2>/dev/null || true
    pkill -f 'php.*tcp-backend' 2>/dev/null || true
    exit 0
}

trap cleanup INT TERM

printf "%-10s | %-12s | %-10s | %-10s | %-8s | %-10s\n" \
    "Time" "Connections" "Target" "Memory" "CPU%" "Status"
printf "%-10s-+-%-12s-+-%-10s-+-%-10s-+-%-8s-+-%-10s\n" \
    "----------" "------------" "----------" "----------" "--------" "----------"

while true; do
    ELAPSED=$(( $(date +%s) - START_TIME ))

    # Get current connections (divide by 2 for localhost)
    TCP_INFO=$(ss -s 2>/dev/null | grep "^TCP:" | head -1)
    TOTAL_SOCKETS=$(echo "$TCP_INFO" | awk '{print $2}')
    ESTAB=$(echo "$TCP_INFO" | grep -oP 'estab \K[0-9]+' || echo "0")
    CONNECTIONS=$((ESTAB / 2))

    # Update peak
    if [ "$CONNECTIONS" -gt "$PEAK_CONNECTIONS" ]; then
        PEAK_CONNECTIONS=$CONNECTIONS
    fi

    # Memory
    MEM_USED=$(free -h | awk '/^Mem:/{print $3}')

    # CPU
    CPU=$(top -bn1 | grep "Cpu(s)" | awk '{print $2}' | cut -d'%' -f1)

    # Status
    if [ "$CONNECTIONS" -ge "$TARGET_CONNECTIONS" ]; then
        STATUS="${GREEN}REACHED${NC}"
    elif [ "$CONNECTIONS" -ge $((TARGET_CONNECTIONS * 90 / 100)) ]; then
        STATUS="${YELLOW}CLOSE${NC}"
    else
        STATUS="RAMPING"
    fi

    # Format time
    MINS=$((ELAPSED / 60))
    SECS=$((ELAPSED % 60))
    TIME_FMT=$(printf "%02d:%02d" $MINS $SECS)

    printf "\r%-10s | %-12s | %-10s | %-10s | %-8s | " \
        "$TIME_FMT" "$CONNECTIONS" "$TARGET_CONNECTIONS" "$MEM_USED" "${CPU}%"
    echo -e "$STATUS"

    sleep $REPORT_INTERVAL
done
