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

namespace Mcp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Mcp\Server\HttpServerSession;
use Mcp\Server\InitializationOptions;
use Mcp\Server\InitializationState;
use Mcp\Server\Transport\Transport;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\Implementation;
use Mcp\Types\ClientCapabilities;
use Mcp\Types\ClientRootsCapability;
use Mcp\Types\InitializeRequestParams;
use Mcp\Types\JsonRpcMessage;
use Psr\Log\NullLogger;

/**
 * Minimal Transport implementation for testing HttpServerSession.
 *
 * Provides a no-op transport that captures written messages
 * without performing any actual I/O operations.
 */
class HttpServerSessionTestTransport implements Transport
{
    /** @var JsonRpcMessage[] */
    public array $writtenMessages = [];

    public function start(): void {}

    public function stop(): void {}

    public function readMessage(): ?JsonRpcMessage
    {
        return null;
    }

    public function writeMessage(JsonRpcMessage $message): void
    {
        $this->writtenMessages[] = $message;
    }
}

/**
 * Tests for HttpServerSession serialization and restoration.
 *
 * Validates that the HttpServerSession correctly:
 * - Serializes session state to an array via toArray()
 * - Restores session state from an array via fromArray()
 * - Round-trips session state without data loss
 * - Handles edge cases such as null or empty clientParams
 * - Preserves roots capability data through serialization
 *
 * These tests are critical for HTTP-based MCP servers where session state
 * must be persisted between stateless HTTP requests (e.g., stored in
 * $_SESSION or a cache layer).
 */
final class HttpServerSessionTest extends TestCase
{
    /**
     * Creates a standard InitializationOptions instance for testing.
     */
    private function createInitOptions(): InitializationOptions
    {
        return new InitializationOptions(
            serverName: 'test-server',
            serverVersion: '1.0.0',
            capabilities: new ServerCapabilities()
        );
    }

    /**
     * Verify that toArray() produces an array containing all required keys.
     *
     * A freshly constructed HttpServerSession should serialize to an array
     * with exactly the keys 'initializationState', 'clientParams', and
     * 'negotiatedProtocolVersion'. This ensures the serialization format
     * is stable and consumers can rely on these keys being present.
     */
    public function testToArrayContainsRequiredKeys(): void
    {
        $transport = new HttpServerSessionTestTransport();
        $session = new HttpServerSession($transport, $this->createInitOptions(), new NullLogger());

        $data = $session->toArray();

        $this->assertArrayHasKey('initializationState', $data);
        $this->assertArrayHasKey('clientParams', $data);
        $this->assertArrayHasKey('negotiatedProtocolVersion', $data);
        $this->assertCount(3, $data, 'toArray() should return exactly 3 keys');
    }

    /**
     * Verify that fromArray() correctly restores the initializationState field.
     *
     * When session data contains initializationState corresponding to
     * InitializationState::Initialized (value 3), the restored session
     * should reflect that state when re-serialized via toArray().
     */
    public function testFromArrayRestoresInitializationState(): void
    {
        $transport = new HttpServerSessionTestTransport();
        $data = [
            'initializationState' => InitializationState::Initialized->value,
            'clientParams' => null,
            'negotiatedProtocolVersion' => null,
        ];

        $session = HttpServerSession::fromArray(
            $data,
            $transport,
            $this->createInitOptions(),
            new NullLogger()
        );

        $restored = $session->toArray();
        $this->assertSame(
            InitializationState::Initialized->value,
            $restored['initializationState'],
            'Restored session should have Initialized state (value 3)'
        );
    }

