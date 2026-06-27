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
 * Filename: tests/Server/HttpModernRequestTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\HttpServerRunner;
use Mcp\Server\InitializationOptions;
use Mcp\Server\NotificationOptions;
use Mcp\Server\Server;
use Mcp\Server\Transport\Http\BufferedIo;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Shared\McpError;
use Mcp\Shared\Version;
use Mcp\Types\ListToolsResult;
use Mcp\Types\MetaKeys;
use Mcp\Types\RequestParams;
use Mcp\Types\Result;
use PHPUnit\Framework\TestCase;

/**
 * Tests for general modern (2026-07-28) requests over the HTTP runner —
 * WS2's per-request era detection generalizing WS1's discover-only
 * sessionless path (SEP-2575 / SEP-2567).
 *
 * Covers: stateless service of ordinary methods with no handshake and no
 * session id, the HTTP status mapping (400 for envelope/version/capability
 * errors, 404 + -32601 for unknown and removed methods), acceptance of the
 * RC-window DRAFT-2026-v1 identifier, and era-correct coexistence of
 * modern and legacy traffic on one endpoint.
 */
final class HttpModernRequestTest extends TestCase
{
    /** Holder so tool handlers can reach the runner's live session. */
    private \stdClass $runnerHolder;

    /** @param array<string, mixed> $httpOptions */
    private function makeRunner(array $httpOptions = []): HttpServerRunner
    {
        $this->runnerHolder = new \stdClass();
        $holder = $this->runnerHolder;
        $server = new Server('http-modern-test');
        $server->registerHandler('prompts/list', function (?RequestParams $params) use ($holder): Result {
            // Emits a notification BEFORE failing, so the response queue
            // holds [notification, error] — exercising the multi-message
            // status-hint path in createJsonResponse().
            $holder->runner->getServerSession()->sendLogMessage(
                \Mcp\Types\LoggingLevel::ERROR,
                'about to fail'
            );
            throw new McpError(new \Mcp\Shared\ErrorData(
                code: McpError::MISSING_REQUIRED_CLIENT_CAPABILITY,
                message: 'Missing required client capability: sampling',
                data: ['requiredCapabilities' => (object) ['sampling' => new \stdClass()]]
            ));
        });
        $server->registerHandler('tools/list', function (?RequestParams $params): Result {
            return new ListToolsResult([]);
        });
        $server->registerHandler('resources/read', function (?RequestParams $params): Result {
            // Simulates the SEP-2164 missing-resource error a real resource
            // handler raises under 2026-07-28 (-32602 with data.uri; the
            // version-gated code selection itself is covered by
            // ModernResultAdaptationTest).
            throw new McpError(new \Mcp\Shared\ErrorData(
                code: -32602,
                message: 'Resource not found',
                data: ['uri' => $params->uri ?? null]
            ));
        });
        $server->registerHandler('tools/call', function (?RequestParams $params): Result {
            // Mirrors the SDK's raiseMissingClientCapabilityIfModern()
            // shape: a ClientCapabilities object per the draft schema.
            throw new McpError(new \Mcp\Shared\ErrorData(
                code: McpError::MISSING_REQUIRED_CLIENT_CAPABILITY,
                message: 'Missing required client capability: sampling',
                data: ['requiredCapabilities' => (object) ['sampling' => new \stdClass()]]
            ));
        });
        $initOptions = new InitializationOptions(
            serverName: 'http-modern-test',
            serverVersion: '1.0.0',
            capabilities: $server->getCapabilities(new NotificationOptions(), []),
        );
        $runner = new HttpServerRunner($server, $initOptions, $httpOptions, null, null, new BufferedIo());
        $this->runnerHolder->runner = $runner;
        $this->runnerHolder->server = $server;
        return $runner;
    }

    private function validEnvelope(string $version = '2026-07-28'): array
    {
        return [
            MetaKeys::PROTOCOL_VERSION => $version,
            MetaKeys::CLIENT_INFO => ['name' => 'modern-http-client', 'version' => '1.0.0'],
            MetaKeys::CLIENT_CAPABILITIES => [],
        ];
    }

