#!/bin/sh
#
# One-shot benchmark runner for fresh Linux droplet
#
# Usage (as root on fresh Ubuntu 22.04/24.04):
#   curl -sL https://raw.githubusercontent.com/utopia-php/protocol-proxy/dev/benchmarks/bootstrap-droplet.sh | bash
#
# Or clone and run:
#   git clone https://github.com/utopia-php/protocol-proxy.git
#   cd protocol-proxy && sudo ./benchmarks/bootstrap-droplet.sh
#
set -e

echo "=== TCP Proxy Benchmark Bootstrap ==="
echo ""

# Check if running as root
if [ "$(id -u)" -ne 0 ]; then
    echo "Error: Run as root (sudo)"
    exit 1
fi

# Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
else
    echo "Error: Cannot detect OS"
    exit 1
fi

echo "[1/6] Installing dependencies..."

case "$OS" in
    ubuntu|debian)
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq
        apt-get install -y -qq php8.3-cli php8.3-dev php8.3-xml php8.3-curl \
            php8.3-mbstring php8.3-zip pecl git unzip curl > /dev/null 2>&1 || {
            # Try PHP 8.2 if 8.3 not available
            apt-get install -y -qq php8.2-cli php8.2-dev php8.2-xml php8.2-curl \
                php8.2-mbstring php8.2-zip pecl git unzip curl > /dev/null 2>&1 || {
                # Fallback: add ondrej PPA
                apt-get install -y -qq software-properties-common > /dev/null 2>&1
                add-apt-repository -y ppa:ondrej/php > /dev/null 2>&1
                apt-get update -qq
                apt-get install -y -qq php8.3-cli php8.3-dev php8.3-xml php8.3-curl \
                    php8.3-mbstring php8.3-zip pecl git unzip curl > /dev/null 2>&1
            }
        }
        ;;
    fedora|rhel|centos|rocky|alma)
        dnf install -y -q php-cli php-devel php-xml php-mbstring php-zip \
            git unzip curl > /dev/null 2>&1
        ;;
    *)
        echo "Warning: Unknown OS '$OS', assuming PHP is installed"
        ;;
esac

echo "  - PHP $(php -v | head -1 | cut -d' ' -f2)"

echo "[2/6] Installing Swoole..."

# Check if Swoole already installed
if php -m 2>/dev/null | grep -q swoole; then
    echo "  - Swoole already installed"
else
    pecl install swoole > /dev/null 2>&1 || {
        # Try with options
        echo "" | pecl install swoole > /dev/null 2>&1
    }
    echo "extension=swoole.so" > /etc/php/*/cli/conf.d/20-swoole.ini 2>/dev/null || \
    echo "extension=swoole.so" >> /etc/php.ini 2>/dev/null || true
    echo "  - Swoole installed"
fi

# Verify Swoole
if ! php -m 2>/dev/null | grep -q swoole; then
    echo "Error: Swoole not loaded. Check PHP configuration."
    exit 1
fi

echo "[3/6] Installing Composer..."

if command -v composer > /dev/null 2>&1; then
    echo "  - Composer already installed"
else
    curl -sS https://getcomposer.org/installer | php -- --quiet --install-dir=/usr/local/bin --filename=composer
    echo "  - Composer installed"
fi

echo "[4/6] Cloning protocol-proxy..."

WORKDIR="/tmp/protocol-proxy-bench"
rm -rf "$WORKDIR"

if [ -f "composer.json" ] && grep -q "protocol-proxy" composer.json 2>/dev/null; then
    # Already in the repo
    WORKDIR="$(pwd)"
    echo "  - Using current directory"
else
    git clone --depth 1 -b dev https://github.com/utopia-php/protocol-proxy.git "$WORKDIR" 2>/dev/null
    cd "$WORKDIR"
    echo "  - Cloned to $WORKDIR"
fi

echo "[5/6] Installing PHP dependencies..."

composer install --no-interaction --no-progress --quiet 2>/dev/null
echo "  - Dependencies installed"

echo "[6/6] Applying kernel tuning..."

# Apply benchmark tuning
./benchmarks/setup-linux.sh > /dev/null 2>&1 || {
    # Inline tuning if script fails
    sysctl -w fs.file-max=2000000 > /dev/null 2>&1 || true
    sysctl -w net.core.somaxconn=65535 > /dev/null 2>&1 || true
    sysctl -w net.core.rmem_max=134217728 > /dev/null 2>&1 || true
    sysctl -w net.core.wmem_max=134217728 > /dev/null 2>&1 || true
    sysctl -w net.ipv4.tcp_fastopen=3 > /dev/null 2>&1 || true
    sysctl -w net.ipv4.tcp_tw_reuse=1 > /dev/null 2>&1 || true
    sysctl -w net.ipv4.ip_local_port_range="1024 65535" > /dev/null 2>&1 || true
    ulimit -n 1000000 2>/dev/null || ulimit -n 100000 2>/dev/null || true
}
echo "  - Kernel tuned"

echo ""
echo "=== Bootstrap Complete ==="
echo ""
echo "System info:"
echo "  - CPU: $(nproc) cores"
echo "  - RAM: $(free -h | awk '/^Mem:/{print $2}')"
echo "  - PHP: $(php -v | head -1 | cut -d' ' -f2)"
echo "  - Swoole: $(php -r 'echo SWOOLE_VERSION;')"
echo ""
echo "Running benchmarks..."
echo ""

# Run benchmark
cd "$WORKDIR"

echo "=== TCP Proxy Benchmark (connection rate) ==="
BENCH_PAYLOAD_BYTES=0 \
BENCH_CONCURRENCY=4000 \
BENCH_CONNECTIONS=400000 \
php benchmarks/tcp.php

echo ""
echo "=== TCP Proxy Benchmark (throughput) ==="
BENCH_PAYLOAD_BYTES=65536 \
BENCH_TARGET_BYTES=8589934592 \
BENCH_CONCURRENCY=2000 \
php benchmarks/tcp.php

echo ""
echo "=== Done ==="
echo "Results above. Re-run with different settings:"
echo "  cd $WORKDIR"
echo "  BENCH_CONCURRENCY=8000 BENCH_CONNECTIONS=800000 php benchmarks/tcp.php"
