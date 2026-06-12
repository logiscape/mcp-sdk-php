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
 * Filename: Server/Subscriptions/FileSubscriptionBus.php
 */

declare(strict_types=1);

namespace Mcp\Server\Subscriptions;

/**
 * File-backed subscription bus: an append-only JSONL event log readable
 * across processes — the shared-hosting-compatible default for
 * `subscriptions/listen` (mirrors the file-based TaskManager pattern;
 * works under PHP-FPM pools, the multi-worker CLI server, and cPanel
 * accounts without any extension or daemon).
 *
 * The cursor is the byte offset into the log file. The log is bounded by
 * truncating once it exceeds a size cap while no reader is expected
 * (best-effort; listen streams re-anchor on the next poll if the file
 * shrank beneath their cursor).
 */
final class FileSubscriptionBus implements SubscriptionBusInterface
{
    private string $file;

    /** Truncate the log when it grows beyond this many bytes. */
    private int $maxBytes;

    public function __construct(string $directory, int $maxBytes = 1048576)
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $this->file = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'subscription-events.jsonl';
        $this->maxBytes = $maxBytes;
    }

    public function publish(string $method, array $params = []): void
    {
        $line = json_encode(['method' => $method, 'params' => $params], JSON_UNESCAPED_SLASHES) . "\n";

        $size = @filesize($this->file);
        if (is_int($size) && $size > $this->maxBytes) {
            // Best-effort bound: start a fresh log. Open listen loops
            // re-anchor to offset 0 when their cursor exceeds the new size.
            @file_put_contents($this->file, $line, LOCK_EX);
            return;
        }
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }

    public function cursor(): int
    {
        clearstatcache(true, $this->file);
        $size = @filesize($this->file);
        return is_int($size) ? $size : 0;
    }

    public function pollSince(int $cursor): array
    {
        clearstatcache(true, $this->file);
        $size = @filesize($this->file);
        if (!is_int($size)) {
            return ['cursor' => 0, 'events' => []];
        }
        if ($cursor > $size) {
            // The log was truncated since this cursor was taken; re-anchor.
            $cursor = 0;
        }
        if ($cursor === $size) {
            return ['cursor' => $cursor, 'events' => []];
        }

        $handle = @fopen($this->file, 'rb');
        if ($handle === false) {
            return ['cursor' => $cursor, 'events' => []];
        }
        try {
            if (flock($handle, LOCK_SH)) {
                fseek($handle, $cursor);
                $chunk = stream_get_contents($handle);
                flock($handle, LOCK_UN);
            } else {
                $chunk = '';
            }
        } finally {
            fclose($handle);
        }
        if (!is_string($chunk) || $chunk === '') {
            return ['cursor' => $cursor, 'events' => []];
        }

        $events = [];
        $consumed = 0;
        foreach (explode("\n", $chunk) as $line) {
            if ($line === '') {
                continue;
            }
            if (!str_ends_with($chunk, "\n") && $consumed + strlen($line) + 1 > strlen($chunk)) {
                // Trailing partial line from a concurrent writer: leave it
                // for the next poll.
                break;
            }
            $consumed += strlen($line) + 1;
            $decoded = json_decode($line, true);
            if (is_array($decoded) && is_string($decoded['method'] ?? null)) {
                $events[] = [
                    'method' => $decoded['method'],
                    'params' => is_array($decoded['params'] ?? null) ? $decoded['params'] : [],
                ];
            }
        }

        return ['cursor' => $cursor + $consumed, 'events' => $events];
    }
}
