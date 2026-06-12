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
 * Filename: tests/Server/ServerEraDetectionTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\InitializationOptions;
use Mcp\Server\Sampling\SamplingContext;
use Mcp\Server\ServerSession;
use Mcp\Server\Transport\Transport;
use Mcp\Shared\McpError;
use Mcp\Shared\Version;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\ListToolsResult;
use Mcp\Types\MetaKeys;
use Mcp\Types\NotificationParams;
use Mcp\Types\RequestId;
use Mcp\Types\RequestParams;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\TextContent;
use Mcp\Types\CallToolResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SEP-2575 per-request era detection in ServerSession (WS2).
 *
 * A dual-era server selects per request: a request carrying the modern
 * `_meta` envelope is served statelessly under the 2026-07-28 rules — even
 * when its method names a removed legacy construct like `initialize` —
 * while a bare `initialize` selects legacy semantics. The session id is
 * never part of the decision. Messages are fed through
 * BaseSession::handleIncomingMessage() (the real wire intake path).
 */
final class ServerEraDetectionTest extends TestCase
{
    /** @return array{EraTestTransport, EraTestableSession} */
    private function makeSession(): array
    {
        $transport = new EraTestTransport();
        $options = new InitializationOptions(
            serverName: 'era-test-server',
            serverVersion: '1.0.0',
            capabilities: new ServerCapabilities()
        );
        $session = new EraTestableSession($transport, $options);

        // A modern-servable method plus a tool that needs sampling.
        $session->registerHandlers([
            'tools/list' => fn($params) => new ListToolsResult([]),
            'tools/call' => function ($params) use ($session) {
                $sampling = new SamplingContext($session);
                if (!$sampling->supportsSampling()) {
                    // Mirrors what createMessage() does internally before
                    // returning null on legacy revisions.
                    $result = $sampling->createMessage(messages: [], maxTokens: 1);
                    return new CallToolResult(
                        content: [new TextContent('fallback: ' . var_export($result, true))]
                    );
                }
                return new CallToolResult(content: [new TextContent('sampling available')]);
            },
        ]);
        return [$transport, $session];
    }

    /** A complete, valid modern _meta envelope. */
    private function envelope(string $version = '2026-07-28', array $capabilities = []): array
    {
        return [
            MetaKeys::PROTOCOL_VERSION => $version,
            MetaKeys::CLIENT_INFO => ['name' => 'era-client', 'version' => '1.0.0'],
            MetaKeys::CLIENT_CAPABILITIES => $capabilities,
        ];
    }

