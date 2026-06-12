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
 * Filename: tests/Server/ServerDiscoverTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\InitializationOptions;
use Mcp\Server\NotificationOptions;
use Mcp\Server\Server;
use Mcp\Server\ServerSession;
use Mcp\Server\Transport\Transport;
use Mcp\Shared\McpError;
use Mcp\Shared\Version;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\DiscoverResult;
use Mcp\Types\MetaKeys;
use Mcp\Types\RequestId;
use Mcp\Types\RequestParams;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\InitializeResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the server/discover request (SEP-2575, revision 2026-07-28).
 *
 * server/discover replaces the metadata half of the legacy initialize
 * handshake: it must be answerable WITHOUT any prior handshake, must
 * advertise the same capabilities the legacy initialize result advertises,
 * must reject a malformed _meta envelope with -32602 naming the missing
 * field, and must answer an unsupported protocol version with -32004
 * carrying the supported/requested data payload.
 *
 * Messages are fed through BaseSession::handleIncomingMessage() (the real
 * wire intake path), not by calling handlers directly.
 */
final class ServerDiscoverTest extends TestCase
{
    private function makeSession(?ServerCapabilities $capabilities = null): array
    {
        $transport = new DiscoverTestTransport();
        $options = new InitializationOptions(
            serverName: 'discover-test-server',
            serverVersion: '3.2.1',
            capabilities: $capabilities ?? new ServerCapabilities()
        );
        $session = new DiscoverTestableSession($transport, $options);
        return [$transport, $session];
    }

