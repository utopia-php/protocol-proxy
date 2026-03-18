#!/bin/sh
#
# Dry-run test for bootstrap script - checks each step without running benchmarks
#
set -e

echo "=== Testing Bootstrap Script ==="

# Check if running as root
if [ "$(id -u)" -ne 0 ]; then
    echo "Error: Run as root (sudo)"
    exit 1
fi

echo "[1/6] Testing package manager..."
if command -v apt-get > /dev/null 2>&1; then
    echo "  OK: apt-get available"
    apt-get update -qq
elif command -v dnf > /dev/null 2>&1; then
    echo "  OK: dnf available"
else
    echo "  FAIL: No supported package manager"
    exit 1
fi

echo "[2/6] Testing PHP installation..."
export DEBIAN_FRONTEND=noninteractive

# Try installing PHP
apt-get install -y -qq software-properties-common > /dev/null 2>&1 || true
add-apt-repository -y ppa:ondrej/php > /dev/null 2>&1 || true
apt-get update -qq > /dev/null 2>&1

if apt-get install -y -qq php8.3-cli php8.3-dev php8.3-xml php8.3-curl php8.3-mbstring php8.3-zip > /dev/null 2>&1; then
    echo "  OK: PHP 8.3 installed"
elif apt-get install -y -qq php8.2-cli php8.2-dev php8.2-xml php8.2-curl php8.2-mbstring php8.2-zip > /dev/null 2>&1; then
    echo "  OK: PHP 8.2 installed"
else
    echo "  FAIL: Could not install PHP"
    exit 1
fi

php -v | head -1

echo "[3/6] Testing pecl/Swoole..."
apt-get install -y -qq php-pear php8.3-dev 2>/dev/null || apt-get install -y -qq php-pear php8.2-dev 2>/dev/null || true

if php -m | grep -q swoole; then
    echo "  OK: Swoole already loaded"
else
    echo "  Installing Swoole via pecl..."
    printf "\n\n\n\n\n\n" | pecl install swoole > /dev/null 2>&1

    # Enable extension
    PHP_INI_DIR=$(php -i | grep "Scan this dir" | cut -d'>' -f2 | tr -d ' ')
    if [ -n "$PHP_INI_DIR" ] && [ -d "$PHP_INI_DIR" ]; then
        echo "extension=swoole.so" > "$PHP_INI_DIR/20-swoole.ini"
    fi

    if php -m | grep -q swoole; then
        echo "  OK: Swoole installed and loaded"
    else
        echo "  FAIL: Swoole not loading"
        echo "  Debug: php -m output:"
        php -m | grep -i swoole || echo "  (not found)"
        exit 1
    fi
fi

echo "[4/6] Testing Composer..."
apt-get install -y -qq git unzip curl > /dev/null 2>&1
curl -sS https://getcomposer.org/installer | php -- --quiet --install-dir=/usr/local/bin --filename=composer
echo "  OK: Composer $(composer --version 2>/dev/null | cut -d' ' -f3)"

echo "[5/6] Testing git clone..."
cd /tmp
rm -rf proxy-test
git clone --depth 1 -b dev https://github.com/utopia-php/proxy.git proxy-test > /dev/null 2>&1
cd proxy-test
echo "  OK: Cloned successfully"

echo "[6/6] Testing composer install..."
composer install --no-interaction --no-progress --quiet
echo "  OK: Dependencies installed"

echo ""
echo "=== All Checks Passed ==="
echo ""
echo "Quick benchmark test (10 connections):"
BENCH_CONCURRENCY=5 BENCH_CONNECTIONS=10 BENCH_PAYLOAD_BYTES=0 php benchmarks/tcp.php

echo ""
echo "Bootstrap script should work. Run the full version:"
echo "  curl -sL https://raw.githubusercontent.com/utopia-php/proxy/dev/benchmarks/bootstrap-droplet.sh | sudo bash"
