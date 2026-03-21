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

namespace Mcp\Tests\Client;

use PHPUnit\Framework\TestCase;
use Mcp\Client\ClientSession;
use Mcp\Shared\MemoryStream;
use Mcp\Shared\Version;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\InitializeResult;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\Implementation;
use Mcp\Types\RequestId;
use Mcp\Types\JsonRpcErrorObject;
use Mcp\Types\JSONRPCError;
use Mcp\Shared\McpError;

/**
 * Tests for ClientSession initialization handshake.
 *
 * Validates that the client correctly:
 * - Sends an 'initialize' request
 * - Receives and validates the InitializeResult
 * - Sends the 'notifications/initialized' notification
 * - Properly sets negotiated protocol version
 * - Correctly reports feature support based on protocol version
 */
class ClientSessionInitializeTest extends TestCase
{
    /**
     * Test that initialize() sends the correct message sequence.
     *
     * Wire protocol verification:
     * 1. Client sends 'initialize' JSON-RPC request
     * 2. Server responds with InitializeResult
     * 3. Client sends 'notifications/initialized' notification
     *
     * This test preloads a valid InitializeResult into the read stream,
     * calls initialize(), and verifies the exact messages written to
     * the write stream match the expected protocol sequence.
     */
    public function testInitializeHandshakeSendsCorrectSequence(): void
    {
        // Arrange: Create two MemoryStream queues for bidirectional communication
        $readStream = new MemoryStream();   // Client reads server responses from this
        $writeStream = new MemoryStream();  // Client writes requests/notifications to this

        // Preload the read stream with a mock server response
        // The server responds with InitializeResult for protocol version 2025-03-26
        // Note: The result must be in array format (as it would be from JSON decoding)
        // not as a pre-constructed object, because BaseSession::sendRequest will call
        // InitializeResult::fromResponseData() on it
        $initializeResultData = [
            'protocolVersion' => Version::LATEST_PROTOCOL_VERSION,
            'capabilities' => [],
            'serverInfo' => [
                'name' => 'test-server',
                'version' => '1.0.0'
            ]
        ];

        // Create a JSON-RPC response with request ID 1 (clients typically start at 1)
        $readStream->send($this->createResponse($initializeResultData));

        // Create the client session with a small read timeout to prevent hanging in tests
        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        // Act: Initialize the session (this should trigger the handshake)
        $session->initialize();

        // Assert: Verify the first message written is the 'initialize' request
        $firstMessage = $writeStream->receive();
        $this->assertInstanceOf(JsonRpcMessage::class, $firstMessage, 'First message should be a JsonRpcMessage');
        $this->assertInstanceOf(JSONRPCRequest::class, $firstMessage->message, 'First message should be a JSON-RPC request');

        // Decode the request to inspect its contents
        $firstMessageData = json_decode(json_encode($firstMessage), true);
        $this->assertEquals('2.0', $firstMessageData['jsonrpc'], 'JSON-RPC version must be 2.0');
        $this->assertEquals('initialize', $firstMessageData['method'], 'First request method must be "initialize"');
        $this->assertArrayHasKey('id', $firstMessageData, 'Initialize request must have an ID');
        $this->assertArrayHasKey('params', $firstMessageData, 'Initialize request must have params');

        // Verify params contain required fields
        $params = $firstMessageData['params'];
        $this->assertArrayHasKey('protocolVersion', $params, 'Params must include protocolVersion');
        $this->assertEquals(Version::LATEST_PROTOCOL_VERSION, $params['protocolVersion'], 'Should request latest protocol version');
        $this->assertArrayHasKey('capabilities', $params, 'Params must include capabilities');
        $this->assertArrayHasKey('clientInfo', $params, 'Params must include clientInfo');

        // Assert: Verify the second message written is the 'notifications/initialized' notification
        $secondMessage = $writeStream->receive();
        $this->assertInstanceOf(JsonRpcMessage::class, $secondMessage, 'Second message should be a JsonRpcMessage');
        $this->assertInstanceOf(JSONRPCNotification::class, $secondMessage->message, 'Second message should be a JSON-RPC notification');

        $secondMessageData = json_decode(json_encode($secondMessage), true);
        $this->assertEquals('2.0', $secondMessageData['jsonrpc'], 'JSON-RPC version must be 2.0');
        $this->assertEquals('notifications/initialized', $secondMessageData['method'], 'Second notification method must be "notifications/initialized"');
        $this->assertArrayNotHasKey('id', $secondMessageData, 'Notifications must not have an ID');

        // Assert: Verify the write stream is now empty (no extra messages)
        $this->assertNull($writeStream->receive(), 'No additional messages should be sent after initialization');
    }