    /**
     * Build a raw wire-shaped server/discover message. $meta === null sends
     * params without any _meta; otherwise the given array becomes _meta.
     */
    private function makeDiscoverMessage(?array $meta, int $id = 1): JsonRpcMessage
    {
        $params = $meta === null ? [] : ['_meta' => $meta];
        return new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId($id),
            method: 'server/discover',
            params: new RawDiscoverWireParams($params)
        ));
    }

    /** A complete, valid modern _meta envelope. */
    private function validEnvelope(string $version = '2026-07-28'): array
    {
        return [
            MetaKeys::PROTOCOL_VERSION => $version,
            MetaKeys::CLIENT_INFO => ['name' => 'test-client', 'version' => '1.0.0'],
            MetaKeys::CLIENT_CAPABILITIES => [],
        ];
    }

    /**
     * server/discover answers BEFORE any initialize handshake — the
     * stateless lifecycle has no handshake to wait for — and the result
     * carries every schema-required field: supportedVersions, capabilities,
     * serverInfo, resultType, ttlMs, and cacheScope.
     */
    public function testDiscoverAnswersWithoutInitialization(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeDiscoverMessage($this->validEnvelope()));

        $this->assertCount(1, $transport->writtenMessages);
        $inner = $transport->writtenMessages[0]->message;
        $this->assertInstanceOf(JSONRPCResponse::class, $inner, 'Discover must succeed pre-initialization');

        /** @var DiscoverResult $result */
        $result = $inner->result;
        $this->assertInstanceOf(DiscoverResult::class, $result);
        // The advertised list covers every negotiable revision plus the
        // RC-window draft alias for the stateless revision (WS2).
        $this->assertSame(Version::advertisedSupportedVersions(), $result->supportedVersions);
        $this->assertSame('discover-test-server', $result->serverInfo->name);
        $this->assertSame('3.2.1', $result->serverInfo->version);
        $this->assertSame('complete', $result->resultType);
        $this->assertSame(0, $result->getTtlMs());
        $this->assertSame('public', $result->getCacheScope());

        // All required wire keys present after serialization
        $wire = json_decode(json_encode($result), true);
        foreach (['supportedVersions', 'capabilities', 'serverInfo', 'resultType', 'ttlMs', 'cacheScope'] as $key) {
            $this->assertArrayHasKey($key, $wire, "Discover result must carry '$key'");
        }
    }

    /**
     * The capabilities in the discover result are IDENTICAL on the wire to
     * what the legacy initialize result advertises for the same server —
     * the two discovery surfaces can never disagree (WS1 completion
     * criterion).
     */
    public function testDiscoverCapabilitiesMatchInitializeResult(): void
    {
        // Build capabilities from a real Server with registered handlers,
        // exactly as McpServer does.
        $server = new Server('cap-test');
        $server->registerHandler('tools/list', fn() => null);
        $server->registerHandler('prompts/list', fn() => null);
        $initOptions = $server->createInitializationOptions(new NotificationOptions());

        // Legacy initialize on one session
        [$legacyTransport, $legacySession] = [new DiscoverTestTransport(), null];
        $legacySession = new DiscoverTestableSession($legacyTransport, $initOptions);
        $legacySession->processIncoming(new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId(1),
            method: 'initialize',
            params: new RawDiscoverWireParams([
                'protocolVersion' => Version::LATEST_LEGACY_PROTOCOL_VERSION,
                'capabilities' => [],
                'clientInfo' => ['name' => 'legacy', 'version' => '1.0'],
            ])
        )));
        /** @var InitializeResult $initResult */
        $initResult = $legacyTransport->writtenMessages[0]->message->result;

        // Modern discover on a fresh session with the same options
        $modernTransport = new DiscoverTestTransport();
        $modernSession = new DiscoverTestableSession($modernTransport, $initOptions);
        $modernSession->processIncoming($this->makeDiscoverMessage($this->validEnvelope()));
        /** @var DiscoverResult $discoverResult */
        $discoverResult = $modernTransport->writtenMessages[0]->message->result;

        $this->assertSame(
            json_encode($initResult->capabilities),
            json_encode($discoverResult->capabilities),
            'Discover capabilities must be wire-identical to initialize capabilities'
        );
        $this->assertSame(
            json_encode($initResult->serverInfo),
            json_encode($discoverResult->serverInfo),
            'Discover serverInfo must be wire-identical to initialize serverInfo'
        );
    }

    /**
     * Missing-envelope variants are each rejected with -32602 (Invalid
     * params), the code the draft conformance suite asserts for
     * sep-2575-request-meta-invalid-*.
     *
     * @dataProvider invalidEnvelopeProvider
     */
    public function testDiscoverRejectsInvalidEnvelope(?array $meta, string $expectedFragment): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeDiscoverMessage($meta));

        $this->assertCount(1, $transport->writtenMessages);
        $inner = $transport->writtenMessages[0]->message;
        $this->assertInstanceOf(JSONRPCError::class, $inner);
        $this->assertSame(-32602, $inner->error->code);
        $this->assertStringContainsString($expectedFragment, $inner->error->message);
    }

    public static function invalidEnvelopeProvider(): array
    {
        return [
            'missing _meta entirely' => [null, '_meta'],
            'missing protocolVersion' => [
                [
                    MetaKeys::CLIENT_INFO => ['name' => 'c', 'version' => '1'],
                    MetaKeys::CLIENT_CAPABILITIES => [],
                ],
                MetaKeys::PROTOCOL_VERSION,
            ],
            'missing clientInfo' => [
                [
                    MetaKeys::PROTOCOL_VERSION => '2026-07-28',
                    MetaKeys::CLIENT_CAPABILITIES => [],
                ],
                MetaKeys::CLIENT_INFO,
            ],
            'missing clientCapabilities' => [
                [
                    MetaKeys::PROTOCOL_VERSION => '2026-07-28',
                    MetaKeys::CLIENT_INFO => ['name' => 'c', 'version' => '1'],
                ],
                MetaKeys::CLIENT_CAPABILITIES,
            ],
            // Type validation (SEP-2575: clientInfo must be a valid
            // Implementation object, clientCapabilities a capabilities
            // object — strings or JSON arrays are malformed envelopes)
            'clientInfo is a string' => [
                [
                    MetaKeys::PROTOCOL_VERSION => '2026-07-28',
                    MetaKeys::CLIENT_INFO => 'not-an-object',
                    MetaKeys::CLIENT_CAPABILITIES => [],
                ],
                MetaKeys::CLIENT_INFO,
            ],
            'clientInfo missing version' => [
                [
                    MetaKeys::PROTOCOL_VERSION => '2026-07-28',
                    MetaKeys::CLIENT_INFO => ['name' => 'c'],
                    MetaKeys::CLIENT_CAPABILITIES => [],
                ],
                MetaKeys::CLIENT_INFO,
            ],
            'clientCapabilities is a string' => [
                [
                    MetaKeys::PROTOCOL_VERSION => '2026-07-28',
                    MetaKeys::CLIENT_INFO => ['name' => 'c', 'version' => '1'],
                    MetaKeys::CLIENT_CAPABILITIES => 'not-an-object',
                ],
                MetaKeys::CLIENT_CAPABILITIES,
            ],
            'clientCapabilities is a JSON array' => [
                [
                    MetaKeys::PROTOCOL_VERSION => '2026-07-28',
                    MetaKeys::CLIENT_INFO => ['name' => 'c', 'version' => '1'],
                    MetaKeys::CLIENT_CAPABILITIES => ['sampling', 'roots'],
                ],
                MetaKeys::CLIENT_CAPABILITIES,
            ],
            'protocolVersion is not a string' => [
                [
                    MetaKeys::PROTOCOL_VERSION => 20260728,
                    MetaKeys::CLIENT_INFO => ['name' => 'c', 'version' => '1'],
                    MetaKeys::CLIENT_CAPABILITIES => [],
                ],
                MetaKeys::PROTOCOL_VERSION,
            ],
        ];
    }

    /**
     * An unsupported protocol version in the envelope is answered with
     * -32004 (UnsupportedProtocolVersionError) whose data carries
     * `supported` (every advertised version, matching the discover result)
     * and `requested` (echoed back) — the exact wire shape from the draft
     * schema's example.
     */
    public function testDiscoverUnsupportedVersionError(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeDiscoverMessage($this->validEnvelope('v999.0.0')));

        $inner = $transport->writtenMessages[0]->message;
        $this->assertInstanceOf(JSONRPCError::class, $inner);
        $this->assertSame(McpError::UNSUPPORTED_PROTOCOL_VERSION, $inner->error->code);
        $this->assertSame(-32004, $inner->error->code);

        $data = (array) $inner->error->data;
        $this->assertSame('v999.0.0', $data['requested']);
        $this->assertNotEmpty($data['supported']);
        $this->assertSame(Version::advertisedSupportedVersions(), $data['supported']);
    }

    /**
     * A legacy protocol version in the envelope is still a valid discover:
     * the request itself is version-agnostic; the server simply advertises
     * what it supports.
     */
    public function testDiscoverAcceptsLegacyVersionInEnvelope(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeDiscoverMessage($this->validEnvelope('2025-11-25')));

        $inner = $transport->writtenMessages[0]->message;
        $this->assertInstanceOf(JSONRPCResponse::class, $inner);
        $this->assertInstanceOf(DiscoverResult::class, $inner->result);
    }

    /**
     * Discover also answers after a legacy handshake completed on the same
     * session — a dual-era server serves both discovery surfaces.
     */
    public function testDiscoverAnswersAfterLegacyInitialize(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming(new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId(1),
            method: 'initialize',
            params: new RawDiscoverWireParams([
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'legacy', 'version' => '1.0'],
            ])
        )));
        $session->processIncoming($this->makeDiscoverMessage($this->validEnvelope(), id: 2));

        $this->assertCount(2, $transport->writtenMessages);
        $discoverInner = $transport->writtenMessages[1]->message;
        $this->assertInstanceOf(JSONRPCResponse::class, $discoverInner);
        $this->assertInstanceOf(DiscoverResult::class, $discoverInner->result);
    }

    /**
     * The negotiated-version setter (the WS2 per-request era-detection seam)
     * validates its input and marks the session ready when selecting the
     * stateless revision — under SEP-2575 there is no handshake to wait for.
     */
    public function testSetNegotiatedProtocolVersion(): void
    {
        [, $session] = $this->makeSession();

        $session->setNegotiatedProtocolVersion('2026-07-28');
        $this->assertSame('2026-07-28', $session->getNegotiatedProtocolVersion());
        $this->assertTrue($session->clientSupportsFeature('stateless_lifecycle'));

        $this->expectException(\InvalidArgumentException::class);
        $session->setNegotiatedProtocolVersion('1999-01-01');
    }

    /**
     * Initialize requesting the stateless revision clamps to the latest
     * LEGACY revision: a client that sends initialize is by definition
     * speaking the legacy era (SEP-2575 removed the handshake), so the
     * handshake can never negotiate 2026-07-28.
     */
    public function testInitializeNeverNegotiatesStatelessRevision(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming(new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId(1),
            method: 'initialize',
            params: new RawDiscoverWireParams([
                'protocolVersion' => '2026-07-28',
                'capabilities' => [],
                'clientInfo' => ['name' => 'confused-client', 'version' => '1.0'],
            ])
        )));

        /** @var InitializeResult $result */
        $result = $transport->writtenMessages[0]->message->result;
        $this->assertSame(Version::LATEST_LEGACY_PROTOCOL_VERSION, $result->protocolVersion);
        $this->assertSame(Version::LATEST_LEGACY_PROTOCOL_VERSION, $session->getNegotiatedProtocolVersion());
    }
}

/**
 * Captures messages written by the session (Transport contract).
 */
final class DiscoverTestTransport implements Transport
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
 * Exposes BaseSession::handleIncomingMessage() so tests exercise the real
 * wire intake path (validation, typed-request construction, dispatch).
 */
final class DiscoverTestableSession extends ServerSession
{
    public function processIncoming(JsonRpcMessage $message): void
    {
        $this->handleIncomingMessage($message);
    }
}

/**
 * Wire-shaped params: serializes exactly the given array, replicating how
 * params look after JSON decoding on a real transport.
 */
final class RawDiscoverWireParams extends RequestParams
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
        parent::__construct();
    }

    public function jsonSerialize(): mixed
    {
        return $this->data !== [] ? $this->data : new \stdClass();
    }
}
