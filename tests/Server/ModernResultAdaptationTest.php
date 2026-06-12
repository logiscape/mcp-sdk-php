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
 * Filename: tests/Server/ModernResultAdaptationTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\InitializationOptions;
use Mcp\Server\McpServer;
use Mcp\Server\ServerSession;
use Mcp\Server\Transport\Transport;
use Mcp\Shared\Version;
use Mcp\Types\CacheableResult;
use Mcp\Types\CallToolResult;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\ListToolsResult;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\RequestId;
use Mcp\Types\RequestParams;
use Mcp\Types\Result;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\TextContent;
use PHPUnit\Framework\TestCase;

/**
 * Tests for version-gated result adaptation around the 2026-07-28 revision:
 *
 * - Modern clients: every result is stamped with `resultType` (SEP-2322
 *   discriminator, required by the draft schema) and cacheable results get
 *   the required `ttlMs` / `cacheScope` (SEP-2549) when the handler left
 *   them unset.
 * - Legacy clients: those same fields are stripped so legacy wire output is
 *   unchanged, and non-object structuredContent (SEP-2106) is removed.
 * - SEP-2164: a missing resource is -32602 with data.uri under 2026-07-28
 *   and stays -32002 on legacy revisions.
 */
final class ModernResultAdaptationTest extends TestCase
{
    private function makeSession(string $negotiatedVersion): ServerSession
    {
        $session = new ServerSession(
            new AdaptationTestTransport(),
            new InitializationOptions(
                serverName: 'adapt-test',
                serverVersion: '1.0.0',
                capabilities: new ServerCapabilities()
            )
        );
        $session->setNegotiatedProtocolVersion($negotiatedVersion);
        return $session;
    }

    /**
     * Under 2026-07-28, a bare cacheable result gets resultType "complete"
     * and the conservative default caching hints (ttlMs 0, scope private) —
     * both fields are REQUIRED by the draft schema.
     */
    public function testModernClientGetsStampedDefaults(): void
    {
        $session = $this->makeSession('2026-07-28');

        $result = new ListToolsResult([]);
        $adapted = $session->adaptResponseForClient($result);

        $this->assertSame('complete', $adapted->resultType);
        $this->assertSame(0, $adapted->getTtlMs());
        $this->assertSame(CacheableResult::CACHE_SCOPE_PRIVATE, $adapted->getCacheScope());
    }

    /**
     * Handler-chosen caching hints and resultType survive modern adaptation
     * untouched — stamping only fills gaps.
     */
    public function testModernClientKeepsHandlerChosenValues(): void
    {
        $session = $this->makeSession('2026-07-28');

        $result = new ListToolsResult([]);
        $result->setCacheHints(60000, CacheableResult::CACHE_SCOPE_PUBLIC);
        $adapted = $session->adaptResponseForClient($result);

        $this->assertSame(60000, $adapted->getTtlMs());
        $this->assertSame('public', $adapted->getCacheScope());
    }

    /**
     * Non-cacheable results get only the resultType discriminator under
     * 2026-07-28 — never caching hints (the schema restricts ttlMs/cacheScope
     * to the six CacheableResult carriers).
     */
    public function testModernNonCacheableResultGetsOnlyResultType(): void
    {
        $session = $this->makeSession('2026-07-28');

        $result = new CallToolResult(content: [new TextContent(text: 'hi')]);
        $adapted = $session->adaptResponseForClient($result);

        $this->assertSame('complete', $adapted->resultType);
        $wire = json_decode(json_encode($adapted), true);
        $this->assertArrayNotHasKey('ttlMs', $wire);
        $this->assertArrayNotHasKey('cacheScope', $wire);
    }

    /**
     * For a legacy client, resultType and caching hints are stripped even if
     * a handler set them, keeping legacy wire output identical to pre-v2
     * behavior (WS6 promise).
     */
    public function testLegacyClientGetsFieldsStripped(): void
    {
        $session = $this->makeSession('2025-11-25');

        $result = new ListToolsResult([]);
        $result->setCacheHints(60000, CacheableResult::CACHE_SCOPE_PUBLIC);
        $result->resultType = Result::RESULT_TYPE_COMPLETE;

        $adapted = $session->adaptResponseForClient($result);

        $this->assertNull($adapted->resultType);
        $this->assertNull($adapted->getTtlMs());
        $this->assertNull($adapted->getCacheScope());

        $wire = json_decode(json_encode($adapted), true);
        $this->assertArrayNotHasKey('resultType', $wire);
        $this->assertArrayNotHasKey('ttlMs', $wire);
        $this->assertArrayNotHasKey('cacheScope', $wire);
    }