    private function body(string $method, array $params, int $id = 1): string
    {
        return (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params === [] ? new \stdClass() : $params,
        ]);
    }

    /**
     * Build a POST carrying the SEP-2243 headers a conforming modern
     * client sends: Mcp-Method mirroring the body method, Mcp-Name on the
     * name/uri-bearing methods, and MCP-Protocol-Version (explicit
     * $headerVersion wins; otherwise mirrored from the envelope when the
     * body is modern). Tests exercising header violations adjust headers
     * after calling this.
     */
    private function postRequest(string $body, ?string $headerVersion = null, ?string $sessionId = null): HttpMessage
    {
        $request = new HttpMessage($body);
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', 'application/json, text/event-stream');

        $decoded = json_decode($body, true);
        $metaVersion = is_array($decoded)
            ? ($decoded['params']['_meta'][MetaKeys::PROTOCOL_VERSION] ?? null)
            : null;
        if ($headerVersion === null && is_string($metaVersion)) {
            $headerVersion = $metaVersion;
        }
        if ($headerVersion !== null) {
            $request->setHeader('MCP-Protocol-Version', $headerVersion);
        }
        $isModernBody = is_string($metaVersion)
            || (is_array($decoded) && ($decoded['method'] ?? null) === 'server/discover');
        if (($headerVersion !== null || $isModernBody)
            && is_array($decoded)
            && isset($decoded['method'])
            && is_string($decoded['method'])
        ) {
            $request->setHeader('Mcp-Method', $decoded['method']);
            $params = is_array($decoded['params'] ?? null) ? $decoded['params'] : null;
            $name = \Mcp\Shared\McpHeaders::expectedNameValue($decoded['method'], $params);
            if ($name !== null) {
                $request->setHeader('Mcp-Name', $name);
            }
        }

        if ($sessionId !== null) {
            $request->setHeader('Mcp-Session-Id', $sessionId);
        }
        return $request;
    }

    /**
     * An ordinary method (tools/list) with the modern envelope is served
     * statelessly: no prior initialize, no session id in, none echoed back,
     * and the modern result carries resultType + SEP-2549 cache hints.
     */
    public function testModernToolsListServedStatelessly(): void
    {
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->postRequest(
            $this->body('tools/list', ['_meta' => $this->validEnvelope()]),
            headerVersion: '2026-07-28'
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($response->getHeader('Mcp-Session-Id'), 'SEP-2567: no session id on the modern path');
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(1, $body['id']);
        $this->assertSame([], $body['result']['tools']);
        $this->assertSame('complete', $body['result']['resultType']);
        $this->assertArrayHasKey('ttlMs', $body['result'], 'SEP-2549 cache hints required on modern list results');
        $this->assertArrayHasKey('cacheScope', $body['result']);
    }

    /**
     * The pinned draft conformance tool's stateless connector sends
     * MCP-Protocol-Version: DRAFT-2026-v1 (header AND _meta) on every
     * request — the RC-window alias must be served as 2026-07-28, not
     * rejected by the legacy version-header gate.
     */
    public function testDraftIdentifierServedAsModern(): void
    {
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->postRequest(
            $this->body('tools/list', ['_meta' => $this->validEnvelope(Version::DRAFT_MODERN_PROTOCOL_VERSION)]),
            headerVersion: Version::DRAFT_MODERN_PROTOCOL_VERSION
        ));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('result', $body);
        $this->assertSame('complete', $body['result']['resultType']);
        $this->assertNull($response->getHeader('Mcp-Session-Id'));
    }

    /**
     * An Mcp-Session-Id header on a modern request is ignored, not
     * validated: an unknown id must not trigger the legacy 404
     * session-not-found path (SEP-2567: "ignore it").
     */
    public function testModernRequestIgnoresSessionId(): void
    {
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->postRequest(
            $this->body('tools/list', ['_meta' => $this->validEnvelope()]),
            headerVersion: '2026-07-28',
            sessionId: 'unknown-session-id'
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($response->getHeader('Mcp-Session-Id'));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('result', $body);
    }

    /**
     * Removed methods answer HTTP 404 + JSON-RPC -32601 with the original
     * request id (SEP-2575 "Deprecated and Removed RPCs") — including
     * `initialize` itself when it arrives with modern metadata.
     *
     * @dataProvider removedMethodProvider
     */
    public function testRemovedMethodAnswers404(string $method, array $params): void
    {
        $runner = $this->makeRunner();

        $params['_meta'] = $this->validEnvelope();
        $response = $runner->handleRequest($this->postRequest(
            $this->body($method, $params, id: 9),
            headerVersion: '2026-07-28'
        ));

        $this->assertSame(404, $response->getStatusCode(), "SEP-2575: removed method {$method} MUST be HTTP 404");
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(-32601, $body['error']['code']);
        $this->assertSame(9, $body['id'], 'Error must carry the original request id');
        $this->assertNull($response->getHeader('Mcp-Session-Id'));
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function removedMethodProvider(): array
    {
        return [
            'initialize' => ['initialize', []],
            'ping' => ['ping', []],
            'logging/setLevel' => ['logging/setLevel', []],
            'resources/subscribe' => ['resources/subscribe', []],
            'resources/unsubscribe' => ['resources/unsubscribe', []],
        ];
    }

    /**
     * Unknown methods on the modern path answer HTTP 404 + -32601 too.
     */
    public function testUnknownMethodAnswers404(): void
    {
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->postRequest(
            $this->body('totally/unknown', ['_meta' => $this->validEnvelope()], id: 3),
            headerVersion: '2026-07-28'
        ));

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(-32601, $body['error']['code']);
        $this->assertSame(3, $body['id']);
    }

    /**
     * A modern-header request whose body has NO _meta envelope is malformed:
     * HTTP 400 + -32602 (the header alone selects the modern era, so the
     * legacy "session id required" rejection must not fire).
     */
    public function testMissingEnvelopeWithModernHeaderAnswers400(): void
    {
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->postRequest(
            $this->body('tools/list', []),
            headerVersion: Version::DRAFT_MODERN_PROTOCOL_VERSION
        ));

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(-32602, $body['error']['code']);
        $this->assertSame(1, $body['id'], 'Envelope errors carry the original request id');
    }

    /**
     * An unsupported version on an ordinary modern request answers HTTP 400
     * + -32022 with data.supported listing the per-request-servable
     * identifiers — a subset of the discover advertisement, as the
     * conformance suite cross-checks.
     */
    public function testUnsupportedVersionAnswers400(): void
    {
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->postRequest(
            $this->body('tools/list', ['_meta' => $this->validEnvelope('v999.0.0')], id: 5),
            headerVersion: 'v999.0.0'
        ));

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(-32022, $body['error']['code']);
        $this->assertSame(5, $body['id']);
        $this->assertSame(Version::MODERN_PROTOCOL_VERSIONS, $body['error']['data']['supported']);
        $this->assertSame('v999.0.0', $body['error']['data']['requested']);
    }

    /**
     * MissingRequiredClientCapabilityError (-32021) maps to HTTP 400 on the
     * modern path (SEP-2575), with data.requiredCapabilities intact.
     */
    public function testMissingCapabilityAnswers400(): void
    {
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->postRequest(
            $this->body('tools/call', [
                'name' => 'needs-sampling',
                'arguments' => new \stdClass(),
                '_meta' => $this->validEnvelope(),
            ], id: 6),
            headerVersion: '2026-07-28'
        ));

        $this->assertSame(400, $response->getStatusCode(), 'SEP-2575: -32021 MUST be HTTP 400');
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(-32021, $body['error']['code']);
        // ClientCapabilities object shape per the draft schema's canonical
        // example (the pinned conformance tool's string-array expectation
        // is a known upstream tool bug).
        $this->assertSame(['sampling' => []], $body['error']['data']['requiredCapabilities']);
        $this->assertSame(6, $body['id']);
    }

    /**
     * SEP-2164 over HTTP: a missing resource on the modern path is -32602
     * with data.uri, delivered as HTTP 400 per the stateless status
     * mapping.
     */
    public function testResourceNotFoundAnswers400WithUri(): void
    {
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->postRequest(
            $this->body('resources/read', [
                'uri' => 'missing://nope',
                '_meta' => $this->validEnvelope(),
            ], id: 8),
            headerVersion: '2026-07-28'
        ));

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(-32602, $body['error']['code']);
        $this->assertSame('missing://nope', $body['error']['data']['uri']);
        $this->assertSame(8, $body['id']);
    }

    /**
     * The SEP-2575 status survives a handler that emits a notification
     * before failing — AND the response body stays a single JSON object,
     * the only valid shape for the modern JSON response mode (review
     * findings: queued notifications first discarded the status, then the
     * interim fix returned an invalid [notification, error] array). The
     * notification itself is dropped: error responses are always plain
     * JSON (an SSE stream would commit to status 200), so notifications
     * preceding an error have no carrier even now that request-scoped SSE
     * exists for success responses.
     */
    public function testNotificationBeforeErrorKeepsModernStatusAndSingleObjectBody(): void
    {
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->postRequest(
            $this->body('prompts/list', ['_meta' => $this->validEnvelope()], id: 12),
            headerVersion: '2026-07-28'
        ));

        $this->assertSame(400, $response->getStatusCode(), 'Hint must survive an interleaved notification');
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('error', $body, 'Body must be the single JSON-RPC error object, not an array');
        $this->assertArrayHasKey('jsonrpc', $body);
        $this->assertSame(-32021, $body['error']['code']);
        $this->assertSame(12, $body['id']);
        $this->assertStringNotContainsString(
            'notifications/message',
            (string) $response->getBody(),
            'Interleaved notifications are dropped on the modern JSON path (carrier arrives with WS3)'
        );
    }

    /**
     * Envelope validation precedes -32601 for unknown methods over HTTP
     * too: unknown method + incomplete envelope is 400/-32602, not
     * 404/-32601.
     */
    public function testUnknownMethodWithBrokenEnvelopeAnswers400(): void
    {
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->postRequest(
            $this->body('totally/unknown', [
                '_meta' => [MetaKeys::PROTOCOL_VERSION => '2026-07-28'],
            ], id: 13),
            headerVersion: '2026-07-28'
        ));

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(-32602, $body['error']['code']);
        $this->assertSame(13, $body['id']);
    }

    /**
     * Modern ERROR responses are plain JSON even on SSE-enabled servers —
     * the SEP-2575 statuses can only ride a JSON response (request-scoped
     * SSE upgrades success responses only).
     */
    public function testModernRequestOnSseEnabledServerReturnsPlainJson(): void
    {
        $runner = $this->makeRunner(['enable_sse' => true]);

        $response = $runner->handleRequest($this->postRequest(
            $this->body('ping', ['_meta' => $this->validEnvelope()], id: 2),
            headerVersion: '2026-07-28'
        ));

        $this->assertSame(404, $response->getStatusCode(), 'Status mapping must hold on SSE-enabled servers');
        $contentType = $response->getHeader('Content-Type') ?? '';
        $this->assertStringNotContainsString('text/event-stream', $contentType);
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(-32601, $body['error']['code']);
    }

    /**
     * Dual-era coexistence on ONE endpoint: a legacy client completes the
     * initialize handshake and keeps its session while modern stateless
     * requests interleave — each era behaving correctly, neither leaking
     * into the other (the WS2 four-way matrix, HTTP server half).
     */
    public function testMixedEraTrafficOnOneRunner(): void
    {
        $runner = $this->makeRunner();

        // 1) Legacy client initializes (mints a session id).
        $initResponse = $runner->handleRequest($this->postRequest((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'legacy-client', 'version' => '1.0'],
            ],
        ])));
        $this->assertSame(200, $initResponse->getStatusCode());
        $legacySessionId = $initResponse->getHeader('Mcp-Session-Id');
        $this->assertNotNull($legacySessionId);
        $initBody = json_decode((string) $initResponse->getBody(), true);
        $this->assertSame('2025-11-25', $initBody['result']['protocolVersion']);

        // Legacy client completes the handshake.
        $notifyRequest = $this->postRequest((string) json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]), sessionId: $legacySessionId);
        $notifyResponse = $runner->handleRequest($notifyRequest);
        $this->assertSame(202, $notifyResponse->getStatusCode());

        // 2) A modern client interleaves a stateless request.
        $modernResponse = $runner->handleRequest($this->postRequest(
            $this->body('tools/list', ['_meta' => $this->validEnvelope()], id: 10),
            headerVersion: '2026-07-28'
        ));
        $this->assertSame(200, $modernResponse->getStatusCode());
        $this->assertNull($modernResponse->getHeader('Mcp-Session-Id'));
        $modernBody = json_decode((string) $modernResponse->getBody(), true);
        $this->assertSame('complete', $modernBody['result']['resultType'], 'Modern result must be stamped');
        $this->assertArrayHasKey('ttlMs', $modernBody['result']);

        // 3) The legacy session still works afterwards, era-correct: same
        // session id honored, and NO modern fields stamped on its results.
        $legacyResponse = $runner->handleRequest($this->postRequest(
            $this->body('tools/list', [], id: 11),
            sessionId: $legacySessionId
        ));
        $this->assertSame(200, $legacyResponse->getStatusCode());
        $this->assertSame($legacySessionId, $legacyResponse->getHeader('Mcp-Session-Id'));
        $legacyBody = json_decode((string) $legacyResponse->getBody(), true);
        $this->assertArrayHasKey('result', $legacyBody);
        $this->assertArrayNotHasKey('resultType', $legacyBody['result'], 'Modern fields must not leak to legacy clients');
        $this->assertArrayNotHasKey('ttlMs', $legacyBody['result']);
        $this->assertArrayNotHasKey('cacheScope', $legacyBody['result']);
    }

    /**
     * The reverse interleaving of testMixedEraTrafficOnOneRunner: a MODERN
     * request arrives FIRST on a fresh runner, then a legacy client
     * initializes. The ephemeral modern session must not outlive its
     * request (post-commit review finding): the runner used to keep it in
     * $this->serverSession, so a following legacy initialize — which
     * carries no session id and finds no saved state — was served by the
     * stale modern-declared instance (still holding the modern request's
     * headers and authenticated principal) and rejected -32602 for
     * lacking the _meta envelope.
     */
    public function testLegacyInitializeAfterModernRequestOnOneRunner(): void
    {
        $runner = $this->makeRunner();

        // 1) A modern stateless request is the runner's FIRST traffic.
        $modernResponse = $runner->handleRequest($this->postRequest(
            $this->body('tools/list', ['_meta' => $this->validEnvelope()], id: 1),
            headerVersion: '2026-07-28'
        ));
        $this->assertSame(200, $modernResponse->getStatusCode());

        // The ephemeral modern session is gone once its request completed.
        $this->assertNull(
            $runner->getServerSession(),
            'Modern sessions are per-request; none may linger on the runner'
        );
        $this->assertNull(
            $this->runnerHolder->server->getSession(),
            'The Server facade must not retain the modern session between requests'
        );

        // 2) A legacy client now initializes on the same runner. It must be
        // served by a fresh legacy session, not misrouted to modern
        // envelope validation by leaked era state.
        $initResponse = $runner->handleRequest($this->postRequest((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'legacy-client', 'version' => '1.0'],
            ],
        ])));
        $this->assertSame(200, $initResponse->getStatusCode());
        $initBody = json_decode((string) $initResponse->getBody(), true);
        $this->assertArrayHasKey(
            'result',
            $initBody,
            'Legacy initialize after a modern request must not hit modern _meta validation'
        );
        $this->assertSame('2025-11-25', $initBody['result']['protocolVersion']);
        $legacySessionId = $initResponse->getHeader('Mcp-Session-Id');
        $this->assertNotNull($legacySessionId);

        // 3) The legacy session works era-correct afterwards.
        $notifyResponse = $runner->handleRequest($this->postRequest((string) json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]), sessionId: $legacySessionId));
        $this->assertSame(202, $notifyResponse->getStatusCode());

        $legacyResponse = $runner->handleRequest($this->postRequest(
            $this->body('tools/list', [], id: 3),
            sessionId: $legacySessionId
        ));
        $this->assertSame(200, $legacyResponse->getStatusCode());
        $legacyBody = json_decode((string) $legacyResponse->getBody(), true);
        $this->assertArrayHasKey('result', $legacyBody);
        $this->assertArrayNotHasKey(
            'resultType',
            $legacyBody['result'],
            'Modern fields must not leak to the legacy session'
        );
    }

    /**
     * The legacy 400 "Session ID required" rejection still protects
     * legacy-era requests: a bare POST (no envelope, no modern header, no
     * session id) is NOT silently served by era detection.
     */
    public function testBareLegacyRequestStillRequiresSession(): void
    {
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->postRequest($this->body('tools/list', [])));

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Session ID required', $body['error'] ?? null);
    }

    /**
     * The legacy version-header gate is intact for legacy traffic: a
     * genuinely unknown header version without modern signals is still the
     * transport-level 400/-32600 rejection.
     */
    public function testLegacyHeaderGateStillRejectsUnknownVersions(): void
    {
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->postRequest(
            $this->body('tools/list', []),
            headerVersion: '1999-01-01'
        ));

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(-32600, $body['error']['code']);
    }
}
