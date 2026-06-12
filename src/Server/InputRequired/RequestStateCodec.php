<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 *
 * Filename: Server/InputRequired/RequestStateCodec.php
 */

declare(strict_types=1);

namespace Mcp\Server\InputRequired;

/**
 * Integrity protection for SEP-2322 `requestState`.
 *
 * The spec makes requestState attacker-controlled input: when it
 * influences behavior, servers MUST protect its integrity and MUST reject
 * state that fails verification. This codec signs the JSON payload with
 * HMAC-SHA256 and embeds an expiry (the spec's SHOULD-level replay
 * mitigation); decode() returns null for anything tampered, malformed, or
 * expired.
 *
 * The secret defaults to a file under the system temp directory keyed to
 * this SDK installation, so the multi-process deployments typical of PHP
 * hosting (FPM pools, the multi-worker CLI server) all verify each
 * other's state. Production deployments can supply their own secret.
 */
final class RequestStateCodec
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttlSeconds = 300,
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('RequestStateCodec secret must not be empty');
        }
    }

    /**
     * Codec backed by a shared per-installation secret file, created on
     * first use.
     *
     * Initialization is race-safe: the file is opened with the exclusive
     * 'x' mode, so exactly one process ever WRITES a secret — every
     * concurrent loser reads the winner's bytes (with a short bounded
     * wait for the winner's write to land). When the secret can be
     * neither created nor read, this throws instead of silently falling
     * back to a process-local secret, because such a secret would make
     * every cross-worker requestState fail verification in ways that are
     * miserable to diagnose; operators of read-only environments should
     * supply an explicit secret via the constructor /
     * McpServer::inputStateCodec().
     *
     * @throws \RuntimeException When no shared secret can be established
     */
    public static function withFileSecret(?string $path = null, int $ttlSeconds = 300): self
    {
        $path ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp-mrtr-secret-' . md5(__DIR__);

        // Fast path: an earlier process already established the secret.
        $secret = @file_get_contents($path);
        if (is_string($secret) && strlen($secret) >= 32) {
            return new self($secret, $ttlSeconds);
        }

        // Exclusive create: 'x' mode fails if the file exists, so at most
        // one process wins the race and writes.
        $handle = @fopen($path, 'xb');
        if ($handle !== false) {
            $secret = bin2hex(random_bytes(32));
            $written = fwrite($handle, $secret);
            fflush($handle);
            fclose($handle);
            @chmod($path, 0600);
            if ($written === strlen($secret)) {
                return new self($secret, $ttlSeconds);
            }
            // Partial write (disk full, quota): remove the unusable file
            // so a later attempt can establish a complete secret.
            @unlink($path);
            throw new \RuntimeException(
                "Failed to write the shared MRTR state secret at '$path'"
            );
        }

        // Lost the race (or the file exists but was empty above): wait
        // briefly for the winner's bytes to become visible.
        for ($attempt = 0; $attempt < 50; $attempt++) {
            clearstatcache(true, $path);
            $secret = @file_get_contents($path);
            if (is_string($secret) && strlen($secret) >= 32) {
                return new self($secret, $ttlSeconds);
            }
            usleep(10000);
        }

        throw new \RuntimeException(
            "Unable to establish the shared MRTR state secret at '$path' (not creatable or readable). "
            . 'Configure an explicit secret via McpServer::inputStateCodec(new RequestStateCodec($secret)) '
            . 'or pass a writable path to RequestStateCodec::withFileSecret().'
        );
    }

    /**
     * Sign a payload into an opaque URL-safe state string. Adds iat/exp
     * and a nonce (so consecutive rounds always produce distinct states).
     *
     * @param array<string, mixed> $payload
     */
    public function encode(array $payload): string
    {
        $payload['iat'] = time();
        $payload['exp'] = time() + $this->ttlSeconds;
        $payload['nonce'] = bin2hex(random_bytes(8));

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('requestState payload could not be encoded');
        }
        $mac = hash_hmac('sha256', $json, $this->secret);
        return rtrim(strtr(base64_encode($json . '.' . $mac), '+/', '-_'), '=');
    }

    /**
     * Verify and decode a state string. Null on any failure: bad base64,
     * missing/incorrect MAC, malformed payload, or expiry passed.
     *
     * @return array<string, mixed>|null
     */
    public function decode(string $state): ?array
    {
        $padded = strtr($state, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $raw = base64_decode($padded, true);
        if (!is_string($raw)) {
            return null;
        }

        $dot = strrpos($raw, '.');
        if ($dot === false) {
            return null;
        }
        $json = substr($raw, 0, $dot);
        $mac = substr($raw, $dot + 1);

        if (!hash_equals(hash_hmac('sha256', $json, $this->secret), $mac)) {
            return null;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }
        $exp = $payload['exp'] ?? null;
        if (!is_int($exp) || $exp < time()) {
            return null;
        }
        return $payload;
    }
}
