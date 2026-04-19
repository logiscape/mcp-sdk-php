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
 * @link       https://github.com/logiscape/sdk-php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\HttpServerRunner;
use Mcp\Server\InitializationOptions;
use Mcp\Server\NotificationOptions;
use Mcp\Server\Server;
use Mcp\Server\Transport\Http\BufferedIo;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Server\Transport\Http\StandardPhpAdapter;
use Mcp\Server\Transport\Http\StreamedHttpMessage;
use Mcp\Server\Transport\HttpServerTransport;
use PHPUnit\Framework\TestCase;

/**
 * Runner-level HttpIoInterface injection — the capability the adapter
 * refactor is intended to deliver.
 *
 * Before this change, `HttpServerRunner::handleRequest()` + `sendResponse()`
 * wrote directly to the PHP SAPI (`http_response_code`, `header`, `echo`,
 * `flush`), so the only way to exercise streaming behaviour end-to-end was
 * to bypass the runner and call `HttpServerTransport` directly. These tests
 * pin down the invariant that a captured-bytes adapter (BufferedIo) can
 * drive the whole runner lifecycle without touching php://output.
 */
final class HttpIoInjectionTest extends TestCase
{
    private function postRequest(string $body): HttpMessage
    {
        $request = new HttpMessage($body);
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', 'application/json, text/event-stream');
        return $request;
    }

