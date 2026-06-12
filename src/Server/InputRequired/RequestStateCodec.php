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
    /**
     * Minimum number of bytes a secret file must hold before it is adopted
     * (the writer always produces 64 hex characters).
     */
    private const MIN_SECRET_BYTES = 32;

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
     * Initialization is race-safe: the file is opened without truncation
     * ('c' mode, created when missing) and the secret is written under an
     * exclusive flock(), so exactly one process ever WRITES a secret —
     * every concurrent initializer blocks on the lock and then adopts the
     * winner's bytes. A stub left behind by a writer that crashed between
     * creating the file and writing the secret is reclaimed under the same
     * lock instead of permanently blocking initialization. When the secret
     * can be neither created nor read, this throws instead of silently
     * falling back to a process-local secret, because such a secret would
     * make every cross-worker requestState fail verification in ways that
     * are miserable to diagnose; operators of read-only environments
     * should supply an explicit secret via the constructor /
     * McpServer::inputStateCodec().
     *
     * Multi-tenant hardening: the DEFAULT path lives in the system temp
     * directory — which shared hosts may expose to many tenants — at a
     * name any co-tenant can predict (it is keyed to the SDK install
     * path). To keep a co-tenant from planting a known secret there before
     * first run, or aiming a symlink somewhere hostile, the default path
     * refuses symlinks and (on POSIX systems) requires the file to be
     * unreadable by group/other and, when ext-posix is available, owned by
     * the current user, failing loudly otherwise. The file's permissions
     * are also locked to 0600 BEFORE any secret byte is written, so the
     * secret is never readable by other users even momentarily. Explicit paths skip the
     * ownership/permission checks by default — the operator chose the
     * location — unless $verifyOwnership is set to true.
     *
     * @param string|null $path Secret file path (default: a per-install file in the system temp directory)
     * @param int $ttlSeconds requestState lifetime in seconds
     * @param bool|null $verifyOwnership Multi-tenant checks (null = auto: enabled for the default path)
     * @throws \RuntimeException When no shared secret can be established or a hardening check fails
     */
    public static function withFileSecret(
        ?string $path = null,
        int $ttlSeconds = 300,
        ?bool $verifyOwnership = null
    ): self {
        $verifyOwnership ??= ($path === null);
        $path ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp-mrtr-secret-' . md5(__DIR__);

        // Fast path: an earlier process already established the secret.
        $secret = self::readSecretFile($path, $verifyOwnership);
        if ($secret !== null) {
            return new self($secret, $ttlSeconds);
        }

        if ($verifyOwnership && is_link($path)) {
            throw new \RuntimeException(
                "Refusing the MRTR state secret at '$path': the path is a symlink. Remove it, or "
                . 'configure an explicit secret via McpServer::inputStateCodec(new RequestStateCodec($secret)).'
            );
        }

        // Create-or-reclaim under an exclusive lock: 'c' creates the file
        // when missing without ever truncating an existing one; flock()
        // makes exactly one process the writer while every concurrent
        // initializer blocks here and then adopts the written bytes.
        $handle = @fopen($path, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException(
                "Unable to establish the shared MRTR state secret at '$path' (not creatable or lockable). "
                . 'Configure an explicit secret via McpServer::inputStateCodec(new RequestStateCodec($secret)) '
                . 'or pass a writable path to RequestStateCodec::withFileSecret().'
            );
        }

        try {
            $stat = fstat($handle);
            if (!is_array($stat)) {
                throw new \RuntimeException(
                    "Unable to inspect the shared MRTR state secret at '$path'"
                );
            }
            if ($verifyOwnership) {
                self::assertPathMatchesHandle($path, $stat);
                self::assertOwnedByCurrentUser($path, $stat);
            }

            // Lock the permissions down BEFORE any secret byte exists:
            // creation permissions are umask-derived and may allow other
            // users to read the file.
            $permissionsChanged = @chmod($path, 0600);
            $stat = fstat($handle);
            if (!is_array($stat)) {
                throw new \RuntimeException(
                    "Unable to inspect the shared MRTR state secret at '$path'"
                );
            }
            if ($verifyOwnership && PHP_OS_FAMILY !== 'Windows') {
                if (!$permissionsChanged) {
                    throw new \RuntimeException(
                        "Unable to restrict the MRTR state secret at '$path' to owner-only permissions"
                    );
                }
                self::assertNotReadableByOthers($path, $stat);
            }

            if ((int) $stat['size'] >= self::MIN_SECRET_BYTES) {
                // Another locker won while we waited: adopt its bytes.
                rewind($handle);
                $secret = stream_get_contents($handle);
                if (is_string($secret) && strlen($secret) >= self::MIN_SECRET_BYTES) {
                    return new self($secret, $ttlSeconds);
                }
            }

            // Empty new file, or a stub left by a writer that crashed
            // before its write landed: claim it.
            $secret = bin2hex(random_bytes(32));
            ftruncate($handle, 0);
            rewind($handle);
            $written = fwrite($handle, $secret);
            fflush($handle);
            if ($written !== strlen($secret)) {
                // Partial write (disk full, quota): leave the file empty
                // and reclaimable rather than holding a truncated secret.
                ftruncate($handle, 0);
                throw new \RuntimeException(
                    "Failed to write the shared MRTR state secret at '$path'"
                );
            }
            return new self($secret, $ttlSeconds);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Read an established secret, applying the multi-tenant checks on the
     * same open handle as the read (no check-to-use gap).
     *
     * @return string|null The secret, or null when the file is missing or
     *                     does not hold a complete secret yet (the caller
     *                     then creates or reclaims it under an exclusive
     *                     lock)
     * @throws \RuntimeException When a hardening check fails
     */
    private static function readSecretFile(string $path, bool $verifyOwnership): ?string
    {
        if ($verifyOwnership && is_link($path)) {
            throw new \RuntimeException(
                "Refusing the MRTR state secret at '$path': the path is a symlink. Remove it, or "
                . 'configure an explicit secret via McpServer::inputStateCodec(new RequestStateCodec($secret)).'
            );
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return null;
            }
            $stat = fstat($handle);
            if (!is_array($stat)) {
                return null;
            }
            if ($verifyOwnership) {
                self::assertPathMatchesHandle($path, $stat);
                self::assertOwnedByCurrentUser($path, $stat);
                self::assertNotReadableByOthers($path, $stat);
            }
            if ((int) $stat['size'] < self::MIN_SECRET_BYTES) {
                return null;
            }
            rewind($handle);
            $secret = stream_get_contents($handle);
            return is_string($secret) && strlen($secret) >= self::MIN_SECRET_BYTES ? $secret : null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * POSIX-only: refuse a secret file owned by another user — at the
     * predictable default path that means a co-tenant planted it (or a
     * symlink resolved somewhere hostile), and adopting a secret someone
     * else knows would let them forge requestState. Skipped where the
     * posix extension is unavailable; permission and symlink checks still
     * apply independently on non-Windows hosts.
     *
     * @param array<mixed> $stat fstat() result for the open handle
     */
    private static function assertOwnedByCurrentUser(string $path, array $stat): void
    {
        if (!function_exists('posix_geteuid')) {
            return;
        }
        $uid = $stat['uid'] ?? null;
        if (!is_int($uid) || $uid !== posix_geteuid()) {
            throw new \RuntimeException(
                "Refusing the MRTR state secret at '$path': the file is not owned by the current user "
                . '(a co-tenant may have planted it). Remove it, or configure an explicit secret via '
                . 'McpServer::inputStateCodec(new RequestStateCodec($secret)).'
            );
        }
    }

    /**
     * Ensure the checked pathname still names the file represented by the
     * open handle. This catches symlink resolution and path replacement that
     * occurs between the initial is_link() check and fopen().
     *
     * @param array<mixed> $stat fstat() result for the open handle
     */
    private static function assertPathMatchesHandle(string $path, array $stat): void
    {
        $pathStat = @lstat($path);
        if (!is_array($pathStat)) {
            throw new \RuntimeException(
                "Refusing the MRTR state secret at '$path': the path disappeared while it was being opened"
            );
        }
        $mode = $pathStat['mode'];
        if (($mode & 0170000) === 0120000) {
            throw new \RuntimeException(
                "Refusing the MRTR state secret at '$path': the path is a symlink. Remove it, or "
                . 'configure an explicit secret via McpServer::inputStateCodec(new RequestStateCodec($secret)).'
            );
        }
        foreach (['dev', 'ino'] as $field) {
            if ($pathStat[$field] !== $stat[$field]) {
                throw new \RuntimeException(
                    "Refusing the MRTR state secret at '$path': the path changed while it was being opened"
                );
            }
        }
    }

    /**
     * POSIX-only: refuse a secret file readable by group/other — a secret
     * other users can read is no secret. (The writer locks permissions to
     * 0600 before the secret exists, so this only fires on files created
     * outside this codec.)
     *
     * @param array<mixed> $stat fstat() result for the open handle
     */
    private static function assertNotReadableByOthers(string $path, array $stat): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }
        $mode = $stat['mode'] ?? null;
        if (is_int($mode) && ($mode & 0077) !== 0) {
            throw new \RuntimeException(
                "Refusing the MRTR state secret at '$path': its permissions allow other users to access it. "
                . 'Restrict it to 0600, or configure an explicit secret via '
                . 'McpServer::inputStateCodec(new RequestStateCodec($secret)).'
            );
        }
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
