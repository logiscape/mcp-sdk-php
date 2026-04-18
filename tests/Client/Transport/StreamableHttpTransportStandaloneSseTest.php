<?php

declare(strict_types=1);

namespace Mcp\Tests\Client\Transport;

use PHPUnit\Framework\TestCase;
use Mcp\Client\Transport\HttpConfiguration;
use Mcp\Client\Transport\HttpSessionManager;
use Mcp\Client\Transport\SseConnection;
use Mcp\Client\Transport\StreamableHttpTransport;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Behavioral tests for post-initialize standalone GET SSE stream setup on
 * StreamableHttpTransport. The curl I/O path itself is covered end-to-end by
 * conformance tests; these unit tests pin down the control-flow the
 * transport uses to decide *whether* to open the stream, which scope it
 * assigns to cursor tracking, and how it degrades when prerequisites are
 * missing.
 */
final class StreamableHttpTransportStandaloneSseTest extends TestCase
{
    /**
     * Without a message dispatcher, there is no path for server-initiated
     * requests on the stream to reach the session. Opening the stream in
     * that state would silently drop any interleaved elicitation/create or
     * sampling/createMessage, so startStandaloneSseStream() must refuse to
     * attach a connection until a dispatcher is wired up.
     */
    public function testStartWithoutDispatcherIsNoOp(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: true
        );

        $transport->startStandaloneSseStream();

        $sseConnection = $this->readSseConnection($transport);
        $this->assertNull(
            $sseConnection,
            'transport must not open the stream without a registered dispatcher'
        );
    }

    /**
     * When autoSse is disabled at construction time, startStandaloneSseStream
     * is an explicit no-op. Callers can still open a stream manually by
     * constructing an SseConnection themselves, but the auto-open path is
     * suppressed — matching the legacy contract of the autoSse flag.
     */
    public function testStartWhenAutoSseDisabledIsNoOp(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: false
        );
        $transport->setMessageDispatcher(static function (): void {});

        $transport->startStandaloneSseStream();

        $this->assertNull($this->readSseConnection($transport));
    }

    /**
     * pumpStandaloneSseStream is the synchronous hook the POST callbacks
     * use to drain the standalone stream while a POST is in flight. With
     * no connection attached it must be a safe no-op (not a NullPointer
     * style crash), so connect() / sendMessage() do not have to guard it
     * at every call site.
     */
    public function testPumpIsSafeWhenNoStandaloneConnection(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: true
        );

        $pump = new ReflectionMethod($transport, 'pumpStandaloneSseStream');
        $pump->setAccessible(true);
        $pump->invoke($transport);

        $this->assertNull($this->readSseConnection($transport));
    }

    /**
     * When the SseConnection reports the server declined the stream (the
     * 405 Method Not Allowed case sanctioned by the MCP Streamable HTTP
     * spec), a pump tick tears the connection down. Subsequent POSTs must
     * not keep pumping a dead handle.
     */
    public function testPumpTearsDownDeclinedConnection(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: true
        );

        $fake = new class extends SseConnection {
            public int $pumpCalls = 0;
            public function __construct()
            {
                // bypass parent constructor: no curl, no sessionManager
            }
            public function pumpOnce(): void
            {
                $this->pumpCalls++;
            }
            public function wasDeclinedByServer(): bool
            {
                return true;
            }
            public function isActive(): bool
            {
                return false;
            }
            public function getResponseStatus(): ?int
            {
                return 405;
            }
            public function stop(): void
            {
                // no-op
            }
        };

        $prop = new ReflectionProperty($transport, 'sseConnection');
        $prop->setAccessible(true);
        $prop->setValue($transport, $fake);

        $pump = new ReflectionMethod($transport, 'pumpStandaloneSseStream');
        $pump->setAccessible(true);
        $pump->invoke($transport);

        $this->assertSame(1, $fake->pumpCalls, 'pumpOnce is called once before decline is observed');
        $this->assertNull(
            $this->readSseConnection($transport),
            'declined connection must be released so subsequent POSTs do not keep pumping'
        );
    }

    /**
     * A second pump tick on a healthy connection must not tear it down.
     * This guards against an over-eager "clean up if anything looks off"
     * path that would destroy a live stream after the first server event.
     */
    public function testPumpKeepsHealthyConnectionAttached(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: true
        );

        $fake = new class extends SseConnection {
            public int $pumpCalls = 0;
            public function __construct()
            {
            }
            public function pumpOnce(): void
            {
                $this->pumpCalls++;
            }
            public function wasDeclinedByServer(): bool
            {
                return false;
            }
            public function isActive(): bool
            {
                return true;
            }
            public function getResponseStatus(): ?int
            {
                return 200;
            }
            public function stop(): void
            {
            }
        };

        $prop = new ReflectionProperty($transport, 'sseConnection');
        $prop->setAccessible(true);
        $prop->setValue($transport, $fake);

        $pump = new ReflectionMethod($transport, 'pumpStandaloneSseStream');
        $pump->setAccessible(true);
        $pump->invoke($transport);
        $pump->invoke($transport);

        $this->assertSame(2, $fake->pumpCalls);
        $this->assertSame(
            $fake,
            $this->readSseConnection($transport),
            'healthy connection must remain attached across pump ticks'
        );
    }

    /**
     * Calling startStandaloneSseStream while a stream is already active is
     * a no-op. Without this guard, a caller that invokes it from both
     * connect() and resume paths (or any retry logic) would leak handles.
     */
    public function testStartIsIdempotentWhileActive(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: true
        );

        $fake = new class extends SseConnection {
            public function __construct() {}
            public function isActive(): bool { return true; }
            public function wasDeclinedByServer(): bool { return false; }
            public function pumpOnce(): void {}
            public function stop(): void {}
        };

        $prop = new ReflectionProperty($transport, 'sseConnection');
        $prop->setAccessible(true);
        $prop->setValue($transport, $fake);

        $transport->setMessageDispatcher(static function (): void {});
        $transport->startStandaloneSseStream();

        $this->assertSame(
            $fake,
            $this->readSseConnection($transport),
            'an already-active stream must not be replaced on a second start call'
        );
    }

    private function readSseConnection(StreamableHttpTransport $transport): ?SseConnection
    {
        $prop = new ReflectionProperty($transport, 'sseConnection');
        $prop->setAccessible(true);
        /** @var SseConnection|null $value */
        $value = $prop->getValue($transport);
        return $value;
    }
}
