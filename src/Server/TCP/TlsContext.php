<?php

namespace Utopia\Proxy\Server\TCP;

/**
 * TLS Context Builder
 *
 * Wraps TLS configuration into formats consumable by:
 * - Swoole Server SSL settings (for the event-driven server)
 * - PHP stream_context_create (for coroutine server / manual sockets)
 *
 * Encapsulates the translation from our TLS config to the underlying
 * SSL library parameters.
 *
 * Example:
 * ```php
 * $tls = new TLS(certPath: '/certs/server.crt', keyPath: '/certs/server.key');
 * $ctx = new TlsContext($tls);
 *
 * // For Swoole Server::set()
 * $server->set($ctx->toSwooleConfig());
 *
 * // For stream_context_create
 * $streamCtx = $ctx->toStreamContext();
 * ```
 */
class TlsContext
{
    public function __construct(
        protected TLS $tls,
    ) {
    }

    /**
     * Build Swoole server SSL configuration array
     *
     * Returns settings suitable for Swoole\Server::set() when the server
     * is created with SWOOLE_SOCK_TCP | SWOOLE_SSL socket type.
     *
     * @return array<string, mixed>
     */
    public function toSwooleConfig(): array
    {
        $config = [
            'ssl_cert_file' => $this->tls->certPath,
            'ssl_key_file' => $this->tls->keyPath,
            'ssl_protocols' => $this->tls->minProtocol,
            'ssl_ciphers' => $this->tls->ciphers,
            'ssl_allow_self_signed' => false,
        ];

        if ($this->tls->caPath !== '') {
            $config['ssl_client_cert_file'] = $this->tls->caPath;
        }

        if ($this->tls->requireClientCert) {
            $config['ssl_verify_peer'] = true;
            $config['ssl_verify_depth'] = 10;
        } else {
            $config['ssl_verify_peer'] = false;
        }

        return $config;
    }

    /**
     * Build a PHP stream context resource for SSL connections
     *
     * Returns a context resource that can be used with stream_socket_server,
     * stream_socket_enable_crypto, and similar stream functions.
     *
     * @return resource
     */
    public function toStreamContext(): mixed
    {
        $sslOptions = [
            'local_cert' => $this->tls->certPath,
            'local_pk' => $this->tls->keyPath,
            'disable_compression' => true,
            'allow_self_signed' => false,
            'ciphers' => $this->tls->ciphers,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
        ];

        if ($this->tls->caPath !== '') {
            $sslOptions['cafile'] = $this->tls->caPath;
        }

        if ($this->tls->requireClientCert) {
            $sslOptions['verify_peer'] = true;
            $sslOptions['verify_peer_name'] = false;
            $sslOptions['verify_depth'] = 10;
        } else {
            $sslOptions['verify_peer'] = false;
            $sslOptions['verify_peer_name'] = false;
        }

        return stream_context_create(['ssl' => $sslOptions]);
    }

    /**
     * Get the Swoole socket type flag for TLS-enabled TCP
     *
     * Combines SWOOLE_SOCK_TCP with SWOOLE_SSL when TLS is configured.
     */
    public function getSocketType(): int
    {
        return SWOOLE_SOCK_TCP | SWOOLE_SSL;
    }

    /**
     * Get the underlying TLS configuration
     */
    public function getTls(): TLS
    {
        return $this->tls;
    }
}