    /**
     * Test that getInitializeResult() succeeds after initialization.
     *
     * Verifies that the InitializeResult returned by the server is
     * correctly stored and accessible via getInitializeResult().
     */
    public function testGetInitializeResultSucceedsAfterInitialization(): void
    {
        // Arrange
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();

        $resultData = [
            'protocolVersion' => Version::LATEST_PROTOCOL_VERSION,
            'capabilities' => [],
            'serverInfo' => [
                'name' => 'test-server',
                'version' => '1.0.0'
            ]
        ];

        $readStream->send($this->createResponse($resultData));
        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        // Act
        $session->initialize();
        $result = $session->getInitializeResult();

        // Assert
        $this->assertInstanceOf(InitializeResult::class, $result, 'Should return InitializeResult');
        $this->assertEquals(Version::LATEST_PROTOCOL_VERSION, $result->protocolVersion, 'Protocol version should match');
        $this->assertEquals('test-server', $result->serverInfo->name, 'Server name should match');
        $this->assertEquals('1.0.0', $result->serverInfo->version, 'Server version should match');
    }

    /**
     * Test that getNegotiatedProtocolVersion() succeeds after initialization.
     *
     * Verifies that the protocol version is correctly negotiated and
     * accessible after successful initialization.
     */
    public function testGetNegotiatedProtocolVersionSucceedsAfterInitialization(): void
    {
        // Arrange
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();

        $resultData = [
            'protocolVersion' => Version::LATEST_PROTOCOL_VERSION,
            'capabilities' => [],
            'serverInfo' => [
                'name' => 'test-server',
                'version' => '1.0.0'
            ]
        ];

        $readStream->send($this->createResponse($resultData));
        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        // Act
        $session->initialize();
        $negotiatedVersion = $session->getNegotiatedProtocolVersion();

        // Assert
        $this->assertEquals(Version::LATEST_PROTOCOL_VERSION, $negotiatedVersion, 'Negotiated version should match server response');
    }

    /**
     * Test that client accepts older supported protocol version from server (downgrade negotiation).
     *
     * Protocol version downgrade scenario:
     * 1. Client sends initialize request with LATEST_PROTOCOL_VERSION (e.g., 2025-03-26)
     * 2. Server responds with older supported version (e.g., 2024-11-05)
     * 3. Client validates version is in SUPPORTED_PROTOCOL_VERSIONS list
     * 4. Client accepts the downgrade and stores older version
     * 5. Client feature detection adapts to older version capabilities
     *
     * This is critical for backward compatibility - clients must be able to
     * communicate with servers running older SDK versions. Without this,
     * upgrading clients would break connections to older servers.
     *
     * Example: A client on SDK v2.0 (protocol 2025-03-26) connects to a
     * server on SDK v1.0 (protocol 2024-11-05). The client must gracefully
     * downgrade and disable features unsupported in 2024-11-05 (like audio_content).
     *
     * Corresponds to ClientSession.php:151-157 (version validation and storage)
     */
    public function testInitializeNegotiatesOlderSupportedProtocolVersion(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();

        $olderVersion = Version::SUPPORTED_PROTOCOL_VERSIONS[0];
        $resultData = [
            'protocolVersion' => $olderVersion,
            'capabilities' => [],
            'serverInfo' => [
                'name' => 'test-server',
                'version' => '1.0.0'
            ]
        ];

        $readStream->send($this->createResponse($resultData));
        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        $session->initialize();

        $this->assertSame($olderVersion, $session->getNegotiatedProtocolVersion(), 'Client should accept older supported protocol version');
    }

    /**
     * Test that client initialization fails gracefully when server returns JSONRPCError.
     *
     * Error response during initialization flow:
     * 1. Client sends initialize request
     * 2. Server encounters error (e.g., internal failure, invalid params)
     * 3. Server responds with JSONRPCError instead of InitializeResult
     * 4. Client's sendRequest() triggers response handler (BaseSession.php:126-148)
     * 5. Handler detects JSONRPCError type and throws McpError
     * 6. McpError propagates out of initialize() to caller
     * 7. Client remains uninitialized (safe failure state)
     *
     * This validates that initialization errors are properly surfaced to the
     * application rather than being silently swallowed or causing hangs.
     *
     * Example: Server receives initialize but is in maintenance mode. It returns
     * JSONRPCError(-32603, "Server unavailable"). Client throws McpError with
     * embedded error details, allowing the application to handle the failure.
     *
     * Corresponds to ClientSession.php:148 (sendRequest) and
     * BaseSession.php:129-137 (error propagation)
     */
    public function testInitializeFailsOnJsonRpcErrorResponse(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();

        $readStream->send(
            new JsonRpcMessage(
                new JSONRPCError(
                    jsonrpc: '2.0',
                    id: new RequestId(0),
                    error: new JsonRpcErrorObject(
                        code: -32603,
                        message: 'server failed',
                        data: ['detail' => 'boom']
                    )
                )
            )
        );

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        $this->expectException(McpError::class);
        $session->initialize();
    }

