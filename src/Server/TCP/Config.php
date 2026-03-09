<?php

namespace Utopia\Proxy\Server\TCP;

class Config
{
    public readonly int $reactorNum;

    /**
     * @param  array<int, int>  $ports
     */
    public function __construct(
        public readonly string $host = '0.0.0.0',
        public readonly array $ports = [5432, 3306],
        public readonly int $workers = 16,
        public readonly int $maxConnections = 200_000,
        public readonly int $maxCoroutine = 200_000,
        public readonly int $socketBufferSize = 16 * 1024 * 1024,
        public readonly int $bufferOutputSize = 16 * 1024 * 1024,
        ?int $reactorNum = null,
        public readonly int $dispatchMode = 2,
        public readonly bool $enableReusePort = true,
        public readonly int $backlog = 65535,
        public readonly int $packageMaxLength = 32 * 1024 * 1024,
        public readonly int $tcpKeepidle = 30,
        public readonly int $tcpKeepinterval = 10,
        public readonly int $tcpKeepcount = 3,
        public readonly bool $enableCoroutine = true,
        public readonly int $maxWaitTime = 60,
        public readonly int $logLevel = SWOOLE_LOG_ERROR,
        public readonly bool $logConnections = false,
        public readonly int $recvBufferSize = 131072,
        public readonly float $backendConnectTimeout = 5.0,
        public readonly bool $skipValidation = false,
    ) {
        $this->reactorNum = $reactorNum ?? swoole_cpu_num() * 2;
    }
}
