<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Filename: tests/Server/CrossRevisionMatrixTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\HttpServerRunner;
use Mcp\Server\InitializationOptions;
use Mcp\Server\McpServer;
use Mcp\Server\NotificationOptions;
use Mcp\Server\Transport\Http\BufferedIo;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Shared\McpHeaders;
use Mcp\Shared\Version;
use Mcp\Types\MetaKeys;
use PHPUnit\Framework\TestCase;

/**
 * The WS6 cross-revision regression matrix: every supported protocol
 * revision exercised against the real `McpServer` request surface over
 * the HTTP runner, asserting the era contracts stay correct on each —
 *
 * - handshake: each legacy revision negotiates via `initialize` and gets
 *   its own revision echoed; the modern revision has no handshake
 *   (`initialize` is a removed method, -32601/404);
 * - session header: legacy sessions mint and echo `Mcp-Session-Id` and
 *   reject session-less requests; the modern path never sees one
 *   (SEP-2567);
 * - SSE resumption: `Last-Event-ID` replay works for legacy sessions on
 *   every legacy revision; the modern path has no standalone GET stream
 *   and nothing to resume (SEP-2575);
 * - error codes: resource-not-found is `-32002` (HTTP 200) on every
 *   legacy revision and `-32602` + `data.uri` (HTTP 400) under
 *   2026-07-28 (SEP-2164);
 * - result shaping: `resultType`/`ttlMs`/`cacheScope` are stamped for
 *   2026-07-28 clients and absent for every legacy revision (SEP-2549).
 */
final class CrossRevisionMatrixTest extends TestCase
{
    private const LEGACY_REVISIONS = ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'];

    private function makeRunner(): HttpServerRunner
    {
        $mcp = new McpServer('matrix-test');
        $mcp->tool(
            'echo',
            'Echoes a value',
            fn (string $value = 'x'): string => "echo:{$value}",
            title: 'Echo',
            annotations: ['readOnlyHint' => true],
        );
        $mcp->resource(uri: 'test://known', name: 'Known', callback: fn (): string => 'known-data');

        $server = $mcp->getServer();
        $initOptions = new InitializationOptions(
            serverName: 'matrix-test',
            serverVersion: '1.0.0',
            capabilities: $server->getCapabilities(new NotificationOptions(), []),
        );
        return new HttpServerRunner(
            $server,
            $initOptions,
            ['enable_sse' => true, 'sse_mode' => 'buffered'],
            null,
            null,
            new BufferedIo()
        );
    }