    /**
     * SEP-2106: scalar structuredContent reaches a 2026-07-28 client intact
     * but is stripped for a 2025-11-25 client (which only knows object-shaped
     * structuredContent). The serialized-JSON TextContent block remains.
     */
    public function testScalarStructuredContentVersionGating(): void
    {
        $result = new CallToolResult(
            content: [new TextContent(text: '42')],
            structuredContent: 42
        );

        $modern = $this->makeSession('2026-07-28')->adaptResponseForClient($result);
        $this->assertSame(42, $modern->structuredContent);

        $legacy = $this->makeSession('2025-11-25')->adaptResponseForClient($result);
        $this->assertNull($legacy->structuredContent);
        $this->assertCount(1, $legacy->content, 'Text fallback content must survive');

        // Object-shaped structuredContent is untouched for 2025-11-25 (it has
        // supported structured content since 2025-06-18)
        $objectResult = new CallToolResult(
            content: [new TextContent(text: '{}')],
            structuredContent: ['answer' => 42]
        );
        $legacyObject = $this->makeSession('2025-11-25')->adaptResponseForClient($objectResult);
        $this->assertSame(['answer' => 42], $legacyObject->structuredContent);
    }

    /**
     * SEP-2106: a PHP list array serializes as a JSON array, which legacy
     * clients (object-only structuredContent) cannot accept — it must be
     * stripped for them just like scalars. Associative arrays (JSON objects)
     * and the ambiguous empty array keep their pre-v2 behavior.
     */
    public function testListArrayStructuredContentVersionGating(): void
    {
        $listResult = new CallToolResult(
            content: [new TextContent(text: '[1,2,3]')],
            structuredContent: [1, 2, 3]
        );

        $modern = $this->makeSession('2026-07-28')->adaptResponseForClient($listResult);
        $this->assertSame([1, 2, 3], $modern->structuredContent);

        $legacy = $this->makeSession('2025-11-25')->adaptResponseForClient($listResult);
        $this->assertNull($legacy->structuredContent, 'JSON-array structuredContent must be stripped for legacy clients');

        // Associative arrays (JSON objects) survive for legacy clients
        $objectResult = new CallToolResult(
            content: [new TextContent(text: '{}')],
            structuredContent: ['k' => 'v']
        );
        $legacyObject = $this->makeSession('2025-11-25')->adaptResponseForClient($objectResult);
        $this->assertSame(['k' => 'v'], $legacyObject->structuredContent);

        // The ambiguous empty array (how handlers express an empty JSON
        // object) keeps its pre-v2 behavior for legacy clients
        $emptyResult = new CallToolResult(
            content: [new TextContent(text: '{}')],
            structuredContent: []
        );
        $legacyEmpty = $this->makeSession('2025-11-25')->adaptResponseForClient($emptyResult);
        $this->assertSame([], $legacyEmpty->structuredContent);
    }

    /**
     * SEP-2106: explicit JSON null structuredContent reaches modern clients
     * on the wire and is stripped (field omitted entirely) for legacy ones.
     */
    public function testExplicitNullStructuredContentVersionGating(): void
    {
        $result = new CallToolResult(content: [new TextContent(text: 'null')]);
        $result->setStructuredContentNull();

        $modern = $this->makeSession('2026-07-28')->adaptResponseForClient($result);
        $modernWire = json_decode(json_encode($modern), true);
        $this->assertArrayHasKey('structuredContent', $modernWire);
        $this->assertNull($modernWire['structuredContent']);

        $legacy = $this->makeSession('2025-11-25')->adaptResponseForClient($result);
        $legacyWire = json_decode(json_encode($legacy), true);
        $this->assertArrayNotHasKey('structuredContent', $legacyWire);
    }

    // -----------------------------------------------------------------------
    // SEP-2106: McpServer tool results with an outputSchema
    // -----------------------------------------------------------------------

    /**
     * Invoke a registered tool through McpServer's tools/call handler and
     * return the CallToolResult.
     */
    private function callTool(McpServer $mcpServer, string $name): CallToolResult
    {
        $handler = $mcpServer->getServer()->getHandlers()['tools/call'];
        $params = new RequestParams();
        $params->name = $name;
        return $handler($params);
    }

    /**
     * With an outputSchema declared, the callback's return value IS the
     * structured output for ANY JSON value — including strings, which
     * previously produced only text content.
     */
    public function testToolWithOutputSchemaStringReturn(): void
    {
        $mcpServer = new McpServer('sep2106-test');
        $mcpServer->tool('greet', 'Greets', fn(): string => 'hello', outputSchema: ['type' => 'string']);

        $result = $this->callTool($mcpServer, 'greet');

        $this->assertSame('hello', $result->structuredContent);
        $this->assertSame('"hello"', $result->content[0]->text, 'Text block carries the serialized JSON');
    }

    /**
     * A null return with an outputSchema becomes an EXPLICIT
     * structuredContent: null on the wire.
     */
    public function testToolWithOutputSchemaNullReturn(): void
    {
        $mcpServer = new McpServer('sep2106-test');
        $mcpServer->tool('nothing', 'Returns null', fn() => null, outputSchema: ['type' => 'null']);

        $result = $this->callTool($mcpServer, 'nothing');

        $this->assertTrue($result->hasExplicitNullStructuredContent());
        $wire = json_decode(json_encode($result), true);
        $this->assertArrayHasKey('structuredContent', $wire);
        $this->assertNull($wire['structuredContent']);
        $this->assertSame('null', $result->content[0]->text);
    }