    private function makeRequest(string $method, array $params, int $id = 1): JsonRpcMessage
    {
        return new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId($id),
            method: $method,
            params: new RawEraWireParams($params)
        ));
    }

    private function lastInner(EraTestTransport $transport): mixed
    {
        $this->assertNotEmpty($transport->writtenMessages, 'Expected a response to be written');
        return $transport->writtenMessages[count($transport->writtenMessages) - 1]->message;
    }

    /**
     * A request carrying the modern envelope is served WITHOUT any
     * initialize handshake: the era, version, and readiness come from the
     * envelope itself (SEP-2575). The modern result carries the required
     * resultType and SEP-2549 cache hints — and the adopted era is scoped
     * to the request: afterwards the session reports no negotiated state
     * (SEP-2567: nothing persists across requests).
     */
    public function testModernEnvelopeServedWithoutHandshake(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest('tools/list', ['_meta' => $this->envelope()]));

        $inner = $this->lastInner($transport);
        $this->assertInstanceOf(JSONRPCResponse::class, $inner);
        $wire = json_decode(json_encode($transport->writtenMessages[0]), true);
        $this->assertSame('complete', $wire['result']['resultType']);
        $this->assertArrayHasKey('ttlMs', $wire['result']);
        $this->assertArrayHasKey('cacheScope', $wire['result']);
        $this->assertFalse(
            $session->clientSupportsFeature('stateless_lifecycle'),
            'The adopted era must not outlive the request'
        );
    }

    /**
     * The adopted modern era is request-scoped: a later BARE request (no
     * envelope, no handshake) on the same connection must still be
     * rejected as a protocol violation — the modern request must not have
     * left the session marked initialized (review finding: modern stdio
     * state leaked into later unenveloped requests).
     */
    public function testModernStateDoesNotLeakToLegacyPath(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest('tools/list', ['_meta' => $this->envelope()], id: 1));
        $this->assertInstanceOf(JSONRPCResponse::class, $this->lastInner($transport));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('before initialization');
        $session->processIncoming($this->makeRequest('tools/list', [], id: 2));
    }

    /**
     * Conversely, a modern request on a session that previously completed
     * a LEGACY handshake must not clobber that session's negotiated state:
     * the legacy client's later requests keep their era-correct shape.
     */
    public function testModernRequestDoesNotClobberLegacySessionState(): void
    {
        [$transport, $session] = $this->makeSession();

        // Legacy handshake at 2025-06-18.
        $session->processIncoming($this->makeRequest('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'legacy', 'version' => '1.0'],
        ], id: 1));

        // A modern request interleaves (e.g. a probing client).
        $session->processIncoming($this->makeRequest('tools/list', ['_meta' => $this->envelope()], id: 2));
        $modernWire = json_decode(json_encode($transport->writtenMessages[1]), true);
        $this->assertSame('complete', $modernWire['result']['resultType']);

        // The legacy session state survived: version unchanged, and a
        // legacy request gets NO modern stamping.
        $this->assertSame('2025-06-18', $session->getNegotiatedProtocolVersion());
        $session->processIncoming($this->makeRequest('tools/list', [], id: 3));
        $legacyWire = json_decode(json_encode($transport->writtenMessages[2]), true);
        $this->assertArrayNotHasKey('resultType', $legacyWire['result']);
        $this->assertArrayNotHasKey('ttlMs', $legacyWire['result']);
    }

    /**
     * The RC-window draft identifier DRAFT-2026-v1 is accepted as an alias
     * for the stateless revision on the per-request path, and canonicalizes
     * to 2026-07-28 for the request's internal feature gating (the
     * capability check below requires the canonical version — the raw
     * alias would not version-compare correctly).
     */
    public function testDraftAliasAcceptedAndCanonicalized(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest('tools/list', [
            '_meta' => $this->envelope(Version::DRAFT_MODERN_PROTOCOL_VERSION),
        ], id: 1));
        $this->assertInstanceOf(JSONRPCResponse::class, $this->lastInner($transport));
        $wire = json_decode(json_encode($transport->writtenMessages[0]), true);
        $this->assertSame('complete', $wire['result']['resultType'], 'Draft alias must select modern stamping');

        $session->processIncoming($this->makeRequest('tools/call', [
            'name' => 'needs-sampling',
            'arguments' => [],
            '_meta' => $this->envelope(Version::DRAFT_MODERN_PROTOCOL_VERSION, ['sampling' => []]),
        ], id: 2));
        $callWire = json_decode(json_encode($transport->writtenMessages[1]), true);
        $this->assertSame(
            'sampling available',
            $callWire['result']['content'][0]['text'],
            'Capability + feature gating must run under the canonical 2026-07-28'
        );
    }

    /**
     * The draft alias never leaks into legacy negotiation: an initialize
     * handshake requesting it is clamped to the latest legacy revision.
     */
    public function testDraftAliasNotNegotiableViaInitialize(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest('initialize', [
            'protocolVersion' => Version::DRAFT_MODERN_PROTOCOL_VERSION,
            'capabilities' => [],
            'clientInfo' => ['name' => 'legacy', 'version' => '1.0'],
        ]));

        $inner = $this->lastInner($transport);
        $this->assertInstanceOf(JSONRPCResponse::class, $inner);
        $this->assertSame(
            Version::LATEST_LEGACY_PROTOCOL_VERSION,
            $session->getNegotiatedProtocolVersion()
        );
    }

    /**
     * Removed methods (SEP-2575 "Deprecated and Removed RPCs") answer
     * -32601 on the modern path — even `initialize` itself when it carries
     * a modern envelope (modern metadata wins over the method name), and
     * even methods like `ping` that have live legacy handlers.
     *
     * @dataProvider removedMethodProvider
     */
    public function testRemovedMethodsAnswerMethodNotFound(string $method): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest($method, ['_meta' => $this->envelope()]));

        $inner = $this->lastInner($transport);
        $this->assertInstanceOf(JSONRPCError::class, $inner);
        $this->assertSame(-32601, $inner->error->code);
        $this->assertSame(1, $inner->id->getValue(), 'Error must carry the original request id');
    }

    /** @return array<string, array{string}> */
    public static function removedMethodProvider(): array
    {
        return [
            'initialize' => ['initialize'],
            'ping' => ['ping'],
            'logging/setLevel' => ['logging/setLevel'],
            'resources/subscribe' => ['resources/subscribe'],
            'resources/unsubscribe' => ['resources/unsubscribe'],
        ];
    }

    /**
     * An unknown method on the modern path answers -32601 with the
     * original request id (the HTTP 404 mapping is asserted in
     * HttpModernRequestTest).
     */
    public function testUnknownMethodAnswersMethodNotFound(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest('subscriptions/listen', [
            '_meta' => $this->envelope(),
        ], id: 7));

        $inner = $this->lastInner($transport);
        $this->assertInstanceOf(JSONRPCError::class, $inner);
        $this->assertSame(-32601, $inner->error->code);
        $this->assertSame(7, $inner->id->getValue());
    }

    /**
     * Envelope validation precedes method routing for EVERY method —
     * including unknown ones: an unknown method with an incomplete
     * envelope is malformed (-32602), not -32601 (review finding: unknown
     * modern methods bypassed envelope validation).
     */
    public function testUnknownMethodWithBrokenEnvelopeAnswersInvalidParams(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest('subscriptions/listen', [
            '_meta' => [
                MetaKeys::PROTOCOL_VERSION => '2026-07-28',
                // clientInfo and clientCapabilities missing
            ],
        ], id: 8));

        $inner = $this->lastInner($transport);
        $this->assertInstanceOf(JSONRPCError::class, $inner);
        $this->assertSame(-32602, $inner->error->code);
        $this->assertSame(400, $transport->writtenMessages[0]->httpStatusHint);
    }

    /**
     * Likewise the version check precedes -32601 for unknown methods: an
     * unsupported version is -32004 whatever the method is.
     */
    public function testUnknownMethodWithUnsupportedVersionAnswers32004(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest('subscriptions/listen', [
            '_meta' => $this->envelope('v999.0.0'),
        ], id: 9));

        $inner = $this->lastInner($transport);
        $this->assertInstanceOf(JSONRPCError::class, $inner);
        $this->assertSame(McpError::UNSUPPORTED_PROTOCOL_VERSION, $inner->error->code);
        $this->assertSame(400, $transport->writtenMessages[0]->httpStatusHint);
    }

    /**
     * Same ordering for REMOVED methods whose typed construction fails:
     * a removed method with a broken envelope is -32602, with a valid
     * envelope it is -32601.
     */
    public function testRemovedMethodWithBrokenEnvelopeAnswersInvalidParams(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest('resources/unsubscribe', [
            '_meta' => [MetaKeys::PROTOCOL_VERSION => '2026-07-28'],
        ], id: 10));

        $inner = $this->lastInner($transport);
        $this->assertInstanceOf(JSONRPCError::class, $inner);
        $this->assertSame(-32602, $inner->error->code);
    }

    /**
     * A non-discover modern request with an unsupported version answers
     * -32004 whose data.supported lists exactly the identifiers servable
     * on the per-request path (a subset of the discover advertisement, as
     * the conformance suite cross-checks).
     */
    public function testUnsupportedVersionAnswersWithModernSupportedList(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest('tools/list', [
            '_meta' => $this->envelope('v999.0.0'),
        ]));

        $inner = $this->lastInner($transport);
        $this->assertInstanceOf(JSONRPCError::class, $inner);
        $this->assertSame(McpError::UNSUPPORTED_PROTOCOL_VERSION, $inner->error->code);
        $data = (array) $inner->error->data;
        $this->assertSame(Version::MODERN_PROTOCOL_VERSIONS, $data['supported']);
        $this->assertSame('v999.0.0', $data['requested']);
    }

    /**
     * A LEGACY revision in the envelope of a non-discover request is also
     * -32004: per-request service exists only under modern identifiers;
     * legacy revisions are served through the initialize handshake.
     */
    public function testLegacyVersionInEnvelopeRejectedOnModernPath(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest('tools/list', [
            '_meta' => $this->envelope('2025-11-25'),
        ]));

        $inner = $this->lastInner($transport);
        $this->assertInstanceOf(JSONRPCError::class, $inner);
        $this->assertSame(McpError::UNSUPPORTED_PROTOCOL_VERSION, $inner->error->code);
    }

    /**
     * A PARTIAL envelope routes to modern validation (yielding the spec's
     * precise -32602 naming the missing field) — not to the legacy path.
     */
    public function testPartialEnvelopeYieldsInvalidParams(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest('tools/list', [
            '_meta' => [
                MetaKeys::CLIENT_INFO => ['name' => 'c', 'version' => '1'],
                MetaKeys::CLIENT_CAPABILITIES => [],
                // protocolVersion missing
            ],
        ]));

        $inner = $this->lastInner($transport);
        $this->assertInstanceOf(JSONRPCError::class, $inner);
        $this->assertSame(-32602, $inner->error->code);
        $this->assertStringContainsString(MetaKeys::PROTOCOL_VERSION, $inner->error->message);
        $this->assertStringContainsString('missing', $inner->error->message);
    }

    /**
     * A required envelope field explicitly set to JSON null is reported as
     * null (invalid), not as missing — isset() semantics would conflate
     * the two (WS1 re-review item d).
     */
    public function testExplicitNullEnvelopeFieldReportedAsNull(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest('tools/list', [
            '_meta' => [
                MetaKeys::PROTOCOL_VERSION => '2026-07-28',
                MetaKeys::CLIENT_INFO => null,
                MetaKeys::CLIENT_CAPABILITIES => [],
            ],
        ]));

        $inner = $this->lastInner($transport);
        $this->assertInstanceOf(JSONRPCError::class, $inner);
        $this->assertSame(-32602, $inner->error->code);
        $this->assertStringContainsString('must not be null', $inner->error->message);
        $this->assertStringNotContainsString('missing', $inner->error->message);
    }

    /**
     * A transport that declared the modern era (HTTP header carrying a
     * modern identifier) forces modern validation even when the body has
     * no _meta at all — the spec's missing-envelope -32602, not the
     * legacy "not initialized" rejection.
     */
    public function testTransportDeclaredModernValidatesEnvelope(): void
    {
        [$transport, $session] = $this->makeSession();
        $session->declareTransportModernEra(true);

        $session->processIncoming($this->makeRequest('tools/list', []));

        $inner = $this->lastInner($transport);
        $this->assertInstanceOf(JSONRPCError::class, $inner);
        $this->assertSame(-32602, $inner->error->code);
        $this->assertStringContainsString('_meta envelope', $inner->error->message);
    }

    /**
     * Legacy detection is unchanged: a bare request without the envelope on
     * an uninitialized session is still a protocol violation, NOT silently
     * served — proving era detection does not loosen the legacy gate.
     */
    public function testBareRequestBeforeInitializeStillRejected(): void
    {
        [, $session] = $this->makeSession();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('before initialization');
        $session->processIncoming($this->makeRequest('tools/list', []));
    }

    /**
     * SEP-2575 missing-capability enforcement: a modern tools/call whose
     * envelope did NOT declare sampling, hitting a tool that requires it,
     * fails with -32003 — instead of the legacy silent-fallback behavior.
     * data.requiredCapabilities is a ClientCapabilities OBJECT
     * (`{"sampling": {}}`) per the SEP-2575 final text and the draft
     * schema's canonical example — not the string array the pinned
     * conformance tool asserts (a known upstream tool bug; official text
     * wins).
     */
    public function testMissingCapabilityAnswersMinus32003(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest('tools/call', [
            'name' => 'needs-sampling',
            'arguments' => [],
            '_meta' => $this->envelope(capabilities: []), // no sampling
        ]));

        $inner = $this->lastInner($transport);
        $this->assertInstanceOf(JSONRPCError::class, $inner);
        $this->assertSame(McpError::MISSING_REQUIRED_CLIENT_CAPABILITY, $inner->error->code);
        $wire = json_decode(json_encode($transport->writtenMessages[0]), true);
        $this->assertSame(
            ['sampling' => []],
            $wire['error']['data']['requiredCapabilities'],
            'requiredCapabilities must be the schema\'s ClientCapabilities object shape'
        );
        $this->assertStringContainsString(
            '"requiredCapabilities":{"sampling":{}}',
            json_encode($transport->writtenMessages[0]),
            'The empty capability must serialize as a JSON object, not an array'
        );
    }

    /**
     * Capabilities come from THIS request's envelope: declaring sampling
     * makes the same tool see the capability...
     */
    public function testEnvelopeCapabilitiesAdoptedPerRequest(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest('tools/call', [
            'name' => 'needs-sampling',
            'arguments' => [],
            '_meta' => $this->envelope(capabilities: ['sampling' => []]),
        ]));

        $inner = $this->lastInner($transport);
        $this->assertInstanceOf(JSONRPCResponse::class, $inner);
        $wire = json_decode(json_encode($transport->writtenMessages[0]), true);
        $this->assertSame('sampling available', $wire['result']['content'][0]['text']);
    }

    /**
     * ...and the spec's "MUST NOT infer capabilities from prior requests":
     * a later request WITHOUT the declaration fails -32003 even though the
     * previous request on the same connection declared it.
     */
    public function testCapabilitiesNotInferredFromPriorRequests(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest('tools/call', [
            'name' => 'needs-sampling',
            'arguments' => [],
            '_meta' => $this->envelope(capabilities: ['sampling' => []]),
        ], id: 1));
        $session->processIncoming($this->makeRequest('tools/call', [
            'name' => 'needs-sampling',
            'arguments' => [],
            '_meta' => $this->envelope(capabilities: []),
        ], id: 2));

        $second = $this->lastInner($transport);
        $this->assertInstanceOf(JSONRPCError::class, $second);
        $this->assertSame(McpError::MISSING_REQUIRED_CLIENT_CAPABILITY, $second->error->code);
    }

    /**
     * Removed notifications on the modern path are ignored without error;
     * the legacy initialized notification still drives the legacy state
     * machine when it arrives WITHOUT modern metadata.
     */
    public function testRemovedNotificationIgnoredOnModernPath(): void
    {
        [$transport, $session] = $this->makeSession();

        $params = new NotificationParams();
        $params->_meta = new \Mcp\Types\Meta();
        $params->_meta->setField(MetaKeys::PROTOCOL_VERSION, '2026-07-28');
        $params->_meta->setField(MetaKeys::CLIENT_INFO, ['name' => 'c', 'version' => '1']);
        $params->_meta->setField(MetaKeys::CLIENT_CAPABILITIES, []);

        $session->processIncoming(new JsonRpcMessage(new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/initialized',
            params: $params
        )));

        // Ignored: no response written, and the LEGACY state machine was
        // not advanced by it (a bare legacy request still gets rejected).
        $this->assertCount(0, $transport->writtenMessages);
        $this->expectException(\RuntimeException::class);
        $session->processIncoming($this->makeRequest('tools/list', []));
    }

    /**
     * Modern error responses carry the structured HTTP status hint the
     * transport applies on the stateless path (400 for envelope/version/
     * capability errors, 404 for unknown and removed methods); legacy
     * errors never carry one.
     */
    public function testModernErrorsCarryHttpStatusHints(): void
    {
        [$transport, $session] = $this->makeSession();

        // -32602 → 400
        $session->processIncoming($this->makeRequest('tools/list', [
            '_meta' => [MetaKeys::PROTOCOL_VERSION => '2026-07-28'],
        ], id: 1));
        // -32601 (removed method) → 404
        $session->processIncoming($this->makeRequest('ping', ['_meta' => $this->envelope()], id: 2));
        // -32004 → 400
        $session->processIncoming($this->makeRequest('tools/list', [
            '_meta' => $this->envelope('v999'),
        ], id: 3));

        $this->assertSame(400, $transport->writtenMessages[0]->httpStatusHint);
        $this->assertSame(404, $transport->writtenMessages[1]->httpStatusHint);
        $this->assertSame(400, $transport->writtenMessages[2]->httpStatusHint);

        // Legacy-era error: initialize legacy, call an unknown method.
        $session->processIncoming($this->makeRequest('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'legacy', 'version' => '1.0'],
        ], id: 4));
        $session->processIncoming(new JsonRpcMessage(new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/initialized',
            params: null
        )));
        $session->processIncoming($this->makeRequest('nonexistent/method', [], id: 5));

        $legacyError = $this->lastInner($transport);
        $this->assertInstanceOf(JSONRPCError::class, $legacyError);
        $lastMessage = $transport->writtenMessages[count($transport->writtenMessages) - 1];
        $this->assertNull($lastMessage->httpStatusHint, 'Legacy errors must not carry modern status hints');
    }

    /**
     * server/discover still answers under era detection, and its result
     * survives even on a session that previously negotiated a legacy
     * revision (DiscoverResult is exempt from legacy response stripping).
     */
    public function testDiscoverAfterLegacyInitializeKeepsModernFields(): void
    {
        [$transport, $session] = $this->makeSession();

        $session->processIncoming($this->makeRequest('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'legacy', 'version' => '1.0'],
        ], id: 1));
        $session->processIncoming($this->makeRequest('server/discover', [
            '_meta' => $this->envelope('2025-06-18'),
        ], id: 2));

        $wire = json_decode(json_encode($transport->writtenMessages[1]), true);
        $this->assertSame('complete', $wire['result']['resultType']);
        $this->assertArrayHasKey('ttlMs', $wire['result']);
        $this->assertArrayHasKey('cacheScope', $wire['result']);
        $this->assertArrayHasKey('supportedVersions', $wire['result']);
    }
}

/**
 * Captures messages written by the session (Transport contract).
 */
final class EraTestTransport implements Transport
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
final class EraTestableSession extends ServerSession
{
    public function processIncoming(JsonRpcMessage $message): void
    {
        $this->handleIncomingMessage($message);
    }
}

/**
 * Wire-shaped params: serializes exactly the given array, replicating how
 * params look after JSON decoding on a real transport — including the
 * transport behavior of materializing `_meta` into a Meta instance on the
 * raw JSONRPCRequest params (see HttpServerTransport::createRequestParams),
 * which the session's era detection reads as its raw-wire fallback.
 */
final class RawEraWireParams extends RequestParams
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
        $meta = null;
        if (isset($data['_meta']) && is_array($data['_meta'])) {
            $meta = new \Mcp\Types\Meta();
            foreach ($data['_meta'] as $key => $value) {
                $meta->setField($key, $value);
            }
        }
        parent::__construct($meta);
    }

    public function jsonSerialize(): mixed
    {
        return $this->data !== [] ? $this->data : new \stdClass();
    }
}
