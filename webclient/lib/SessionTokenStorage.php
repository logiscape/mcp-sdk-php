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
 */

declare(strict_types=1);

use Mcp\Client\Auth\Token\FileTokenStorage;
use Mcp\Client\Auth\Token\TokenSet;
use Mcp\Client\Auth\Token\TokenStorageInterface;

/**
 * Per-session token storage wrapper for the webclient.
 *
 * Wraps FileTokenStorage with session-based directory isolation,
 * so each PHP session gets its own token storage directory.
 */
class SessionTokenStorage implements TokenStorageInterface
{
    private FileTokenStorage $storage;
    private string $basePath;
    private string $sessionId;

    /**
     * @param string $basePath Base directory for all token storage (e.g., webclient/tokens)
     * @param string|null $encryptionSecret Secret for encrypting tokens
     * @param string|null $sessionId Override session ID (defaults to PHP session ID)
     * @throws RuntimeException If storage directory cannot be created
     */
    public function __construct(
        string $basePath,
        ?string $encryptionSecret = null,
        ?string $sessionId = null
    ) {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);

        // Use PHP session ID or provided session ID
        $this->sessionId = $sessionId ?? session_id();
        if (empty($this->sessionId)) {
            throw new RuntimeException('No session ID available. Ensure session is started.');
        }

        // Create per-session directory
        $sessionPath = $this->basePath . DIRECTORY_SEPARATOR . $this->sanitizeSessionId($this->sessionId);

        // Initialize the underlying FileTokenStorage
        $this->storage = new FileTokenStorage($sessionPath, $encryptionSecret);
    }

    /**
     * Sanitize session ID for use as a directory name.
     *
     * @param string $sessionId The session ID
     * @return string Safe directory name
     */
    private function sanitizeSessionId(string $sessionId): string
    {
        // Hash the session ID to create a fixed-length, safe directory name
        return hash('sha256', $sessionId);
    }

    /**
     * {@inheritdoc}
     */
    public function store(string $resourceUrl, TokenSet $tokens): void
    {
        $this->storage->store($resourceUrl, $tokens);
    }

    /**
     * {@inheritdoc}
     */
    public function retrieve(string $resourceUrl): ?TokenSet
    {
        return $this->storage->retrieve($resourceUrl);
    }

    /**
     * {@inheritdoc}
     */
    public function remove(string $resourceUrl): void
    {
        $this->storage->remove($resourceUrl);
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        $this->storage->clear();
    }

    /**
     * Get the per-session storage path.
     *
     * @return string
     */
    public function getStoragePath(): string
    {
        return $this->storage->getStoragePath();
    }

    /**
     * Check if encryption is enabled.
     *
     * @return bool
     */
    public function isEncrypted(): bool
    {
        return $this->storage->isEncrypted();
    }

}
