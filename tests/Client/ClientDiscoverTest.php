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
 * Filename: tests/Client/ClientDiscoverTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client;

use Mcp\Client\ClientSession;
use Mcp\Shared\McpError;
use Mcp\Shared\MemoryStream;
use Mcp\Shared\Version;
use Mcp\Types\DiscoverResult;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JsonRpcErrorObject;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\MetaKeys;
use Mcp\Types\RequestId;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ClientSession::discover() (SEP-2575, revision 2026-07-28).
 *
 * Validates that the client sends a self-contained server/discover request
 * carrying the required _meta envelope — WITHOUT any initialize handshake —
 * and correctly parses the DiscoverResult or surfaces the modern error
 * shapes (-32022 with supported/requested, -32601 from a legacy server).
 */
final class ClientDiscoverTest extends TestCase
{
    private function validDiscoverResultData(): array
    {
        return [
            'resultType' => 'complete',
            'supportedVersions' => ['2026-07-28', '2025-11-25'],
            'capabilities' => ['tools' => ['listChanged' => true]],
            'serverInfo' => ['name' => 'modern-server', 'version' => '2.0.0'],
            'ttlMs' => 3600000,
            'cacheScope' => 'public',
        ];
    }

    /**
     * discover() works pre-handshake and emits the full _meta envelope:
     * protocol version, client info, and client capabilities, all under the
     * io.modelcontextprotocol/ keys the draft schema reserves.
     */
    public function testDiscoverSendsEnvelopeAndParsesResult(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $readStream->send(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(0),
            result: $this->validDiscoverResultData()
        )));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        // No initialize() — discover is self-contained.
        $result = $session->discover();

        // Wire shape of the request
        $sent = $writeStream->receive();
        $this->assertInstanceOf(JsonRpcMessage::class, $sent);
        $this->assertInstanceOf(JSONRPCRequest::class, $sent->message);
        $wire = json_decode(json_encode($sent), true);
        $this->assertSame('server/discover', $wire['method']);
        $meta = $wire['params']['_meta'];
        $this->assertSame(Version::LATEST_PROTOCOL_VERSION, $meta[MetaKeys::PROTOCOL_VERSION]);
        $this->assertSame('mcp-client', $meta[MetaKeys::CLIENT_INFO]['name']);
        $this->assertArrayHasKey(MetaKeys::CLIENT_CAPABILITIES, $meta);

        // Parsed result
        $this->assertInstanceOf(DiscoverResult::class, $result);
        $this->assertSame(['2026-07-28', '2025-11-25'], $result->supportedVersions);
        $this->assertSame('modern-server', $result->serverInfo->name);
        $this->assertSame(3600000, $result->getTtlMs());
        $this->assertSame('public', $result->getCacheScope());
    }

    /**
     * An explicit protocol version overrides the default in the envelope.
     */
    public function testDiscoverWithExplicitVersion(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $readStream->send(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(0),
            result: $this->validDiscoverResultData()
        )));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);
        $session->discover('2025-11-25');

        $wire = json_decode(json_encode($writeStream->receive()), true);
        $this->assertSame('2025-11-25', $wire['params']['_meta'][MetaKeys::PROTOCOL_VERSION]);
    }

    /**
     * A -32601 from a legacy server (the WS2 fallback trigger) surfaces as a
     * typed McpError the caller can inspect.
     */
    public function testDiscoverLegacyServerMethodNotFound(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $readStream->send(new JsonRpcMessage(new JSONRPCError(
            jsonrpc: '2.0',
            id: new RequestId(0),
            error: new JsonRpcErrorObject(
                code: -32601,
                message: 'Method not found: server/discover',
                data: null
            )
        )));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        try {
            $session->discover();
            $this->fail('Expected McpError');
        } catch (McpError $e) {
            $this->assertSame(-32601, $e->error->code);
        }
    }

    /**
     * A -32022 UnsupportedProtocolVersionError surfaces with its
     * supported/requested data payload intact (the WS2 retry input).
     */
    public function testDiscoverUnsupportedVersionErrorSurfaces(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $readStream->send(new JsonRpcMessage(new JSONRPCError(
            jsonrpc: '2.0',
            id: new RequestId(0),
            error: new JsonRpcErrorObject(
                code: McpError::UNSUPPORTED_PROTOCOL_VERSION,
                message: 'Unsupported protocol version',
                data: ['supported' => ['2026-07-28'], 'requested' => '1900-01-01']
            )
        )));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        try {
            $session->discover('1900-01-01');
            $this->fail('Expected McpError');
        } catch (McpError $e) {
            $this->assertSame(-32022, $e->error->code);
            $data = (array) $e->error->data;
            $this->assertSame(['2026-07-28'], $data['supported']);
            $this->assertSame('1900-01-01', $data['requested']);
        }
    }
}
