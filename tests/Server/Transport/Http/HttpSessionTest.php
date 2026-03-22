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

use Mcp\Server\Transport\Http\HttpSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for HttpSession lifecycle, metadata, and serialization.
 *
 * Validates that the HTTP session correctly manages its ID generation,
 * state transitions (new -> active -> expired), activity tracking,
 * metadata storage, and array serialization round-trips.
 */
final class HttpSessionTest extends TestCase
{
    /**
     * Verify that the constructor generates a 64-character hexadecimal session ID
     * when no ID is provided. The ID must consist solely of hex digits (0-9, a-f)
     * and be exactly 64 characters long (32 random bytes encoded as hex).
     */
    public function testConstructorGeneratesSessionId(): void
    {
        $session = new HttpSession();

        $id = $session->getId();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $id);
    }

    /**
     * Verify that the constructor accepts and preserves a custom session ID
     * without modification.
     */
    public function testConstructorAcceptsCustomId(): void
    {
        $customId = 'my-custom-session-id-12345';
        $session = new HttpSession($customId);

        $this->assertSame($customId, $session->getId());
    }

    /**
     * Verify that a freshly constructed session starts in the 'new' state
     * and is not considered active.
     */
    public function testInitialStateIsNew(): void
    {
        $session = new HttpSession();

        $this->assertSame('new', $session->getState());
        $this->assertFalse($session->isActive());
    }

    /**
     * Verify that calling activate() transitions the session state from 'new'
     * to 'active' and that isActive() returns true afterwards.
     */
    public function testActivateChangesStateToActive(): void
    {
        $session = new HttpSession();

        $session->activate();

        $this->assertSame('active', $session->getState());
        $this->assertTrue($session->isActive());
    }

    /**
     * Verify that activate() updates the lastActivity timestamp. Since activate()
     * internally calls updateActivity(), the last activity time should be at least
     * as recent as the creation time.
     */
    public function testActivateUpdatesLastActivity(): void
    {
        $session = new HttpSession();
        $initialActivity = $session->getLastActivity();

        // activate() calls updateActivity() internally, so lastActivity should
        // be >= the initial value (same second or later).
        $session->activate();

        $this->assertGreaterThanOrEqual($initialActivity, $session->getLastActivity());
    }

    /**
     * Verify that calling activate() on an expired session throws a RuntimeException.
     * Once a session is expired, it cannot be reactivated.
     */
    public function testActivateExpiredSessionThrowsRuntimeException(): void
    {
        $session = new HttpSession();
        $session->expire();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot activate an expired session');

        $session->activate();
    }

    /**
     * Verify that expire() transitions the session state to 'expired' and
     * that isActive() returns false for an expired session.
     */
    public function testExpireChangesState(): void
    {
        $session = new HttpSession();
        $session->activate();
        $session->expire();

        $this->assertSame('expired', $session->getState());
        $this->assertFalse($session->isActive());
    }

    /**
     * Verify that isExpired() returns true when the session has been explicitly
     * expired via expire(), regardless of the timeout value provided.
     */
    public function testIsExpiredReturnsTrueForExpiredState(): void
    {
        $session = new HttpSession();
        $session->expire();

        // Even with a very large timeout, an explicitly expired session is expired.
        $this->assertTrue($session->isExpired(9999));
    }

    /**
     * Verify that isExpired() returns true when the timeout threshold is exceeded.
     * With a timeout of 0 seconds, the session should be considered expired
     * immediately because (time() - lastActivity) will be >= 0, and the check
     * is strictly greater-than, so any non-zero elapsed time triggers expiration.
     * We use timeout=0 so even within the same second the condition holds since
     * (time() - lastActivity) >= 0 and > 0 is satisfied if at least one second
     * has elapsed. To guarantee this, we use fromArray to backdate lastActivity.
     */
    public function testIsExpiredReturnsTrueWhenTimeoutExceeded(): void
    {
        $session = HttpSession::fromArray([
            'id' => 'timeout-test',
            'created_at' => time() - 10,
            'last_activity' => time() - 10,
            'state' => 'active',
            'metadata' => [],
        ]);

        // With a timeout of 5 seconds and lastActivity 10 seconds ago, this is expired.
        $this->assertTrue($session->isExpired(5));

        // With a timeout of 20 seconds, it is not yet expired.
        $this->assertFalse($session->isExpired(20));
    }

    /**
     * Verify that metadata can be set and retrieved by key, and that requesting
     * a missing key returns the specified default value (or null if no default).
     */
    public function testSetAndGetMetadata(): void
    {
        $session = new HttpSession();

        $session->setMetadata('username', 'alice');
        $session->setMetadata('role', 'admin');

        $this->assertSame('alice', $session->getMetadata('username'));
        $this->assertSame('admin', $session->getMetadata('role'));

        // Missing key returns null by default.
        $this->assertNull($session->getMetadata('nonexistent'));

        // Missing key returns the specified default.
        $this->assertSame('fallback', $session->getMetadata('nonexistent', 'fallback'));
    }

    /**
     * Verify that getAllMetadata() returns all stored metadata as an associative
     * array containing every key that was previously set.
     */
    public function testGetAllMetadata(): void
    {
        $session = new HttpSession();

        $session->setMetadata('key1', 'value1');
        $session->setMetadata('key2', 42);
        $session->setMetadata('key3', ['nested' => true]);

        $all = $session->getAllMetadata();

        $this->assertSame('value1', $all['key1']);
        $this->assertSame(42, $all['key2']);
        $this->assertSame(['nested' => true], $all['key3']);
        $this->assertCount(3, $all);
    }

    /**
     * Verify that toArray() and fromArray() form a lossless round-trip: all fields
     * (id, created_at, last_activity, state, metadata) survive serialization and
     * deserialization without modification.
     */
    public function testToArrayFromArrayRoundTrip(): void
    {
        $original = new HttpSession('roundtrip-id');
        $original->activate();
        $original->setMetadata('env', 'test');
        $original->setMetadata('count', 7);

        $array = $original->toArray();
        $restored = HttpSession::fromArray($array);

        $this->assertSame($original->getId(), $restored->getId());
        $this->assertSame($original->getCreatedAt(), $restored->getCreatedAt());
        $this->assertSame($original->getLastActivity(), $restored->getLastActivity());
        $this->assertSame($original->getState(), $restored->getState());
        $this->assertSame($original->getAllMetadata(), $restored->getAllMetadata());

        // Verify the round-tripped array matches the original.
        $this->assertSame($array, $restored->toArray());
    }

    /**
     * Verify that fromArray() correctly restores a session with an 'active' state
     * from raw array data, including all timestamps and metadata, without requiring
     * an explicit activate() call.
     */
    public function testFromArrayRestoresState(): void
    {
        $data = [
            'id' => 'restored-session-abc',
            'created_at' => 1700000000,
            'last_activity' => 1700001000,
            'state' => 'active',
            'metadata' => ['source' => 'persisted'],
        ];

        $session = HttpSession::fromArray($data);

        $this->assertSame('restored-session-abc', $session->getId());
        $this->assertSame(1700000000, $session->getCreatedAt());
        $this->assertSame(1700001000, $session->getLastActivity());
        $this->assertSame('active', $session->getState());
        $this->assertTrue($session->isActive());
        $this->assertSame('persisted', $session->getMetadata('source'));
    }
}