    /** @param array<string, mixed> $params */
    private function post(
        string $method,
        array $params = [],
        int $id = 1,
        ?string $sessionId = null,
        bool $modern = false,
        ?string $accept = null,
    ): HttpMessage {
        $body = (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params === [] ? new \stdClass() : $params,
        ]);
        $request = new HttpMessage($body);
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', $accept ?? 'application/json');
        if ($modern) {
            $request->setHeader('MCP-Protocol-Version', '2026-07-28');
            $request->setHeader('Mcp-Method', $method);
            $name = McpHeaders::expectedNameValue($method, $params);
            if ($name !== null) {
                $request->setHeader('Mcp-Name', $name);
            }
        }
        if ($sessionId !== null) {
            $request->setHeader('Mcp-Session-Id', $sessionId);
        }
        return $request;
    }

    /** @return array<string, mixed> */
    private function envelope(): array
    {
        return [
            MetaKeys::PROTOCOL_VERSION => '2026-07-28',
            MetaKeys::CLIENT_INFO => ['name' => 'matrix-modern-client', 'version' => '1.0.0'],
            MetaKeys::CLIENT_CAPABILITIES => [],
        ];
    }

    /**
     * Run the legacy initialize handshake for a revision and return the
     * minted session id, asserting the handshake contract on the way.
     */
    private function initializeLegacySession(HttpServerRunner $runner, string $revision): string
    {
        $notification = static function (string $method): string {
            return (string) json_encode(['jsonrpc' => '2.0', 'method' => $method]);
        };

        $initResponse = $runner->handleRequest($this->post('initialize', [
            'protocolVersion' => $revision,
            'capabilities' => [],
            'clientInfo' => ['name' => "legacy-{$revision}", 'version' => '1.0'],
        ]));
        $this->assertSame(200, $initResponse->getStatusCode(), "[$revision] initialize succeeds");
        $sessionId = $initResponse->getHeader('Mcp-Session-Id');
        $this->assertNotNull($sessionId, "[$revision] the handshake mints a session id");
        $initBody = json_decode((string) $initResponse->getBody(), true);
        $this->assertSame(
            $revision,
            $initBody['result']['protocolVersion'],
            "[$revision] the requested (supported) revision is echoed, never upgraded"
        );
        $this->assertArrayNotHasKey('resultType', $initBody['result'], "[$revision] no modern fields on the handshake result");

        $initialized = new HttpMessage($notification('notifications/initialized'));
        $initialized->setMethod('POST');
        $initialized->setHeader('Content-Type', 'application/json');
        $initialized->setHeader('Accept', 'application/json');
        $initialized->setHeader('Mcp-Session-Id', $sessionId);
        $this->assertSame(202, $runner->handleRequest($initialized)->getStatusCode(), "[$revision] initialized notification accepted");

        return $sessionId;
    }

    // -------------------------------------------------------------------
    // Legacy columns
    // -------------------------------------------------------------------

    public function testHandshakeSessionHeaderAndResultShapingPerLegacyRevision(): void
    {
        foreach (self::LEGACY_REVISIONS as $revision) {
            $runner = $this->makeRunner();
            $sessionId = $this->initializeLegacySession($runner, $revision);

            // Session header: echoed on every subsequent response.
            $listResponse = $runner->handleRequest($this->post('tools/list', id: 2, sessionId: $sessionId));
            $this->assertSame(200, $listResponse->getStatusCode(), "[$revision] tools/list succeeds in-session");
            $this->assertSame($sessionId, $listResponse->getHeader('Mcp-Session-Id'), "[$revision] session id echoed");

            // Result shaping: no 2026-07-28 fields leak to any legacy era.
            $listBody = json_decode((string) $listResponse->getBody(), true);
            $this->assertSame('echo', $listBody['result']['tools'][0]['name'] ?? null, "[$revision] tool listed");
            foreach (['resultType', 'ttlMs', 'cacheScope'] as $modernField) {
                $this->assertArrayNotHasKey(
                    $modernField,
                    $listBody['result'],
                    "[$revision] '$modernField' must not appear on a legacy result"
                );
            }

            // Session header: a legacy (non-enveloped) request WITHOUT a
            // session id is rejected — sessions are the legacy contract.
            $noSession = $runner->handleRequest($this->post('tools/list', id: 3));
            $this->assertSame(400, $noSession->getStatusCode(), "[$revision] session-less legacy request rejected");
        }
    }

    /**
     * Tool annotations (spec 2025-03-26) are shaped per revision on the
     * wire: absent for a 2024-11-05 client — with every OTHER tool field
     * (here: title) surviving the strip — and present verbatim on
     * 2025-03-26 through 2025-11-25 and on the modern 2026-07-28 column.
     */
    public function testToolAnnotationsShapedPerRevision(): void
    {
        foreach (self::LEGACY_REVISIONS as $revision) {
            $runner = $this->makeRunner();
            $sessionId = $this->initializeLegacySession($runner, $revision);

            $listResponse = $runner->handleRequest($this->post('tools/list', id: 2, sessionId: $sessionId));
            $this->assertSame(200, $listResponse->getStatusCode(), "[$revision] tools/list succeeds");
            $tool = json_decode((string) $listResponse->getBody(), true)['result']['tools'][0];

            if ($revision === '2024-11-05') {
                $this->assertArrayNotHasKey(
                    'annotations',
                    $tool,
                    "[$revision] annotations predate this revision and must be stripped"
                );
                $this->assertSame('Echo', $tool['title'] ?? null, "[$revision] stripping annotations must not drop title");
            } else {
                $this->assertTrue(
                    $tool['annotations']['readOnlyHint'] ?? null,
                    "[$revision] annotations ride the wire from 2025-03-26 on"
                );
            }
        }

        // Modern column: annotations pass through unmodified.
        $runner = $this->makeRunner();
        $listResponse = $runner->handleRequest($this->post(
            'tools/list',
            ['_meta' => $this->envelope()],
            id: 2,
            modern: true
        ));
        $this->assertSame(200, $listResponse->getStatusCode(), '[modern] tools/list succeeds');
        $tool = json_decode((string) $listResponse->getBody(), true)['result']['tools'][0];
        $this->assertTrue($tool['annotations']['readOnlyHint'] ?? null, '[modern] annotations present under 2026-07-28');
    }

    public function testResourceNotFoundIsLegacyCodeOnEveryLegacyRevision(): void
    {
        foreach (self::LEGACY_REVISIONS as $revision) {
            $runner = $this->makeRunner();
            $sessionId = $this->initializeLegacySession($runner, $revision);

            $response = $runner->handleRequest($this->post(
                'resources/read',
                ['uri' => 'test://missing'],
                id: 4,
                sessionId: $sessionId
            ));
            $this->assertSame(200, $response->getStatusCode(), "[$revision] legacy errors ride HTTP 200");
            $body = json_decode((string) $response->getBody(), true);
            $this->assertSame(
                -32002,
                $body['error']['code'] ?? null,
                "[$revision] SEP-2164: legacy revisions keep the -32002 resource-not-found code"
            );
        }
    }

    public function testSseResumptionWorksOnEveryLegacyRevision(): void
    {
        foreach (self::LEGACY_REVISIONS as $revision) {
            $runner = $this->makeRunner();
            $sessionId = $this->initializeLegacySession($runner, $revision);

            // A request carrying a progressToken is served as an SSE stream
            // whose frames carry event ids (the resumption anchor).
            $sseResponse = $runner->handleRequest($this->post(
                'tools/call',
                [
                    'name' => 'echo',
                    'arguments' => ['value' => $revision],
                    '_meta' => ['progressToken' => 7],
                ],
                id: 5,
                sessionId: $sessionId,
                accept: 'application/json, text/event-stream'
            ));
            $this->assertSame(200, $sseResponse->getStatusCode(), "[$revision] progress request succeeds");
            $this->assertStringContainsString(
                'text/event-stream',
                (string) $sseResponse->getHeader('Content-Type'),
                "[$revision] progress request is SSE-framed on the legacy path"
            );
            $sseBody = (string) $sseResponse->getBody();
            $this->assertMatchesRegularExpression('/^id: .+$/m', $sseBody, "[$revision] SSE frames carry event ids");
            preg_match('/^id: (.+)$/m', $sseBody, $m);
            $firstEventId = trim($m[1]);

            // Last-Event-ID resumption: replaying from the first frame's id
            // returns the remainder of the stream — including the final
            // response — on a fresh connection.
            $resume = new HttpMessage('');
            $resume->setMethod('GET');
            $resume->setHeader('Accept', 'text/event-stream');
            $resume->setHeader('Mcp-Session-Id', $sessionId);
            $resume->setHeader('Last-Event-ID', $firstEventId);
            $resumeResponse = $runner->handleRequest($resume);
            $this->assertSame(200, $resumeResponse->getStatusCode(), "[$revision] Last-Event-ID replay accepted");
            $this->assertStringContainsString(
                'text/event-stream',
                (string) $resumeResponse->getHeader('Content-Type'),
                "[$revision] replay is an SSE stream"
            );
            $this->assertStringContainsString(
                'echo:' . $revision,
                (string) $resumeResponse->getBody(),
                "[$revision] the replayed stream carries the original response"
            );
        }
    }

    // -------------------------------------------------------------------
    // Modern column (2026-07-28)
    // -------------------------------------------------------------------

    public function testModernColumnIsStatelessWithStampedResultsAndModernErrorCodes(): void
    {
        $runner = $this->makeRunner();

        // No handshake, no session: served statelessly with stamped fields.
        $listResponse = $runner->handleRequest($this->post(
            'tools/list',
            ['_meta' => $this->envelope()],
            id: 10,
            modern: true
        ));
        $this->assertSame(200, $listResponse->getStatusCode());
        $this->assertNull($listResponse->getHeader('Mcp-Session-Id'), 'SEP-2567: no session id on the modern path');
        $listBody = json_decode((string) $listResponse->getBody(), true);
        $this->assertSame('complete', $listBody['result']['resultType']);
        $this->assertArrayHasKey('ttlMs', $listBody['result']);
        $this->assertArrayHasKey('cacheScope', $listBody['result']);

        // The handshake method does not exist on the modern path.
        $initResponse = $runner->handleRequest($this->post(
            'initialize',
            [
                'protocolVersion' => Version::LATEST_PROTOCOL_VERSION,
                'capabilities' => [],
                'clientInfo' => ['name' => 'x', 'version' => '1'],
                '_meta' => $this->envelope(),
            ],
            id: 11,
            modern: true
        ));
        $this->assertSame(404, $initResponse->getStatusCode(), 'initialize is a removed method under 2026-07-28');
        $initBody = json_decode((string) $initResponse->getBody(), true);
        $this->assertSame(-32601, $initBody['error']['code']);

        // SEP-2164: resource-not-found is -32602 with data.uri, mapped to 400.
        $readResponse = $runner->handleRequest($this->post(
            'resources/read',
            ['uri' => 'test://missing', '_meta' => $this->envelope()],
            id: 12,
            modern: true
        ));
        $this->assertSame(400, $readResponse->getStatusCode(), 'Modern -32602 rides HTTP 400');
        $readBody = json_decode((string) $readResponse->getBody(), true);
        $this->assertSame(-32602, $readBody['error']['code'], 'SEP-2164: modern resource-not-found is -32602');
        $this->assertSame('test://missing', $readBody['error']['data']['uri'] ?? null, 'SEP-2164: error.data carries the uri');

        // SEP-2575: no standalone GET stream and no Last-Event-ID resumption
        // exist on the modern path — a session-less GET is rejected.
        $get = new HttpMessage('');
        $get->setMethod('GET');
        $get->setHeader('Accept', 'text/event-stream');
        $get->setHeader('Last-Event-ID', 'stream#1');
        $getResponse = $runner->handleRequest($get);
        $this->assertSame(400, $getResponse->getStatusCode(), 'No resumable channel exists without a legacy session');
    }
}
