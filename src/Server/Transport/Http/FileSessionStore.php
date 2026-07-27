<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2025 Logiscape LLC <https://logiscape.com>
 *
 * Developed by:
 * - Josh Abbott
 * - Claude 3.7 Sonnet (Anthropic AI model)
 * - ChatGPT o1 pro mode
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
 * Filename: Server/Transport/Http/FileSessionStore.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport\Http;

/**
 * Stores session data in a file to persist across requests on a typical web server.
 */
class FileSessionStore implements SessionStoreInterface
{
    public function __construct(private string $directory)
    {
        // Ensure directory exists
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0750, true);
        }
    }

    public function load(string $sessionId): ?HttpSession
    {
        $path = $this->getPath($sessionId);

        // Open directly instead of is_file()-then-read: a concurrent
        // delete() between the check and the read would warn.
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return null;
        }

        $raw = false;
        if (flock($handle, LOCK_SH)) {
            $raw = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!$data) {
            return null;
        }

        // Convert the array back to an HttpSession
        return HttpSession::fromArray($data);
    }

    public function save(HttpSession $session): void
    {
        $path = $this->getPath($session->getId());
        $json = json_encode($session->toArray());

        // Open non-truncating ('c') and truncate only after LOCK_EX is
        // held. A bare file_put_contents() truncates in place before any
        // lock, so a concurrent request on the same session — multi-worker
        // dev servers, production PHP-FPM — could read a torn or empty
        // file, fail to decode it, and answer 404 for a live session.
        // Same IO discipline as TaskManager's record store; the lock is
        // advisory and assumes a local filesystem, like the store itself.
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return;
        }

        if (flock($handle, LOCK_EX)) {
            ftruncate($handle, 0);
            fwrite($handle, $json);
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }

    public function delete(string $sessionId): void
    {
        $path = $this->getPath($sessionId);
        if (is_file($path)) {
            // Suppressed: a concurrent delete() may have won the race, and
            // on Windows an unlink can fail while another process still
            // holds the file open — the TTL sweep collects it later.
            @unlink($path);
        }
    }

    private function getPath(string $sessionId): string
    {
        return rtrim($this->directory, '/\\') . '/session-' . $sessionId . '.json';
    }
}