    private function initBody(int $id = 1): string
    {
        return (string) \json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
            ],
        ]);
    }

    private function makeRunner(BufferedIo $io): HttpServerRunner
    {
        $server = new Server('io-injection-test-server');
        $initOptions = new InitializationOptions(
            serverName: 'io-injection-test-server',
            serverVersion: '1.0',
            capabilities: $server->getCapabilities(new NotificationOptions(), []),
        );
        return new HttpServerRunner(
            $server,
            $initOptions,
            [],
            null,
            null,
            $io,
        );
    }

    /**
     * Non-streaming JSON response path: status, headers, and body all
     * flow through the injected HttpIoInterface, with zero direct SAPI
     * side effects.
     *
     * Pinning this down means an embedder (Symfony / Slim / FrankenPHP /
     * RoadRunner) can drive the runner and harvest the bytes from the
     * adapter without running inside a cPanel/Apache SAPI context.
     */
    public function testRunnerRoutesStatusHeadersAndBodyThroughInjectedIo(): void
    {
        $io = new BufferedIo();
        $runner = $this->makeRunner($io);

        $response = $runner->handleRequest($this->postRequest($this->initBody(1)));
        $runner->sendResponse($response);

        $this->assertSame(200, $io->status, 'status must be pushed through the adapter, not http_response_code()');
        $this->assertNotEmpty($io->headers, 'headers must be pushed through the adapter');
        $contentTypes = $io->headerValues('Content-Type');
        $this->assertNotEmpty($contentTypes);
        $this->assertSame('application/json', $contentTypes[0]);
        $this->assertNotEmpty($io->headerValues('Mcp-Session-Id'));
        $this->assertNotSame('', $io->buffer, 'body must be pushed through the adapter, not echo');
        $this->assertStringContainsString('"jsonrpc":"2.0"', $io->buffer);
        $this->assertStringContainsString('"id":1', $io->buffer);
        $this->assertSame(1, $io->writes, 'non-streaming path should emit the whole body in one write');
    }

    /**
     * StreamedHttpMessage is the typed replacement for the transitional
     * `X-Mcp-Already-Emitted: 1` header. `sendResponse()` must recognise
     * the type alone and skip body emission — integrators can do the
     * same without sniffing headers.
     */
    public function testStreamedHttpMessageSkipsBodyEmission(): void
    {
        $io = new BufferedIo();
        $runner = $this->makeRunner($io);

        $streamed = new StreamedHttpMessage('should-not-appear-on-the-wire');
        $streamed->setStatusCode(200);
        $streamed->setHeader('Content-Type', 'text/event-stream');
        $runner->sendResponse($streamed);

        $this->assertNull($io->status, 'no status should be pushed for an already-emitted response');
        $this->assertSame([], $io->headers, 'no headers should be pushed for an already-emitted response');
        $this->assertSame('', $io->buffer, 'body must not be re-emitted for a StreamedHttpMessage');
        $this->assertSame(0, $io->writes);
        $this->assertSame(0, $io->flushes);
    }

    /**
     * Backward compatibility: the pre-refactor sentinel header is still
     * honored for one release so anything external that inspected it
     * keeps working. Same behavioural contract as the typed path above.
     */
    public function testLegacyAlreadyEmittedHeaderSkipsBodyEmission(): void
    {
        $io = new BufferedIo();
        $runner = $this->makeRunner($io);

        $legacy = new HttpMessage('legacy-body');
        $legacy->setStatusCode(200);
        $legacy->setHeader('Content-Type', 'text/event-stream');
        $legacy->setHeader('X-Mcp-Already-Emitted', '1');
        $runner->sendResponse($legacy);

        $this->assertNull($io->status);
        $this->assertSame('', $io->buffer);
        $this->assertSame(0, $io->writes);
    }

    /**
     * SSE responses (non-streamed, e.g. the buffered emitSseResponse path)
     * must drain output buffers, disable abort kills, and flush through
     * the adapter. These are the three side effects NativePhpIo wraps and
     * that the runner relied on bare PHP builtins for before the
     * refactor.
     */
    public function testNonStreamedSseResponseExercisesDrainDisableAndFlush(): void
    {
        $io = new BufferedIo();
        $runner = $this->makeRunner($io);

        $response = new HttpMessage("id: s:0\nretry: 3000\ndata: \n\n");
        $response->setStatusCode(200);
        $response->setHeader('Content-Type', 'text/event-stream');
        $response->setHeader('Cache-Control', 'no-cache, no-transform');
        $runner->sendResponse($response);

        $this->assertSame(200, $io->status);
        $this->assertSame(1, $io->outputBufferDrains, 'SSE must drain output buffers once');
        $this->assertTrue($io->abortKillsDisabled, 'SSE must disable abort kills');
        $this->assertSame(1, $io->flushes, 'SSE must flush after writing the body');
        $this->assertStringContainsString('id: s:0', $io->buffer);
    }

    /**
     * JSON (non-SSE) responses must NOT touch drain/disable/flush — those
     * are SSE-only concerns and invoking them on every response would
     * churn framework-owned output buffers for no reason.
     */
    public function testJsonResponseDoesNotTouchSseOnlyAdapterMethods(): void
    {
        $io = new BufferedIo();
        $runner = $this->makeRunner($io);

        $response = $runner->handleRequest($this->postRequest($this->initBody(2)));
        $runner->sendResponse($response);

        $this->assertSame(0, $io->outputBufferDrains);
        $this->assertFalse($io->abortKillsDisabled);
        $this->assertSame(0, $io->flushes);
    }

    /**
     * Passing an HttpIoInterface directly to beginStreamingSseOutput() must
     * fully override $this->io for the stream. SSE status and headers
     * (Content-Type, Mcp-Session-Id, etc.) must land on the passed adapter,
     * and the fatal-finalization shutdown guard must register on it too —
     * otherwise a direct transport embedder that constructs the transport
     * with one IO and streams with another would see framed body bytes
     * arrive on the per-request adapter while the response headers and
     * the fatal-safety-net went to the wrong adapter.
     */
    public function testDirectHttpIoInterfaceSinkFullyOwnsStatusHeadersAndShutdown(): void
    {
        $defaultIo = new BufferedIo();
        $transport = new HttpServerTransport(
            ['enable_sse' => true, 'sse_mode' => 'streaming'],
            null,
            null,
            $defaultIo,
        );
        $transport->start();

        // Open an initialized session so follow-up POSTs pass the spec
        // §5.8.2 strict-session gate, matching HttpSseStreamingTest.
        $initBody = $this->initBody(1);
        $initReq = $this->postRequest($initBody);
        $transport->handleRequest($initReq);
        $session = $transport->getLastUsedSession();
        $this->assertNotNull($session);
        $session->setMetadata('mcp_server_session', [
            'initializationState' => 3,
        ]);
        $transport->saveSession($session);

        // Clear capture from the init response so assertions only see
        // what the streaming path writes.
        $defaultIo->buffer = '';
        $defaultIo->writes = 0;
        $defaultIo->headers = [];
        $defaultIo->status = null;
        $defaultIo->shutdownHandlers = [];

        $toolsCall = $this->postRequest((string) \json_encode([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'longtool',
                'arguments' => (object) [],
                '_meta' => ['progressToken' => 'p-5'],
            ],
        ]));
        $toolsCall->setHeader('Mcp-Session-Id', $session->getId());
        $transport->handleRequest($toolsCall);

        $perRequestIo = new BufferedIo();
        $transport->beginStreamingSseOutput($session, $perRequestIo);

        // Status and headers land on the per-request adapter.
        $this->assertSame(200, $perRequestIo->status);
        $contentType = $perRequestIo->headerValues('Content-Type');
        $this->assertSame(['text/event-stream'], $contentType);
        $this->assertNotEmpty($perRequestIo->headerValues('Mcp-Session-Id'));
        $this->assertNotEmpty($perRequestIo->headerValues('Cache-Control'));

        // Priming frame lands on the per-request adapter.
        $this->assertStringContainsString('id: ', $perRequestIo->buffer);
        $this->assertGreaterThanOrEqual(1, $perRequestIo->writes);

        // Shutdown guard registered on the per-request adapter — not the
        // transport's default $this->io. This is what keeps the fatal-
        // safety-net wired correctly when an embedder passes a per-
        // request adapter.
        $this->assertCount(1, $perRequestIo->shutdownHandlers);
        $this->assertSame([], $defaultIo->shutdownHandlers);

        // Default adapter sees no streaming side effects at all — no
        // header leak, no body leak, no shutdown leak.
        $this->assertNull($defaultIo->status);
        $this->assertSame('', $defaultIo->buffer);
        $this->assertSame([], $defaultIo->headers);
    }

    /**
     * Long-running hosts (FrankenPHP / RoadRunner) and any transport
     * reused across requests must register a fresh shutdown guard for
     * every stream, each on the stream's own resolved IO. Before this
     * was fixed the streamingShutdownGuarded flag latched true after
     * the first stream, so subsequent per-request HttpIoInterface sinks
     * received SSE headers/body but no fatal-safety-net — a silent
     * correctness gap for the documented per-request adapter model.
     */
    public function testSecondStreamRegistersFreshShutdownGuardOnOwnIo(): void
    {
        $defaultIo = new BufferedIo();
        $transport = new HttpServerTransport(
            ['enable_sse' => true, 'sse_mode' => 'streaming'],
            null,
            null,
            $defaultIo,
        );
        $transport->start();

        $transport->handleRequest($this->postRequest($this->initBody(1)));
        $session = $transport->getLastUsedSession();
        $this->assertNotNull($session);
        $session->setMetadata('mcp_server_session', [
            'initializationState' => 3,
        ]);
        $transport->saveSession($session);

        // First stream cycle — own IO, own shutdown handler.
        $firstCall = $this->postRequest((string) \json_encode([
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/call',
            'params' => [
                'name' => 'longtool',
                'arguments' => (object) [],
                '_meta' => ['progressToken' => 'p-10'],
            ],
        ]));
        $firstCall->setHeader('Mcp-Session-Id', $session->getId());
        $transport->handleRequest($firstCall);

        $ioA = new BufferedIo();
        $transport->beginStreamingSseOutput($session, $ioA);
        $this->assertCount(1, $ioA->shutdownHandlers, 'first stream must register its guard on ioA');
        $transport->finalizeStreamingSse($session);

        // Second stream cycle — different per-request IO. Must register
        // its own guard, not be silently skipped by a latched flag.
        $secondCall = $this->postRequest((string) \json_encode([
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'tools/call',
            'params' => [
                'name' => 'longtool',
                'arguments' => (object) [],
                '_meta' => ['progressToken' => 'p-11'],
            ],
        ]));
        $secondCall->setHeader('Mcp-Session-Id', $session->getId());
        $transport->handleRequest($secondCall);

        $ioB = new BufferedIo();
        $transport->beginStreamingSseOutput($session, $ioB);

        $this->assertCount(1, $ioB->shutdownHandlers, 'second stream must register its guard on ioB');
        $this->assertCount(1, $ioA->shutdownHandlers, 'first stream guard must not leak onto ioA twice');
        $this->assertSame([], $defaultIo->shutdownHandlers, 'no guard should leak onto the transport default IO');
    }

    /**
     * The exception path in StandardPhpAdapter::handle() must route the
     * 500 response through the runner's injected HttpIoInterface —
     * embedders using a framework adapter or BufferedIo need predictable
     * capture on errors, not silent direct-SAPI writes. Before this was
     * fixed, the catch block called http_response_code(500) + header() +
     * echo json_encode() directly, bypassing the whole abstraction on
     * exactly the code path where a framework integration most needs
     * consistent behavior.
     */
    public function testStandardPhpAdapterRoutesExceptionResponseThroughInjectedIo(): void
    {
        $io = new BufferedIo();

        $server = new Server('throwing-runner-test');
        $initOptions = new InitializationOptions(
            serverName: 'throwing-runner-test',
            serverVersion: '1.0',
            capabilities: $server->getCapabilities(new NotificationOptions(), []),
        );

        // Subclass the runner to force handleRequest() to throw so we
        // can observe the catch-block behavior in isolation. The runner
        // constructor still runs, giving us a real $io wired into the
        // inherited sendResponse() path.
        $throwingRunner = new class ($server, $initOptions, [], null, null, $io) extends HttpServerRunner {
            public function handleRequest(?HttpMessage $request = null): HttpMessage
            {
                throw new \RuntimeException('simulated handler failure');
            }
        };

        $adapter = new StandardPhpAdapter($throwingRunner);

        // The catch block deliberately calls error_log() for operator
        // diagnostics. Under PHPUnit that writes to stderr and surfaces
        // as noise ("MCP Server error: simulated handler failure") on
        // every run. Redirect to a temp file for the duration of this
        // test only so the suite output stays clean without suppressing
        // the production diagnostic.
        $previousErrorLog = \ini_get('error_log');
        $tempErrorLog = \tempnam(\sys_get_temp_dir(), 'mcp-test-error-log-');
        $this->assertNotFalse($tempErrorLog);
        \ini_set('error_log', $tempErrorLog);
        try {
            $adapter->handle();
        } finally {
            \ini_set('error_log', $previousErrorLog === false ? '' : $previousErrorLog);
            @\unlink($tempErrorLog);
        }

        $this->assertSame(500, $io->status, '500 status must land on the injected IO');
        $contentType = $io->headerValues('Content-Type');
        $this->assertSame(['application/json'], $contentType);
        $this->assertStringContainsString(
            '"error":"Internal server error"',
            $io->buffer,
            'error body must land on the injected IO',
        );
        $this->assertStringContainsString(
            'simulated handler failure',
            $io->buffer,
            'exception message must be surfaced through the IO path',
        );
        $this->assertSame(1, $io->writes, 'body must be written through the IO, not via bare echo');
    }

    /**
     * Legacy duck-typed sink path — the pre-refactor behavior existing
     * tests rely on — must still route frames but record NO header or
     * status calls and NO shutdown registration. The shim's no-op
     * methods preserve the original "tests bypass SAPI entirely"
     * contract even though sendStreamingHeaders() now always runs.
     */
    public function testLegacyDuckTypedSinkPathRemainsSapiFree(): void
    {
        $defaultIo = new BufferedIo();
        $transport = new HttpServerTransport(
            ['enable_sse' => true, 'sse_mode' => 'streaming'],
            null,
            null,
            $defaultIo,
        );
        $transport->start();

        $transport->handleRequest($this->postRequest($this->initBody(1)));
        $session = $transport->getLastUsedSession();
        $this->assertNotNull($session);
        $session->setMetadata('mcp_server_session', [
            'initializationState' => 3,
        ]);
        $transport->saveSession($session);

        $defaultIo->buffer = '';
        $defaultIo->writes = 0;
        $defaultIo->headers = [];
        $defaultIo->status = null;
        $defaultIo->shutdownHandlers = [];

        $toolsCall = $this->postRequest((string) \json_encode([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'tools/call',
            'params' => [
                'name' => 'longtool',
                'arguments' => (object) [],
                '_meta' => ['progressToken' => 'p-9'],
            ],
        ]));
        $toolsCall->setHeader('Mcp-Session-Id', $session->getId());
        $transport->handleRequest($toolsCall);

        $legacySink = new class {
            public string $buffer = '';
            public int $writes = 0;
            public function write(string $s): void
            {
                $this->buffer .= $s;
                $this->writes++;
            }
            public function aborted(): bool
            {
                return false;
            }
        };
        $transport->beginStreamingSseOutput($session, $legacySink);

        // Priming frame reached the legacy sink.
        $this->assertStringContainsString('id: ', $legacySink->buffer);
        $this->assertGreaterThanOrEqual(1, $legacySink->writes);

        // No headers, status, or shutdown registration leaked to the
        // transport's default adapter — the shim's no-ops absorbed them.
        $this->assertNull($defaultIo->status);
        $this->assertSame([], $defaultIo->headers);
        $this->assertSame([], $defaultIo->shutdownHandlers);
    }
}