    /**
     * Verify that fromArray() correctly restores clientParams including
     * clientInfo name/version, capabilities, and protocolVersion.
     *
     * The restored session's toArray() output should contain matching
     * clientParams data so that server logic can inspect the client's
     * declared capabilities after restoration.
     *
     * Note: fromArray() does NOT restore sampling, elicitation, tasks,
     * or experimental capabilities -- only roots. This is a known SDK
     * limitation; those fields are silently dropped during reconstruction.
     */
    public function testFromArrayRestoresClientParams(): void
    {
        $transport = new HttpServerSessionTestTransport();
        $data = [
            'initializationState' => InitializationState::Initialized->value,
            'clientParams' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [
                    'roots' => null,
                ],
                'clientInfo' => [
                    'name' => 'test-client',
                    'version' => '2.0.0',
                ],
            ],
            'negotiatedProtocolVersion' => '2025-03-26',
        ];

        $session = HttpServerSession::fromArray(
            $data,
            $transport,
            $this->createInitOptions(),
            new NullLogger()
        );

        $restored = $session->toArray();
        $this->assertNotNull($restored['clientParams'], 'clientParams should not be null after restoration');
        // toArray() calls jsonSerialize() which returns nested objects; decode to pure arrays
        $clientParams = json_decode(json_encode($restored['clientParams']), true);
        $this->assertSame('test-client', $clientParams['clientInfo']['name']);
        $this->assertSame('2.0.0', $clientParams['clientInfo']['version']);
        $this->assertSame('2025-03-26', $clientParams['protocolVersion']);
    }

    /**
     * Verify that fromArray() correctly restores the negotiatedProtocolVersion.
     *
     * After restoring a session with a specific protocol version, the
     * round-tripped toArray() output should preserve that exact version
     * string. This is important because the negotiated version controls
     * which protocol features the server may use in responses.
     */
    public function testFromArrayRestoresProtocolVersion(): void
    {
        $transport = new HttpServerSessionTestTransport();
        $data = [
            'initializationState' => InitializationState::Initialized->value,
            'clientParams' => null,
            'negotiatedProtocolVersion' => '2025-03-26',
        ];

        $session = HttpServerSession::fromArray(
            $data,
            $transport,
            $this->createInitOptions(),
            new NullLogger()
        );

        $restored = $session->toArray();
        $this->assertSame(
            '2025-03-26',
            $restored['negotiatedProtocolVersion'],
            'Negotiated protocol version should be preserved through serialization'
        );
    }

    /**
     * Verify full round-trip: populate all fields via fromArray(), serialize
     * back with toArray(), then restore again and compare.
     *
     * This end-to-end test ensures that no data is lost or mutated across
     * multiple serialization/deserialization cycles, which is critical for
     * long-lived HTTP sessions that may be saved and restored many times.
     *
     * Note: fromArray() does NOT restore sampling, elicitation, tasks,
     * or experimental capabilities -- only roots. This is a known SDK
     * limitation.
     */
    public function testToArrayFromArrayRoundTrip(): void
    {
        $transport = new HttpServerSessionTestTransport();
        $originalData = [
            'initializationState' => InitializationState::Initialized->value,
            'clientParams' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [
                    'roots' => [
                        'listChanged' => true,
                    ],
                ],
                'clientInfo' => [
                    'name' => 'round-trip-client',
                    'version' => '3.1.0',
                ],
            ],
            'negotiatedProtocolVersion' => '2025-03-26',
        ];

        // First restoration
        $session1 = HttpServerSession::fromArray(
            $originalData,
            $transport,
            $this->createInitOptions(),
            new NullLogger()
        );
        $firstSerializationRaw = $session1->toArray();

        // @note SDK issue: toArray() calls jsonSerialize() which returns nested
        // objects (Implementation, ClientCapabilities, etc.) instead of plain arrays.
        // fromArray() expects plain arrays. Normalize via JSON encode/decode to
        // simulate real-world serialization (e.g., storing in a file/database).
        $firstSerialization = json_decode(json_encode($firstSerializationRaw), true);

        // Second restoration from the normalized serialization
        $session2 = HttpServerSession::fromArray(
            $firstSerialization,
            $transport,
            $this->createInitOptions(),
            new NullLogger()
        );
        $secondSerializationRaw = $session2->toArray();
        $secondSerialization = json_decode(json_encode($secondSerializationRaw), true);

        $this->assertSame(
            $firstSerialization['initializationState'],
            $secondSerialization['initializationState'],
            'initializationState should be identical after round-trip'
        );
        $this->assertSame(
            $firstSerialization['negotiatedProtocolVersion'],
            $secondSerialization['negotiatedProtocolVersion'],
            'negotiatedProtocolVersion should be identical after round-trip'
        );
        $this->assertEquals(
            $firstSerialization['clientParams'],
            $secondSerialization['clientParams'],
            'clientParams should be equivalent after round-trip'
        );
    }

    /**
     * Verify that fromArray() handles null clientParams gracefully.
     *
     * When a session was serialized before any client connected (or with
     * a client that sent no params), clientParams will be null. The
     * restoration must not throw or produce unexpected state.
     */
    public function testFromArrayWithNullClientParams(): void
    {
        $transport = new HttpServerSessionTestTransport();
        $data = [
            'initializationState' => InitializationState::NotInitialized->value,
            'clientParams' => null,
            'negotiatedProtocolVersion' => null,
        ];

        $session = HttpServerSession::fromArray(
            $data,
            $transport,
            $this->createInitOptions(),
            new NullLogger()
        );

        $restored = $session->toArray();
        $this->assertNull($restored['clientParams'], 'clientParams should remain null when restored from null');
    }

    /**
     * Verify that fromArray() handles an empty array for clientParams gracefully.
     *
     * An empty array is falsy under PHP's empty() check, so the fromArray()
     * method should skip clientParams reconstruction without error.
     * This guards against edge cases where serialized data may have been
     * manipulated or produced by a different serializer.
     */
    public function testFromArrayWithEmptyClientParams(): void
    {
        $transport = new HttpServerSessionTestTransport();
        $data = [
            'initializationState' => InitializationState::NotInitialized->value,
            'clientParams' => [],
            'negotiatedProtocolVersion' => null,
        ];

        $session = HttpServerSession::fromArray(
            $data,
            $transport,
            $this->createInitOptions(),
            new NullLogger()
        );

        $restored = $session->toArray();
        // empty() treats [] as empty, so clientParams reconstruction is skipped
        $this->assertNull(
            $restored['clientParams'],
            'Empty array clientParams should be treated as absent (null after restoration)'
        );
    }

    /**
     * Verify that fromArray() correctly reconstructs ClientRootsCapability
     * when clientParams includes capabilities.roots.listChanged=true.
     *
     * The roots capability is the only client capability that is fully
     * reconstructed by fromArray(). This test confirms that the listChanged
     * flag survives serialization and is accessible in the restored session.
     *
     * Note: fromArray() does NOT restore sampling, elicitation, tasks,
     * or experimental capabilities -- only roots. This is a known SDK
     * limitation.
     */
    public function testFromArrayWithRootsCapability(): void
    {
        $transport = new HttpServerSessionTestTransport();
        $data = [
            'initializationState' => InitializationState::Initialized->value,
            'clientParams' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [
                    'roots' => [
                        'listChanged' => true,
                    ],
                ],
                'clientInfo' => [
                    'name' => 'roots-test-client',
                    'version' => '1.0.0',
                ],
            ],
            'negotiatedProtocolVersion' => '2025-03-26',
        ];

        $session = HttpServerSession::fromArray(
            $data,
            $transport,
            $this->createInitOptions(),
            new NullLogger()
        );

        $restoredRaw = $session->toArray();
        $this->assertNotNull($restoredRaw['clientParams'], 'clientParams should be present');

        // Normalize through JSON to get plain arrays (toArray uses jsonSerialize which returns objects)
        $restored = json_decode(json_encode($restoredRaw['clientParams']), true);
        $this->assertArrayHasKey('capabilities', $restored);

        $rootsData = $restored['capabilities']['roots'] ?? null;
        $this->assertNotNull($rootsData, 'roots capability should be present after restoration');
        $this->assertTrue(
            $rootsData['listChanged'] ?? false,
            'roots.listChanged should be true after round-trip'
        );
    }
}
