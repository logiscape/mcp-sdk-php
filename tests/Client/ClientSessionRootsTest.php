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

namespace Mcp\Tests\Client;

use Mcp\Client\ClientSession;
use Mcp\Shared\MemoryStream;
use Mcp\Shared\Version;
use Mcp\Types\Implementation;
use Mcp\Types\InitializeResult;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\ListRootsResult;
use Mcp\Types\RequestId;
use Mcp\Types\Root;
use Mcp\Types\ServerCapabilities;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ClientSession::onListRoots() — the roots capability advertisement
 * and the server-initiated `roots/list` dispatch path.
 *
 * Per the MCP 2025-11-25 spec, a client that supports roots MUST declare the
 * `roots` capability during initialization, and may only use capabilities that
 * were negotiated. These tests pin down that registering a handler advertises
 * the capability and answers `roots/list`.
 */
final class ClientSessionRootsTest extends TestCase
{
    public function testCapabilityNotAdvertisedWhenNoHandler(): void
    {
        // No handler → no `roots` capability in the initialize request, matching
        // the pre-existing behavior for clients that don't support roots.
        $session = $this->makeInitializedSession(null, true, $writeStream);
        $this->assertInstanceOf(ClientSession::class, $session);
        $capabilities = $this->extractInitCapabilities($writeStream);
        $this->assertArrayNotHasKey('roots', $capabilities);
    }

    public function testCapabilityAdvertisesListChangedTrueByDefault(): void
    {
        $session = $this->makeInitializedSession(
            static fn(): ListRootsResult => new ListRootsResult(roots: []),
            true,
            $writeStream
        );
        $this->assertInstanceOf(ClientSession::class, $session);
        $capabilities = $this->extractInitCapabilities($writeStream);
        $this->assertArrayHasKey('roots', $capabilities);
        $this->assertArrayHasKey('listChanged', $capabilities['roots']);
        $this->assertTrue($capabilities['roots']['listChanged']);
    }

    public function testCapabilityAdvertisesListChangedFalseWhenOptedOut(): void
    {
        $session = $this->makeInitializedSession(
            static fn(): ListRootsResult => new ListRootsResult(roots: []),
            false,
            $writeStream
        );
        $this->assertInstanceOf(ClientSession::class, $session);
        $capabilities = $this->extractInitCapabilities($writeStream);
        $this->assertArrayHasKey('roots', $capabilities);
        // A static root set still declares the capability, but with
        // listChanged: false so a compliant server won't expect notifications.
        $this->assertArrayHasKey('listChanged', $capabilities['roots']);
        $this->assertFalse($capabilities['roots']['listChanged']);
    }

    public function testHandlerInvokedAndResponseSentOnListRoots(): void
    {
        $invoked = false;
        $handler = static function () use (&$invoked): ListRootsResult {
            $invoked = true;
            return new ListRootsResult(roots: [
                new Root(uri: 'file:///home/alice/projects/website', name: 'website'),
                new Root(uri: 'file:///home/alice/projects/api', name: 'api'),
            ]);
        };

        $session = $this->makeInitializedSession($handler, true, $writeStream);
        $this->drainInitMessages($writeStream);

        $session->dispatchIncomingMessage($this->makeListRootsRequest(42));

        $this->assertTrue($invoked, 'Handler should have been called for roots/list');

        $response = $this->receiveResponse($writeStream);
        $this->assertSame(42, $response['id']);
        $this->assertCount(2, $response['result']['roots']);
        $this->assertSame('file:///home/alice/projects/website', $response['result']['roots'][0]['uri']);
        $this->assertSame('website', $response['result']['roots'][0]['name']);
    }

    public function testOnListRootsRejectsPostInitCall(): void
    {
        $session = $this->makeInitializedSession(null, true, $writeStream);
        $this->expectException(\RuntimeException::class);
        $session->onListRoots(static fn(): ListRootsResult => new ListRootsResult(roots: []));
    }

