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
use Mcp\Server\Transport\Http\FileSessionStore;
use Mcp\Server\Transport\Http\HttpSession;

/**
 * Tests for FileSessionStore.
 *
 * Validates persistence round-trips plus the concurrent-access hardening:
 * save() must publish a record a concurrent reader can never observe torn
 * (flock LOCK_EX, truncate-after-lock — the same IO discipline as
 * TaskManager's record store), and load() must degrade to null, without
 * warnings, on the artifacts concurrency can produce (empty file, partial
 * JSON, a file deleted between the existence check and the read).
 */
final class FileSessionStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp-file-session-store-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /**
     * Verify a session survives a save/load round-trip through the
     * filesystem with its identity and metadata intact. Unlike the
     * in-memory store this is a serialize/deserialize cycle, so the loaded
     * object is a reconstruction, not the same instance.
     */
    public function testSaveAndLoadRoundTrip(): void
    {
        $store = new FileSessionStore($this->dir);
        $session = new HttpSession('round-trip-id');
        $session->setMetadata('client', 'conformance');

        $store->save($session);
        $loaded = $store->load('round-trip-id');

        $this->assertNotNull($loaded);
        $this->assertNotSame($session, $loaded);
        $this->assertSame('round-trip-id', $loaded->getId());
        $this->assertSame('conformance', $loaded->getMetadata('client'));
    }

    /**
     * Verify loading an unknown session ID returns null rather than
     * warning or throwing — the HTTP runner turns null into the spec's
     * 404 for an unknown Mcp-Session-Id.
     */
    public function testLoadNonExistentReturnsNull(): void
    {
        $store = new FileSessionStore($this->dir);

        $this->assertNull($store->load('never-saved'));
    }

    /**
     * Verify save() fully replaces the previous record even when the new
     * payload is SHORTER than the old one. The write path opens the file
     * non-truncating ('c') and truncates only after the exclusive lock is
     * held, so a missing ftruncate() would leave trailing bytes of the old
     * record and corrupt the JSON — this pins the truncate-after-lock
     * behavior.
     */
    public function testSaveOverwriteShrinksRecord(): void
    {
        $store = new FileSessionStore($this->dir);

        $big = new HttpSession('overwrite-id');
        $big->setMetadata('payload', str_repeat('x', 4096));
        $store->save($big);

        $small = new HttpSession('overwrite-id');
        $small->setMetadata('payload', 'tiny');
        $store->save($small);

        $loaded = $store->load('overwrite-id');
        $this->assertNotNull($loaded);
        $this->assertSame('tiny', $loaded->getMetadata('payload'));
    }

    /**
     * Verify load() returns null, without emitting warnings, for an empty
     * session file — the artifact a reader observed mid-write before
     * save() was made atomic, and still the correct degraded answer if a
     * file is ever caught in that state (e.g. written by an older SDK
     * build dying mid-write).
     */
    public function testLoadEmptyFileReturnsNull(): void
    {
        $store = new FileSessionStore($this->dir);
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'session-empty-id.json', '');

        $this->assertNull($store->load('empty-id'));
    }

    /**
     * Verify load() returns null, without warnings, when the record holds
     * truncated/invalid JSON rather than propagating a decode failure.
     */
    public function testLoadCorruptedJsonReturnsNull(): void
    {
        $store = new FileSessionStore($this->dir);
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'session-corrupt-id.json', '{"id":"corrupt-id","meta');

        $this->assertNull($store->load('corrupt-id'));
    }

    /**
     * Verify delete() removes the record (subsequent load returns null)
     * and that deleting an already-absent session is a silent no-op — the
     * outcome of two racing DELETE requests.
     */
    public function testDeleteRemovesSessionAndToleratesAbsence(): void
    {
        $store = new FileSessionStore($this->dir);
        $store->save(new HttpSession('delete-id'));

        $store->delete('delete-id');
        $this->assertNull($store->load('delete-id'));

        $store->delete('delete-id');
        $this->assertNull($store->load('delete-id'));
    }
}
