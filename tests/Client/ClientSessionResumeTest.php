<?php

declare(strict_types=1);

namespace Mcp\Tests\Client;

use PHPUnit\Framework\TestCase;
use Mcp\Client\ClientSession;
use Mcp\Shared\MemoryStream;
use Mcp\Shared\Version;
use Mcp\Types\InitializeResult;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\Implementation;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\MetaKeys;
use Mcp\Types\RequestId;

/**
 * Tests for ClientSession::createRestored() — resumed session factory.
 *
 * Validates that restored sessions:
 * - Skip the initialization handshake (no messages sent)
 * - Are immediately ready for operations
 * - Correctly expose state from the original session
 * - Start request IDs at the provided value
 */
final class ClientSessionResumeTest extends TestCase
{
    /**
     * Test that createRestored() skips initialization handshake.
     *
     * A restored session must NOT send 'initialize' or 'notifications/initialized'
     * to the write stream. The server already has an active session; sending
     * initialize again would be a protocol violation.
     */
    public function testCreateRestoredSkipsInitializationHandshake(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();

        $initResult = $this->createInitResult();

        $session = ClientSession::createRestored(
            readStream: $readStream,
            writeStream: $writeStream,
            initResult: $initResult,
            negotiatedProtocolVersion: Version::LATEST_PROTOCOL_VERSION,
            nextRequestId: 5,
            readTimeout: 2.0
        );

        // Verify no messages were sent to the write stream
        $this->assertNull(
            $writeStream->receive(),
            'Restored session must not send any messages during creation'
        );
    }

    /**
     * Test that restored session returns correct InitializeResult.
     */
    public function testGetInitializeResultReturnsRestoredValue(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $initResult = $this->createInitResult();

        $session = ClientSession::createRestored(
            readStream: $readStream,
            writeStream: $writeStream,
            initResult: $initResult,
            negotiatedProtocolVersion: Version::LATEST_PROTOCOL_VERSION,
            nextRequestId: 1
        );

        $result = $session->getInitializeResult();
        $this->assertSame($initResult, $result);
        $this->assertSame('test-server', $result->serverInfo->name);
    }

    /**
     * Test that restored session returns correct negotiated protocol version.
     */
    public function testGetNegotiatedProtocolVersionReturnsRestoredValue(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();

        $session = ClientSession::createRestored(
            readStream: $readStream,
            writeStream: $writeStream,
            initResult: $this->createInitResult(),
            negotiatedProtocolVersion: '2024-11-05',
            nextRequestId: 1
        );

        $this->assertSame('2024-11-05', $session->getNegotiatedProtocolVersion());
    }

    /**
     * Test that request ID counter starts at the provided value.
     *
     * When resuming a session, the request ID must continue from where the
     * previous session left off to avoid collisions with pending responses.
     */
    public function testRequestIdStartsAtProvidedValue(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();

        $session = ClientSession::createRestored(
            readStream: $readStream,
            writeStream: $writeStream,
            initResult: $this->createInitResult(),
            negotiatedProtocolVersion: Version::LATEST_PROTOCOL_VERSION,
            nextRequestId: 42
        );

        $this->assertSame(42, $session->getNextRequestId());
    }

    /**
     * Test that restored session allows operations (e.g., sendPing).
     *
     * After restoration, the session is in initialized state and should
     * accept operation calls without throwing "not initialized" errors.
     */
    public function testRestoredSessionAllowsOperations(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();

        // Preload a response for the ping request
        // The restored session will start at request ID 5, so the response
        // must match that ID
        $readStream->send(new JsonRpcMessage(
            new JSONRPCResponse(
                jsonrpc: '2.0',
                id: new RequestId(5),
                result: []
            )
        ));

        $session = ClientSession::createRestored(
            readStream: $readStream,
            writeStream: $writeStream,
            initResult: $this->createInitResult(),
            negotiatedProtocolVersion: Version::LATEST_PROTOCOL_VERSION,
            nextRequestId: 5,
            readTimeout: 2.0
        );

        // This should not throw — session is initialized
        $result = $session->sendPing();
        $this->assertNotNull($result);

        // Verify the ping request was sent to the write stream
        $sentMessage = $writeStream->receive();
        $this->assertInstanceOf(JsonRpcMessage::class, $sentMessage);

        $data = json_decode(json_encode($sentMessage), true);
        $this->assertSame('ping', $data['method']);
        $this->assertSame(5, $data['id']);
    }

    /**
     * Test that a restored session whose negotiated version is modern
     * re-enters modern mode automatically (no explicit wire version needed).
     *
     * A modern revision can only ever be negotiated via the server/discover
     * probe, so a persisted negotiated version of 2026-07-28 is proof the
     * original session was modern-era. Without this, a resumed session sent
     * bare legacy-shaped requests that modern servers reject (the webclient
     * hit HTTP 400 "Session ID required" on tools/list after resume).
     */
    public function testRestoredModernSessionAutoDetectsModernMode(): void
    {
        $session = ClientSession::createRestored(
            readStream: new MemoryStream(),
            writeStream: new MemoryStream(),
            initResult: $this->createInitResult(),
            negotiatedProtocolVersion: Version::LATEST_PROTOCOL_VERSION,
            nextRequestId: 1
        );

        $this->assertTrue($session->isModernMode());
        $this->assertSame(Version::LATEST_PROTOCOL_VERSION, $session->getModernWireVersion());
        $this->assertSame(Version::LATEST_PROTOCOL_VERSION, $session->getNegotiatedProtocolVersion());
    }

