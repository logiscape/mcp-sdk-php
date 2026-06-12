<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Filename: tests/Server/HttpModernStreamingTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\HttpServerRunner;
use Mcp\Server\InitializationOptions;
use Mcp\Server\NotificationOptions;
use Mcp\Server\Server;
use Mcp\Server\Subscriptions\SubscriptionBusInterface;
use Mcp\Server\Transport\Http\BufferedIo;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Server\Transport\Http\StreamedHttpMessage;
use Mcp\Types\ListToolsResult;
use Mcp\Types\MetaKeys;
use Mcp\Types\RequestParams;
use Mcp\Types\Result;
use PHPUnit\Framework\TestCase;

/**
 * SEP-2575 streaming on the modern (2026-07-28) HTTP path:
 *
 * - Request-scoped SSE: a success response whose handler emitted
 *   notifications is upgraded to a buffered text/event-stream body —
 *   request-related notifications first, then the final response
 *   terminating the stream. No SSE event ids (no Last-Event-ID resumption
 *   exists on the modern path) and no Mcp-Session-Id header. Error
 *   responses and JSON-only clients keep the plain single-object JSON
 *   shape.
 *
 * - subscriptions/listen: the long-lived notification channel. The
 *   acknowledgement is the FIRST frame, every frame carries
 *   _meta["io.modelcontextprotocol/subscriptionId"] (the stringified
 *   listen request id), the filter is strict (no leaking of notification
 *   types the client did not opt into), there is never a JSON-RPC
 *   response, and servers without SSE answer -32601/404.
 */
final class HttpModernStreamingTest extends TestCase
{
    private \stdClass $holder;

    private BufferedIo $io;

    private function makeRunner(array $httpOptions = [], bool $withResources = true): HttpServerRunner
    {
        $this->holder = new \stdClass();
        $holder = $this->holder;
        $server = new Server('modern-streaming-test');
        $server->registerHandler('tools/list', function (?RequestParams $params) use ($holder): Result {
            // Emits a request-related notification before succeeding so the
            // response queue holds [notification, response].
            $holder->runner->getServerSession()->sendLogMessage(
                \Mcp\Types\LoggingLevel::INFO,
                'progress note'
            );
            return new ListToolsResult([]);
        });
        if ($withResources) {
            $server->registerHandler('resources/list', function (?RequestParams $params): Result {
                return new \Mcp\Types\ListResourcesResult([]);
            });
        }
        $initOptions = new InitializationOptions(
            serverName: 'modern-streaming-test',
            serverVersion: '1.0.0',
            capabilities: $server->getCapabilities(
                new NotificationOptions(promptsChanged: true, toolsChanged: true),
                []
            ),
        );
        $this->io = new BufferedIo();
        $runner = new HttpServerRunner($server, $initOptions, $httpOptions, null, null, $this->io);
        $holder->runner = $runner;
        return $runner;
    }

    private function envelope(): array
    {
        return [
            MetaKeys::PROTOCOL_VERSION => '2026-07-28',
            MetaKeys::CLIENT_INFO => ['name' => 'streaming-client', 'version' => '1.0.0'],
            MetaKeys::CLIENT_CAPABILITIES => [],
        ];
    }