    /**
     * Test that client initialization times out when server never sends response.
     *
     * Timeout scenario:
     * 1. Client sends initialize request
     * 2. Client enters waitForResponse loop (ClientSession.php:560-579)
     * 3. Read timeout is set to 0.05 seconds
     * 4. Loop repeatedly calls readNextMessage() but stream returns null
     * 5. After 0.05 seconds, timeout is detected (line 567)
     * 6. RuntimeException is thrown with timeout message
     * 7. Initialization fails and client remains uninitialized
     *
     * This prevents clients from hanging indefinitely when:
     * - Server crashes after receiving initialize
     * - Network connection is lost
     * - Server is frozen/unresponsive
     * - Server forgets to send initialize response
     *
     * Without timeout handling, clients would hang forever waiting for a
     * response that will never arrive, requiring process termination.
     *
     * Corresponds to ClientSession.php:567-569 (timeout detection in waitForResponse)
     */
    public function testInitializeTimesOutWhenServerNeverResponds(): void
    {
        $readStream = new TimeoutMemoryStream(0.05);
        $writeStream = new MemoryStream();

        $session = new ClientSession($readStream, $writeStream, readTimeout: 0.05);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Timed out/i');
        $session->initialize();
    }

    /**
     * Test that initialization fails when server returns unsupported protocol version.
     *
     * The client should reject protocol versions that are not in the
     * SUPPORTED_PROTOCOL_VERSIONS list.
     */
    public function testInitializeRejectsUnsupportedProtocolVersion(): void
    {
        // Arrange
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();

        // Server responds with an unsupported protocol version
        $resultData = [
            'protocolVersion' => '2099-99-99', // Unsupported future version
            'capabilities' => [],
            'serverInfo' => [
                'name' => 'test-server',
                'version' => '1.0.0'
            ]
        ];

        $readStream->send($this->createResponse($resultData));
        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported protocol version from server: 2099-99-99');
        $session->initialize();
    }

    /**
     * Test that supportsFeature() returns false before initialization.
     *
     * Before the session is initialized, no protocol version is negotiated,
     * so all features should be reported as unsupported.
     */
    public function testSupportsFeatureReturnsFalseBeforeInitialization(): void
    {
        // Arrange
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $session = new ClientSession($readStream, $writeStream);

        // Act & Assert
        $this->assertFalse($session->supportsFeature('audio_content'), 'Features should not be supported before initialization');
        $this->assertFalse($session->supportsFeature('annotations'), 'Features should not be supported before initialization');
    }

    private function createResponse(array $resultData): JsonRpcMessage
    {
        return new JsonRpcMessage(
            new JSONRPCResponse(
                jsonrpc: '2.0',
                id: new RequestId(0),
                result: $resultData
            )
        );
    }
}

/**
 * Memory stream that simulates timeout scenarios for testing client error handling.
 *
 * This test double extends MemoryStream to add timeout behavior. After a specified
 * deadline, receive() throws RuntimeException to simulate a read timeout.
 *
 * This is necessary because real MemoryStream would return null indefinitely,
 * causing tests to hang forever. TimeoutMemoryStream allows tests to verify
 * timeout detection logic without actually waiting for real timeouts.
 *
 * Usage:
 * ```php
 * $stream = new TimeoutMemoryStream(0.05); // 50ms timeout
 * $session = new ClientSession($stream, $writeStream, readTimeout: 0.05);
 * // After 50ms, receive() throws RuntimeException
 * $session->initialize(); // Should timeout and throw
 * ```
 *
 * Corresponds to timeout testing for ClientSession.php:567-569
 */
final class TimeoutMemoryStream extends MemoryStream
{
    /** @var array<JsonRpcMessage|\Exception> */
    private array $items = [];
    private readonly float $deadline;

    public function __construct(float $timeoutSeconds)
    {
        $this->deadline = microtime(true) + $timeoutSeconds;
    }

    public function send(mixed $item): void
    {
        $this->items[] = $item;
    }

    public function receive(): mixed
    {
        if (!empty($this->items)) {
            return array_shift($this->items);
        }

        if (microtime(true) >= $this->deadline) {
            throw new \RuntimeException('Timed out waiting for response');
        }

        return null;
    }
}
