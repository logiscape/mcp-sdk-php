<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2025 Logiscape LLC <https://logiscape.com>
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

namespace Mcp\Tests\Server\Transport\Http;

use PHPUnit\Framework\TestCase;
use Mcp\Server\Transport\Http\InMemorySessionStore;
use Mcp\Server\Transport\Http\HttpSession;

/**
 * Tests for InMemorySessionStore.
 *
 * Validates that the in-memory session store correctly handles
 * saving, loading, deleting, and overwriting session objects.
 */
final class InMemorySessionStoreTest extends TestCase
{
    /**
     * Verify that a session can be saved and then loaded by its ID.
     * The loaded session must be the exact same object instance that
     * was saved, confirming the store holds a reference rather than a copy.
     */
    public function testSaveAndLoad(): void
    {
        $store = new InMemorySessionStore();
        $session = new HttpSession('test-session-id');

        $store->save($session);

        $loaded = $store->load('test-session-id');
        $this->assertSame($session, $loaded);
    }

    /**
     * Verify that loading a session ID that has never been saved
     * returns null rather than throwing an exception or returning
     * a default session object.
     */
    public function testLoadNonExistentReturnsNull(): void
    {
        $store = new InMemorySessionStore();

        $result = $store->load('non-existent-id');
        $this->assertNull($result);
    }

    /**
     * Verify that deleting a previously saved session removes it
     * from the store, so that a subsequent load for the same ID
     * returns null.
     */
    public function testDelete(): void
    {
        $store = new InMemorySessionStore();
        $session = new HttpSession('delete-me');

        $store->save($session);
        $store->delete('delete-me');

        $loaded = $store->load('delete-me');
        $this->assertNull($loaded);
    }

    /**
     * Verify that deleting a session ID that does not exist in the
     * store does not throw an exception. This confirms that the
     * delete operation is idempotent and safe to call unconditionally.
     */
    public function testDeleteNonExistentDoesNotThrow(): void
    {
        $store = new InMemorySessionStore();

        $store->delete('never-saved-id');

        // If we reach this point, no exception was thrown.
        $this->assertTrue(true);
    }

    /**
     * Verify that saving a session a second time overwrites the
     * previous entry. After setting metadata on the session and
     * saving it again, loading the session should reflect the
     * updated metadata, confirming the store uses the latest save.
     */
    public function testOverwrite(): void
    {
        $store = new InMemorySessionStore();
        $session = new HttpSession('overwrite-id');

        $store->save($session);

        $session->setMetadata('key', 'updated-value');
        $store->save($session);

        $loaded = $store->load('overwrite-id');
        $this->assertSame($session, $loaded);
        $this->assertSame('updated-value', $loaded->getMetadata('key'));
    }
}