    private function post(string $method, array $params, int $id = 1, string $accept = 'application/json, text/event-stream'): HttpMessage
    {
        $body = (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params === [] ? new \stdClass() : $params,
        ]);
        $request = new HttpMessage($body);
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', $accept);
        $request->setHeader('MCP-Protocol-Version', '2026-07-28');
        $request->setHeader('Mcp-Method', $method);
        return $request;
    }

    /**
     * Parse the JSON payloads out of an SSE body's data: lines.
     *
     * @return list<array<string, mixed>>
     */
    private function sseFrames(string $body): array
    {
        $frames = [];
        foreach (explode("\n", $body) as $line) {
            if (str_starts_with($line, 'data: ')) {
                $decoded = json_decode(substr($line, 6), true);
                if (is_array($decoded)) {
                    $frames[] = $decoded;
                }
            }
        }
        return $frames;
    }

    // -------------------------------------------------------------------
    // Request-scoped SSE
    // -------------------------------------------------------------------

    public function testHandlerNotificationsUpgradeSuccessResponseToSse(): void
    {
        $runner = $this->makeRunner(['enable_sse' => true]);

        $response = $runner->handleRequest($this->post('tools/list', ['_meta' => $this->envelope()], id: 5));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/event-stream', $response->getHeader('Content-Type') ?? '');
        $this->assertNull($response->getHeader('Mcp-Session-Id'), 'SEP-2567: no session id on modern streams');

        $body = (string) $response->getBody();
        $this->assertStringNotContainsString("id:", $body, 'No SSE event ids on the modern path (no resumption)');

        $frames = $this->sseFrames($body);
        $this->assertCount(2, $frames);
        $this->assertSame('notifications/message', $frames[0]['method'], 'Request-related notification streams first');
        $this->assertSame(5, $frames[1]['id'], 'Final response terminates the stream');
        $this->assertArrayHasKey('result', $frames[1]);
    }

    public function testJsonOnlyClientGetsPlainJsonAndNotificationsDropped(): void
    {
        $runner = $this->makeRunner(['enable_sse' => true]);

        $response = $runner->handleRequest($this->post(
            'tools/list',
            ['_meta' => $this->envelope()],
            id: 6,
            accept: 'application/json'
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('text/event-stream', $response->getHeader('Content-Type') ?? '');
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(6, $body['id']);
        $this->assertArrayHasKey('result', $body, 'Single JSON object — never an array');
        $this->assertStringNotContainsString('notifications/message', (string) $response->getBody());
    }

    public function testSseDisabledServerKeepsPlainJson(): void
    {
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->post('tools/list', ['_meta' => $this->envelope()], id: 7));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('text/event-stream', $response->getHeader('Content-Type') ?? '');
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('result', $body);
    }

    // -------------------------------------------------------------------
    // subscriptions/listen
    // -------------------------------------------------------------------

    private function listenOptions(?SubscriptionBusInterface $bus = null): array
    {
        return [
            'enable_sse' => true,
            'listen_max_ms' => 120,
            'listen_poll_ms' => 10,
            'listen_keepalive_ms' => 30,
            // A bus is a precondition for listen support: without one the
            // server answers -32601 (covered by its own test below).
            'subscription_bus' => $bus ?? new \Mcp\Server\Subscriptions\InMemorySubscriptionBus(),
        ];
    }

    private function listenRequest(array $notifications, int $id = 11): HttpMessage
    {
        return $this->post('subscriptions/listen', [
            'notifications' => $notifications === [] ? new \stdClass() : $notifications,
            '_meta' => $this->envelope(),
        ], id: $id);
    }

    public function testListenAcknowledgementIsFirstFrameWithSubscriptionId(): void
    {
        $runner = $this->makeRunner($this->listenOptions());

        $response = $runner->handleRequest($this->listenRequest(['toolsListChanged' => true], id: 42));

        $this->assertInstanceOf(StreamedHttpMessage::class, $response, 'Listen output is written through the IO adapter');
        $this->assertSame(200, $this->io->status);
        $this->assertContains('text/event-stream', $this->io->headerValues('Content-Type'));
        $this->assertSame([], $this->io->headerValues('Mcp-Session-Id'), 'SEP-2567: no session id on the listen stream');

        $frames = $this->sseFrames($this->io->buffer);
        $this->assertNotEmpty($frames);
        $ack = $frames[0];
        $this->assertSame('notifications/subscriptions/acknowledged', $ack['method'], 'Ack MUST be the first frame');
        $this->assertSame(
            '42',
            $ack['params']['_meta'][MetaKeys::SUBSCRIPTION_ID],
            'Subscription id is the stringified listen request id (on the ack too)'
        );
        $this->assertTrue(
            $ack['params']['notifications']['toolsListChanged'] ?? false,
            'Ack echoes the honored filter subset'
        );

        // No JSON-RPC response may ever appear on the stream.
        foreach ($frames as $frame) {
            $this->assertArrayNotHasKey('result', $frame);
            $this->assertArrayNotHasKey('error', $frame);
        }
    }

    public function testListenDeliversMatchingBusEventsTagged(): void
    {
        $bus = new ScriptedSubscriptionBus([
            // First poll: one wanted event and one that must be filtered.
            [
                ['method' => 'notifications/tools/list_changed', 'params' => []],
                ['method' => 'notifications/prompts/list_changed', 'params' => []],
            ],
        ]);
        $runner = $this->makeRunner($this->listenOptions($bus));

        $runner->handleRequest($this->listenRequest(['toolsListChanged' => true], id: 9));

        $frames = $this->sseFrames($this->io->buffer);
        $methods = array_column($frames, 'method');
        $this->assertContains('notifications/tools/list_changed', $methods);
        $this->assertNotContains(
            'notifications/prompts/list_changed',
            $methods,
            'SEP-2575: the server MUST NOT send notification types the client did not request'
        );

        foreach ($frames as $frame) {
            if (($frame['method'] ?? '') === 'notifications/tools/list_changed') {
                $this->assertSame('9', $frame['params']['_meta'][MetaKeys::SUBSCRIPTION_ID]);
            }
        }
    }

    public function testListenUnsupportedFilterTypesOmittedFromAck(): void
    {
        // The server's capabilities declare tools+prompts listChanged but
        // not resources — a resourcesListChanged opt-in is silently
        // omitted from the honored subset, not refused.
        $runner = $this->makeRunner($this->listenOptions());

        $runner->handleRequest($this->listenRequest([
            'toolsListChanged' => true,
            'resourcesListChanged' => true,
        ], id: 12));

        $frames = $this->sseFrames($this->io->buffer);
        $ack = $frames[0];
        $this->assertTrue($ack['params']['notifications']['toolsListChanged'] ?? false);
        $this->assertArrayNotHasKey('resourcesListChanged', (array) ($ack['params']['notifications'] ?? []));
    }

    public function testResourceSubscriptionsHonoredAndFilteredByUri(): void
    {
        // resourceSubscriptions is gated on actual deliverability (bus +
        // resources served), NOT on the legacy resources.subscribe
        // capability — and updates flow only for opted-in URIs.
        $bus = new ScriptedSubscriptionBus([
            [
                ['method' => 'notifications/resources/updated', 'params' => ['uri' => 'test://watched']],
                ['method' => 'notifications/resources/updated', 'params' => ['uri' => 'test://other']],
            ],
        ]);
        $runner = $this->makeRunner($this->listenOptions($bus));

        $runner->handleRequest($this->listenRequest([
            'resourceSubscriptions' => ['test://watched'],
        ], id: 18));

        $frames = $this->sseFrames($this->io->buffer);
        $ack = $frames[0];
        $this->assertSame(
            ['test://watched'],
            $ack['params']['notifications']['resourceSubscriptions'] ?? null,
            'A deliverable resource subscription is acknowledged'
        );

        $uris = [];
        foreach ($frames as $frame) {
            if (($frame['method'] ?? '') === 'notifications/resources/updated') {
                $uris[] = $frame['params']['uri'];
                $this->assertSame('18', $frame['params']['_meta'][MetaKeys::SUBSCRIPTION_ID]);
            }
        }
        $this->assertSame(['test://watched'], $uris, 'Only opted-in URIs may flow');
    }

    public function testResourceSubscriptionsOmittedWhenServerHasNoResources(): void
    {
        $runner = $this->makeRunner($this->listenOptions(), withResources: false);

        $runner->handleRequest($this->listenRequest([
            'toolsListChanged' => true,
            'resourceSubscriptions' => ['test://watched'],
        ], id: 19));

        $ack = $this->sseFrames($this->io->buffer)[0];
        $this->assertTrue($ack['params']['notifications']['toolsListChanged'] ?? false);
        $this->assertArrayNotHasKey(
            'resourceSubscriptions',
            (array) ($ack['params']['notifications'] ?? []),
            'A server with no resources must not acknowledge resource subscriptions'
        );
    }

    public function testListenWithoutSseAnswers404MethodNotFound(): void
    {
        // SSE disabled: the server cannot hold a stream open, so it
        // answers like any unsupported method — the conformance tool's
        // signal to skip listen checks.
        $runner = $this->makeRunner();

        $response = $runner->handleRequest($this->listenRequest(['toolsListChanged' => true], id: 13));

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(-32601, $body['error']['code']);
        $this->assertSame(13, $body['id']);
    }

    public function testListenWithoutBusAnswers404MethodNotFound(): void
    {
        // SSE enabled but no subscription bus configured: change events
        // from other requests can never reach the stream, so the server
        // must NOT acknowledge subscription types it cannot deliver —
        // listen is unsupported (-32601), exactly like the no-SSE case.
        $runner = $this->makeRunner([
            'enable_sse' => true,
            'listen_max_ms' => 120,
        ]);

        $response = $runner->handleRequest($this->listenRequest(['toolsListChanged' => true], id: 16));

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(-32601, $body['error']['code']);
        $this->assertSame(16, $body['id']);
    }

    public function testListenWithoutFilterAnswers400InvalidParams(): void
    {
        $runner = $this->makeRunner($this->listenOptions());

        $response = $runner->handleRequest($this->post('subscriptions/listen', [
            '_meta' => $this->envelope(),
        ], id: 14));

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(-32602, $body['error']['code']);
        $this->assertSame(14, $body['id']);
    }

    public function testBusCursorCapturedBeforeAckReachesTheWire(): void
    {
        // A client may trigger changes the instant it sees the ack; an
        // event published between the ack flush and a later cursor capture
        // would be silently lost. The cursor must therefore be taken
        // BEFORE anything is written.
        $bytesWrittenAtCursorCapture = null;
        $io = null;
        $bus = new class($bytesWrittenAtCursorCapture, $io) implements SubscriptionBusInterface {
            /** @param int|null $captured */
            public function __construct(private &$captured, private ?BufferedIo &$io)
            {
            }

            public function publish(string $method, array $params = []): void
            {
            }

            public function cursor(): int
            {
                $this->captured ??= strlen($this->io?->buffer ?? '');
                return 0;
            }

            public function pollSince(int $cursor): array
            {
                return ['cursor' => $cursor, 'events' => []];
            }
        };

        $runner = $this->makeRunner($this->listenOptions($bus));
        $io = $this->io;

        $runner->handleRequest($this->listenRequest(['toolsListChanged' => true], id: 17));

        $this->assertSame(
            0,
            $bytesWrittenAtCursorCapture,
            'The bus cursor must be captured before any stream bytes (the ack) are flushed'
        );
    }

    public function testListenStopsPromptlyWhenConnectionAborts(): void
    {
        $bus = new ScriptedSubscriptionBus([]);
        $runner = $this->makeRunner($this->listenOptions($bus));
        $this->io->aborted = true;

        $start = microtime(true);
        $runner->handleRequest($this->listenRequest(['toolsListChanged' => true], id: 15));
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.1, $elapsed, 'An aborted connection must end the loop before the lifetime budget');
    }
}

/**
 * Scripted SubscriptionBusInterface: returns the next pre-loaded event
 * batch on each poll — lets a single-threaded test simulate events that
 * "arrive" while the listen loop is open.
 */
final class ScriptedSubscriptionBus implements SubscriptionBusInterface
{
    private int $polls = 0;

    /** @param list<list<array{method: string, params: array<string, mixed>}>> $batches */
    public function __construct(private array $batches)
    {
    }

    public function publish(string $method, array $params = []): void
    {
    }

    public function cursor(): int
    {
        return 0;
    }

    public function pollSince(int $cursor): array
    {
        $events = $this->batches[$this->polls] ?? [];
        $this->polls++;
        return ['cursor' => $cursor + count($events), 'events' => $events];
    }
}
