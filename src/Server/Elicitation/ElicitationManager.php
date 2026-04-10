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
 * Filename: Server/Elicitation/ElicitationManager.php
 */

declare(strict_types=1);

namespace Mcp\Server\Elicitation;

/**
 * File-based storage manager for pending elicitation state.
 *
 * Designed for cPanel/Apache compatibility — uses simple JSON files
 * in a configurable directory (defaults to system temp). No long-running
 * processes, databases, or special extensions required.
 */
class ElicitationManager
{
    private string $storagePath;

    public function __construct(string $storagePath = '')
    {
        $this->storagePath = $storagePath ?: sys_get_temp_dir() . '/mcp_elicitations';
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0755, true);
            if (!is_dir($this->storagePath)) {
                throw new \RuntimeException("Failed to create elicitation storage directory: {$this->storagePath}");
            }
        }
    }

    /**
     * Save a pending elicitation to storage.
     */
    public function savePending(PendingElicitation $pending): void
    {
        $filename = $this->pendingFilename($pending->serverRequestId);
        $data = $pending->toArray();
        file_put_contents($filename, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * Retrieve a pending elicitation by server request ID.
     */
    public function getPendingByRequestId(int $serverRequestId): ?PendingElicitation
    {
        $filename = $this->pendingFilename($serverRequestId);
        if (!file_exists($filename)) {
            return null;
        }

        $contents = file_get_contents($filename);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return null;
        }

        return PendingElicitation::fromArray($data);
    }

    /**
     * Delete a pending elicitation.
     */
    public function deletePending(int $serverRequestId): void
    {
        $filename = $this->pendingFilename($serverRequestId);
        if (file_exists($filename)) {
            @unlink($filename);
        }
    }

    /**
     * Clean up expired pending elicitations.
     *
     * @param int $maxAge Maximum age in seconds (default: 1 hour)
     */
    public function cleanup(int $maxAge = 3600): void
    {
        $files = glob($this->storagePath . '/pending_*.json');
        if ($files === false) {
            return;
        }

        $now = microtime(true);
        foreach ($files as $file) {
            $contents = @file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            $data = json_decode($contents, true);
            if (!is_array($data)) {
                @unlink($file);
                continue;
            }

            $createdAt = (float) ($data['createdAt'] ?? 0.0);
            if ($createdAt > 0 && ($now - $createdAt) > $maxAge) {
                @unlink($file);
            }
        }
    }

    private function pendingFilename(int $serverRequestId): string
    {
        return $this->storagePath . '/pending_' . $serverRequestId . '.json';
    }
}