    public function testOnListRootsAllowedOnRestoredSession(): void
    {
        // Restored sessions skip the handshake — the capability was negotiated
        // in a prior PHP request. Registration must still succeed so the
        // server-initiated roots/list dispatch path is wired up.
        $invoked = false;
        $session = $this->makeRestoredSession($writeStream);
        $session->onListRoots(static function () use (&$invoked): ListRootsResult {
            $invoked = true;
            return new ListRootsResult(roots: []);
        });

        $session->dispatchIncomingMessage($this->makeListRootsRequest(9));

        $this->assertTrue($invoked, 'Restored-session handler should fire');
        $response = $this->receiveResponse($writeStream);
        $this->assertSame(9, $response['id']);
        $this->assertSame([], $response['result']['roots']);
    }

    public function testHandlerErrorProducesJsonRpcError(): void
    {
        $handler = static function (): ListRootsResult {
            throw new \RuntimeException('roots handler blew up');
        };

        $session = $this->makeInitializedSession($handler, true, $writeStream);
        $this->drainInitMessages($writeStream);

        $session->dispatchIncomingMessage($this->makeListRootsRequest(7));

        $writtenRaw = $writeStream->receive();
        $this->assertInstanceOf(JsonRpcMessage::class, $writtenRaw);
        $this->assertInstanceOf(JSONRPCError::class, $writtenRaw->message);
        $decoded = json_decode(json_encode($writtenRaw), true);
        $this->assertSame(7, $decoded['id']);
        $this->assertSame(-32603, $decoded['error']['code']);
        $this->assertStringContainsString('roots handler blew up', $decoded['error']['message']);
    }

    // ---------------------------------------------------------------------
    // Fixtures / helpers
    // ---------------------------------------------------------------------

    /**
     * Create and initialize a ClientSession, optionally wiring a roots handler
     * before the handshake. Returns the session and sets $writeStream to the
     * stream carrying outbound messages (init + subsequent responses).
     */
    private function makeInitializedSession(
        ?callable $handler,
        bool $listChanged,
        ?MemoryStream &$writeStream
    ): ClientSession {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();

        $readStream->send(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(0),
            result: [
                'protocolVersion' => Version::LATEST_PROTOCOL_VERSION,
                'capabilities' => [],
                'serverInfo' => ['name' => 'test-server', 'version' => '1.0.0'],
            ]
        )));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);
        if ($handler !== null) {
            $session->onListRoots($handler, $listChanged);
        }
        $session->initialize();

        return $session;
    }

    /**
     * Pull both init messages (initialize request + initialized notification)
     * off the write stream so subsequent receive() calls see only responses to
     * server-initiated requests.
     */
    private function drainInitMessages(MemoryStream $writeStream): void
    {
        $writeStream->receive(); // initialize request
        $writeStream->receive(); // notifications/initialized
    }

    /**
     * Build a session via createRestored() — no handshake, already "initialized."
     */
    private function makeRestoredSession(?MemoryStream &$writeStream): ClientSession
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();

        $initResult = new InitializeResult(
            capabilities: new ServerCapabilities(),
            serverInfo: new Implementation(name: 'test-server', version: '1.0.0'),
            protocolVersion: Version::LATEST_PROTOCOL_VERSION,
        );

        return ClientSession::createRestored(
            readStream: $readStream,
            writeStream: $writeStream,
            initResult: $initResult,
            negotiatedProtocolVersion: Version::LATEST_PROTOCOL_VERSION,
            nextRequestId: 0,
            readTimeout: 2.0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function extractInitCapabilities(MemoryStream $writeStream): array
    {
        $initMessage = $writeStream->receive();
        $decoded = json_decode(json_encode($initMessage), true);
        return $decoded['params']['capabilities'] ?? [];
    }

    private function makeListRootsRequest(int $id): JsonRpcMessage
    {
        // roots/list carries no params.
        return new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId($id),
            params: null,
            method: 'roots/list'
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function receiveResponse(MemoryStream $writeStream): array
    {
        $raw = $writeStream->receive();
        $this->assertInstanceOf(JsonRpcMessage::class, $raw);
        $this->assertInstanceOf(JSONRPCResponse::class, $raw->message);
        return json_decode(json_encode($raw), true);
    }
}
