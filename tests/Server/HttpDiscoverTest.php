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
 * Filename: tests/Server/HttpDiscoverTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\HttpServerRunner;
use Mcp\Server\InitializationOptions;
use Mcp\Server\NotificationOptions;
use Mcp\Server\Server;
use Mcp\Server\Transport\Http\BufferedIo;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Server\Transport\Http\HttpSession;
use Mcp\Server\Transport\Http\InMemorySessionStore;
use Mcp\Types\ListToolsResult;
use Mcp\Types\MetaKeys;
use Mcp\Types\RequestParams;
use Mcp\Types\Result;
use PHPUnit\Framework\TestCase;

/**
 * Tests for server/discover over the HTTP runner (SEP-2575 / SEP-2567).
 *
 * The HTTP path adds the sessionless requirements on top of the protocol
 * behavior: a discover POST needs no prior initialize and no Mcp-Session-Id,
 * any session id it does carry is ignored rather than validated, and the
 * response must NOT mint or echo a session id (SEP-2567).
 */
final class HttpDiscoverTest extends TestCase
{
    /** @param array<string, mixed> $httpOptions */
    private function makeRunner(?RecordingSessionStore $store = null, array $httpOptions = []): HttpServerRunner
    {
        $server = new Server('http-discover-test');
        $server->registerHandler('tools/list', function (?RequestParams $params): Result {
            return new ListToolsResult([]);
        });
        $initOptions = new InitializationOptions(
            serverName: 'http-discover-test',
            serverVersion: '2.0.0',
            capabilities: $server->getCapabilities(new NotificationOptions(), []),
        );
        return new HttpServerRunner($server, $initOptions, $httpOptions, null, $store, new BufferedIo());
    }