    /**
     * Without an outputSchema, string returns keep their historical
     * text-only behavior and null returns are still rejected.
     */
    public function testToolWithoutOutputSchemaUnchanged(): void
    {
        $mcpServer = new McpServer('sep2106-test');
        $mcpServer->tool('plain', 'Plain text', fn(): string => 'hello');

        $result = $this->callTool($mcpServer, 'plain');

        $this->assertSame('hello', $result->content[0]->text);
        $this->assertNull($result->structuredContent);
        $this->assertFalse($result->hasExplicitNullStructuredContent());
        $wire = json_decode(json_encode($result), true);
        $this->assertArrayNotHasKey('structuredContent', $wire);
    }

    // -----------------------------------------------------------------------
    // SEP-2164: resource-not-found error code
    // -----------------------------------------------------------------------

    /**
     * Drive a resources/read for a nonexistent URI through a real McpServer
     * handler registry and assert the era-correct error shape.
     */
    private function readMissingResource(string $negotiatedVersion): JSONRPCError
    {
        $mcpServer = new McpServer('sep2164-test');
        $mcpServer->resource(uri: 'test://exists', name: 'Exists', callback: fn() => 'ok');

        $server = $mcpServer->getServer();
        $transport = new AdaptationTestTransport();
        $session = new AdaptationTestableSession(
            $transport,
            new InitializationOptions(
                serverName: 'sep2164-test',
                serverVersion: '1.0.0',
                capabilities: new ServerCapabilities()
            )
        );
        $server->setSession($session);
        $session->registerHandlers($server->getHandlers());

        if (Version::supportsFeature($negotiatedVersion, 'stateless_lifecycle')) {
            // Modern era: no handshake exists; the setter is the era seam.
            $session->setNegotiatedProtocolVersion($negotiatedVersion);
        } else {
            // Legacy era: negotiate through the real initialize handshake.
            $initParams = new RequestParams();
            $initParams->protocolVersion = $negotiatedVersion;
            $initParams->capabilities = [];
            $initParams->clientInfo = ['name' => 'legacy-client', 'version' => '1.0'];
            $session->processIncoming(new JsonRpcMessage(new JSONRPCRequest(
                jsonrpc: '2.0',
                id: new RequestId(1),
                method: 'initialize',
                params: $initParams
            )));
            $transport->writtenMessages = [];
        }

        $params = new RequestParams();
        $params->uri = 'test://nonexistent-resource-for-conformance-testing';
        $session->processIncoming(new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId(7),
            method: 'resources/read',
            params: $params
        )));

        $inner = $transport->writtenMessages[0]->message;
        $this->assertInstanceOf(JSONRPCError::class, $inner, 'Missing resource must be an error, never empty contents');
        return $inner;
    }

    public function testMissingResourceModernErrorCode(): void
    {
        $error = $this->readMissingResource('2026-07-28');

        $this->assertSame(-32602, $error->error->code);
        $this->assertSame('Resource not found', $error->error->message);
        $data = (array) $error->error->data;
        $this->assertSame('test://nonexistent-resource-for-conformance-testing', $data['uri']);
    }

    public function testMissingResourceLegacyErrorCode(): void
    {
        $error = $this->readMissingResource('2025-11-25');

        $this->assertSame(-32002, $error->error->code);
        $data = (array) $error->error->data;
        $this->assertSame('test://nonexistent-resource-for-conformance-testing', $data['uri']);
    }

    public function testMissingResourceOldestLegacyErrorCode(): void
    {
        $error = $this->readMissingResource('2024-11-05');
        $this->assertSame(-32002, $error->error->code);
    }

    /**
     * Version gating sanity: the existing legacy adaptations (audio
     * stripping for pre-2025-03-26 clients) still run now that the modern
     * branch exists in adaptResponseForClient().
     */
    public function testLegacyAudioAdaptationStillRuns(): void
    {
        $session = $this->makeSession('2024-11-05');

        $result = new CallToolResult(content: [
            new TextContent(text: 'hello'),
            new \Mcp\Types\AudioContent(data: base64_encode('x'), mimeType: 'audio/wav'),
        ]);

        $adapted = $session->adaptResponseForClient($result);
        $this->assertCount(1, $adapted->content, 'AudioContent must be stripped for 2024-11-05');
        $this->assertInstanceOf(TextContent::class, $adapted->content[0]);
    }
}

/**
 * Captures messages written by the session (Transport contract).
 */
final class AdaptationTestTransport implements Transport
{
    /** @var JsonRpcMessage[] */
    public array $writtenMessages = [];

    public function start(): void
    {
    }

    public function stop(): void
    {
    }

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
 * Exposes BaseSession::handleIncomingMessage() for wire-path testing.
 */
final class AdaptationTestableSession extends ServerSession
{
    public function processIncoming(JsonRpcMessage $message): void
    {
        $this->handleIncomingMessage($message);
    }
}