    /**
     * Test that a restored modern session stamps the SEP-2575 `_meta`
     * envelope onto outgoing requests again.
     *
     * The 2026-07-28 revision requires every request to carry the protocol
     * version, client info, and client capabilities in `_meta`; servers MUST
     * NOT infer them from prior requests. The envelope is also what the HTTP
     * transport mirrors into the MCP-Protocol-Version header, so a missing
     * envelope makes modern servers classify the request as legacy.
     */
    public function testRestoredModernSessionStampsEnvelopeOnRequests(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();

        // Preload the tools/list response for the request the session sends.
        $readStream->send(new JsonRpcMessage(
            new JSONRPCResponse(
                jsonrpc: '2.0',
                id: new RequestId(7),
                result: ['tools' => []]
            )
        ));

        $session = ClientSession::createRestored(
            readStream: $readStream,
            writeStream: $writeStream,
            initResult: $this->createInitResult(),
            negotiatedProtocolVersion: Version::LATEST_PROTOCOL_VERSION,
            nextRequestId: 7,
            readTimeout: 2.0
        );

        $session->listTools();

        $sentMessage = $writeStream->receive();
        $this->assertInstanceOf(JsonRpcMessage::class, $sentMessage);

        $data = json_decode(json_encode($sentMessage), true);
        $this->assertSame('tools/list', $data['method']);

        $meta = $data['params']['_meta'] ?? null;
        $this->assertIsArray($meta, 'Modern resumed session must stamp the _meta envelope');
        $this->assertSame(
            Version::LATEST_PROTOCOL_VERSION,
            $meta[MetaKeys::PROTOCOL_VERSION] ?? null
        );
        $this->assertArrayHasKey(MetaKeys::CLIENT_INFO, $meta);
        $this->assertArrayHasKey(MetaKeys::CLIENT_CAPABILITIES, $meta);
    }

    /**
     * Test that an explicitly provided wire version (the RC-window draft
     * alias) is preserved on the wire while the negotiated version is
     * canonicalized to the dated revision for internal feature gating —
     * matching enterModernMode()'s behavior on a live negotiation.
     */
    public function testRestoredSessionPreservesDraftAliasWireVersion(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();

        $readStream->send(new JsonRpcMessage(
            new JSONRPCResponse(
                jsonrpc: '2.0',
                id: new RequestId(3),
                result: []
            )
        ));

        $session = ClientSession::createRestored(
            readStream: $readStream,
            writeStream: $writeStream,
            initResult: $this->createInitResult(),
            negotiatedProtocolVersion: Version::LATEST_PROTOCOL_VERSION,
            nextRequestId: 3,
            readTimeout: 2.0,
            modernWireVersion: Version::DRAFT_MODERN_PROTOCOL_VERSION
        );

        $this->assertTrue($session->isModernMode());
        $this->assertSame(Version::DRAFT_MODERN_PROTOCOL_VERSION, $session->getModernWireVersion());
        $this->assertSame(Version::LATEST_PROTOCOL_VERSION, $session->getNegotiatedProtocolVersion());

        $session->sendPing();

        $data = json_decode(json_encode($writeStream->receive()), true);
        $this->assertSame(
            Version::DRAFT_MODERN_PROTOCOL_VERSION,
            $data['params']['_meta'][MetaKeys::PROTOCOL_VERSION] ?? null,
            'Envelope must carry the alias the server negotiated, not the canonical id'
        );
    }

    /**
     * Test that a restored legacy session stays legacy: no modern mode and
     * no `_meta` envelope on outgoing requests (regression guard — legacy
     * servers negotiated via initialize must keep seeing unchanged wire
     * shapes after a resume).
     */
    public function testRestoredLegacySessionDoesNotStampEnvelope(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();

        $readStream->send(new JsonRpcMessage(
            new JSONRPCResponse(
                jsonrpc: '2.0',
                id: new RequestId(2),
                result: []
            )
        ));

        $session = ClientSession::createRestored(
            readStream: $readStream,
            writeStream: $writeStream,
            initResult: $this->createInitResult(),
            negotiatedProtocolVersion: Version::LATEST_LEGACY_PROTOCOL_VERSION,
            nextRequestId: 2,
            readTimeout: 2.0
        );

        $this->assertFalse($session->isModernMode());
        $this->assertNull($session->getModernWireVersion());

        $session->sendPing();

        $data = json_decode(json_encode($writeStream->receive()), true);
        $this->assertSame('ping', $data['method']);
        $this->assertArrayNotHasKey(
            '_meta',
            $data['params'] ?? [],
            'Legacy resumed session must not stamp the modern envelope'
        );
    }

    private function createInitResult(): InitializeResult
    {
        return new InitializeResult(
            capabilities: new ServerCapabilities(),
            serverInfo: new Implementation(name: 'test-server', version: '1.0.0'),
            protocolVersion: Version::LATEST_PROTOCOL_VERSION
        );
    }
}