    private function discoverBody(?array $meta, int $id = 1): string
    {
        $params = $meta === null ? new \stdClass() : ['_meta' => $meta];
        return (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'server/discover',
            'params' => $params,
        ]);
    }

    private function validEnvelope(): array
    {
        return [
            MetaKeys::PROTOCOL_VERSION => '2026-07-28',
            MetaKeys::CLIENT_INFO => ['name' => 'http-test-client', 'version' => '1.0.0'],
            MetaKeys::CLIENT_CAPABILITIES => [],
        ];
    }

    private function postRequest(string $body, ?string $sessionId = null): HttpMessage
    {
        $request = new HttpMessage($body);
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', 'application/json, text/event-stream');
        if ($sessionId !== null) {
            $request->setHeader('Mcp-Session-Id', $sessionId);
        }
        return $request;
    }

    /**
     * A discover POST without prior initialize and without a session id is
     * answered 200 with the full DiscoverResult — and the response carries
     * NO Mcp-Session-Id header (the sessionless lifecycle never mints one).
     */
    public function testDiscoverOverHttpWithoutSession(): void
    {
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->postRequest($this->discoverBody($this->validEnvelope())));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(
            $response->getHeader('Mcp-Session-Id'),
            'SEP-2567: no session id may be minted/echoed on the sessionless path'
        );

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(1, $body['id']);
        $this->assertArrayHasKey('result', $body);
        $result = $body['result'];
        $this->assertNotEmpty($result['supportedVersions']);
        $this->assertContains('2026-07-28', $result['supportedVersions']);
        $this->assertSame('http-discover-test', $result['serverInfo']['name']);
        $this->assertSame('2.0.0', $result['serverInfo']['version']);
        $this->assertArrayHasKey('tools', $result['capabilities']);
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame(0, $result['ttlMs']);
        $this->assertSame('public', $result['cacheScope']);
    }

    /**
     * An Mcp-Session-Id header on a discover request is IGNORED, not
     * validated: an unknown/stale id must not produce the legacy 404
     * "session not found" rejection (SEP-2567: "ignore it").
     */
    public function testDiscoverIgnoresUnknownSessionId(): void
    {
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->postRequest(
            $this->discoverBody($this->validEnvelope()),
            sessionId: 'stale-session-id-from-another-era'
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($response->getHeader('Mcp-Session-Id'));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('result', $body);
        $this->assertNotEmpty($body['result']['supportedVersions']);
    }

    /**
     * Envelope validation runs on the HTTP path too: a discover without the
     * required _meta fields is answered 400 Bad Request with JSON-RPC
     * -32602 (SEP-2575: a malformed envelope is INVALID_PARAMS + HTTP 400).
     */
    public function testDiscoverEnvelopeErrorOverHttp(): void
    {
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->postRequest($this->discoverBody(null)));

        $this->assertSame(400, $response->getStatusCode(), 'SEP-2575: malformed envelope MUST be HTTP 400');
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(-32602, $body['error']['code']);
        $this->assertNull($response->getHeader('Mcp-Session-Id'));
    }

    /**
     * The ephemeral processing context behind a discover request is
     * DELETED from the session store, not persisted: its id is never
     * disclosed to the client, so a persisted entry would just accumulate
     * as unreachable garbage in file-backed stores.
     */
    public function testDiscoverLeavesNoStoredSession(): void
    {
        $store = new RecordingSessionStore();
        $runner = $this->makeRunner($store);

        $response = $runner->handleRequest($this->postRequest($this->discoverBody($this->validEnvelope())));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $store->storedSessionIds(), 'No session may remain in the store after discover');
        $this->assertNotEmpty($store->deletedIds, 'The ephemeral discover session must be deleted, not just expired');
    }

    /**
     * A discover POST that fails at the transport level (here: unsupported
     * media type) takes the runner's early-exit path — the ephemeral
     * session must still be discarded, not left behind in the store.
     */
    public function testErroredDiscoverLeavesNoStoredSession(): void
    {
        $store = new RecordingSessionStore();
        $runner = $this->makeRunner($store);

        $request = new HttpMessage($this->discoverBody($this->validEnvelope()));
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'text/plain');
        $request->setHeader('Accept', 'application/json');

        $response = $runner->handleRequest($request);

        $this->assertSame(415, $response->getStatusCode());
        $this->assertSame([], $store->storedSessionIds(), 'Errored discover must not leave a stored session');
    }

    /**
     * An unsupported protocol version on a discover request (header and
     * _meta agreeing) is answered with the SEP-2575
     * UnsupportedProtocolVersionError: HTTP 400 Bad Request carrying
     * -32004, the ORIGINAL request id, and data.supported/data.requested —
     * not the transport's generic -32600/id:null rejection.
     */
    public function testDiscoverUnsupportedVersionOverHttp(): void
    {
        $runner = $this->makeRunner();

        $envelope = $this->validEnvelope();
        $envelope[MetaKeys::PROTOCOL_VERSION] = 'v999.0.0';
        $request = $this->postRequest($this->discoverBody($envelope, id: 42));
        $request->setHeader('MCP-Protocol-Version', 'v999.0.0');

        $response = $runner->handleRequest($request);

        $this->assertSame(400, $response->getStatusCode(), 'SEP-2575: UnsupportedProtocolVersionError MUST be HTTP 400');
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame(-32004, $body['error']['code']);
        $this->assertSame(42, $body['id'], 'Error must carry the original request id, not null');
        $this->assertSame('v999.0.0', $body['error']['data']['requested']);
        $this->assertNotEmpty($body['error']['data']['supported']);
        $this->assertContains('2026-07-28', $body['error']['data']['supported']);
        $this->assertNull($response->getHeader('Mcp-Session-Id'));
    }

    /**
     * Discover responses are never SSE-framed: even on an SSE-enabled
     * server with a client accepting text/event-stream, the result comes
     * back as a plain JSON document. The result is single and
     * self-contained, and only a JSON response can carry the SEP-2575
     * error statuses.
     */
    public function testDiscoverOverSseEnabledServerReturnsPlainJson(): void
    {
        $runner = $this->makeRunner(httpOptions: ['enable_sse' => true]);

        // postRequest() already sends Accept: application/json, text/event-stream
        $response = $runner->handleRequest($this->postRequest($this->discoverBody($this->validEnvelope())));

        $this->assertSame(200, $response->getStatusCode());
        $contentType = $response->getHeader('Content-Type') ?? '';
        $this->assertStringNotContainsString('text/event-stream', $contentType, 'Discover must not be SSE-framed');

        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body, 'Discover body must be plain JSON, not SSE frames');
        $this->assertNotEmpty($body['result']['supportedVersions']);
        $this->assertNull($response->getHeader('Mcp-Session-Id'));
    }

    /**
     * The SEP-2575 HTTP 400 mandate holds on SSE-enabled servers too: an
     * unsupported-version discover gets 400 + JSON-RPC -32004, not an SSE
     * stream committed to status 200.
     */
    public function testDiscoverUnsupportedVersionOverSseEnabledServer(): void
    {
        $runner = $this->makeRunner(httpOptions: ['enable_sse' => true]);

        $envelope = $this->validEnvelope();
        $envelope[MetaKeys::PROTOCOL_VERSION] = 'v999.0.0';
        $request = $this->postRequest($this->discoverBody($envelope, id: 7));
        $request->setHeader('MCP-Protocol-Version', 'v999.0.0');

        $response = $runner->handleRequest($request);

        $this->assertSame(400, $response->getStatusCode(), 'SEP-2575: 400 must apply on SSE-enabled servers too');
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame(-32004, $body['error']['code']);
        $this->assertSame(7, $body['id']);
    }

    /**
     * The legacy HTTP flow is untouched: an initialize POST still mints and
     * echoes a session id exactly as before (WS6 promise).
     */
    public function testLegacyInitializeStillMintsSessionId(): void
    {
        $runner = $this->makeRunner();

        $initBody = (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'legacy-client', 'version' => '1.0'],
            ],
        ]);
        $response = $runner->handleRequest($this->postRequest($initBody));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull(
            $response->getHeader('Mcp-Session-Id'),
            'Legacy initialize must keep minting a session id'
        );
    }
}

/**
 * Session store that records deletions so tests can assert ephemeral
 * discover sessions are removed rather than persisted.
 */
final class RecordingSessionStore extends InMemorySessionStore
{
    /** @var array<string, true> */
    private array $stored = [];

    /** @var string[] */
    public array $deletedIds = [];

    public function save(HttpSession $session): void
    {
        $this->stored[$session->getId()] = true;
        parent::save($session);
    }

    public function delete(string $sessionId): void
    {
        unset($this->stored[$sessionId]);
        $this->deletedIds[] = $sessionId;
        parent::delete($sessionId);
    }

    /** @return string[] */
    public function storedSessionIds(): array
    {
        return array_keys($this->stored);
    }
}
