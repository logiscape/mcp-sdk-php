<?php

declare(strict_types=1);

namespace Mcp\Tests\Server\Transport;

use Mcp\Server\Transport\StdioServerTransport;
use Mcp\Server\Transport\TransportClosedException;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCRequest;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for GitHub issue #61: StdioServerTransport treated EOF on
 * stdin as "no data yet" and returned null forever, so a server whose client
 * had closed stdin (the MCP lifecycle's stdio shutdown signal) never left its
 * message loop — the process spun in a 10 ms sleep loop until an external
 * SIGTERM, which some Docker setups never deliver.
 *
 * The contract pinned here: readMessage() returns null only while the stream
 * is still open with no complete line available, and throws
 * TransportClosedException once stdin reaches EOF.
 */
final class StdioServerTransportEofTest extends TestCase
{
    /**
     * A stream that is already at EOF (empty, nothing will ever arrive) must
     * surface as TransportClosedException, not as an endless null.
     */
    public function testReadMessageThrowsAtImmediateEof(): void
    {
        $stdin = fopen('php://memory', 'r+');
        $stdout = fopen('php://memory', 'r+');
        $this->assertIsResource($stdin);
        $this->assertIsResource($stdout);

        $transport = new StdioServerTransport($stdin, $stdout);
        $transport->start();

        $this->expectException(TransportClosedException::class);
        $transport->readMessage();
    }

    /**
     * Buffered data is still delivered before the EOF signal: one valid
     * message parses normally, and only the read after it throws.
     */
    public function testReadMessageDeliversBufferedMessageThenThrows(): void
    {
        $stdin = fopen('php://memory', 'r+');
        $stdout = fopen('php://memory', 'r+');
        $this->assertIsResource($stdin);
        $this->assertIsResource($stdout);

        fwrite($stdin, '{"jsonrpc":"2.0","id":1,"method":"ping"}' . "\n");
        rewind($stdin);

        $transport = new StdioServerTransport($stdin, $stdout);
        $transport->start();

        $message = $transport->readMessage();
        $this->assertInstanceOf(JsonRpcMessage::class, $message);
        $this->assertInstanceOf(JSONRPCRequest::class, $message->message);
        $this->assertSame('ping', $message->message->method);

        $this->expectException(TransportClosedException::class);
        $transport->readMessage();
    }

    /**
     * The distinction the fix hinges on: a non-blocking stream with no data
     * available yet is NOT EOF — readMessage() must keep returning null so
     * the session keeps polling — while the peer closing its end must turn
     * the very same call into TransportClosedException.
     */
    public function testIdleOpenStreamReturnsNullUntilPeerCloses(): void
    {
        $domain = PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX;
        $pair = @stream_socket_pair($domain, STREAM_SOCK_STREAM, $domain === STREAM_PF_INET ? STREAM_IPPROTO_IP : 0);
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair() is unavailable on this platform');
        }
        [$readEnd, $writeEnd] = $pair;
        $stdout = fopen('php://memory', 'r+');
        $this->assertIsResource($stdout);

        $transport = new StdioServerTransport($readEnd, $stdout);
        $transport->start();
        // start() only switches to non-blocking mode on non-Windows; force it
        // here so the test exercises the same configuration on every platform
        // (and never blocks in fgets on an idle socket).
        $this->assertTrue(stream_set_blocking($readEnd, false));

        $this->assertNull($transport->readMessage(), 'idle open stream must read as "no data yet"');
        $this->assertNull($transport->readMessage(), 'still no data: must stay null, not EOF');

        fwrite($writeEnd, '{"jsonrpc":"2.0","id":2,"method":"ping"}' . "\n");
        $message = $this->readUntilAvailable($transport);
        $this->assertInstanceOf(JSONRPCRequest::class, $message->message);

        fclose($writeEnd);

        $this->expectException(TransportClosedException::class);
        // Socket delivery of the FIN may not be instantaneous; poll through
        // the transient nulls until the transport reports the closed stream.
        for ($i = 0; $i < 100; $i++) {
            if ($transport->readMessage() === null) {
                usleep(10000);
            }
        }
        $this->fail('readMessage() never signalled EOF after the peer closed the stream');
    }

    /**
     * Drain transient nulls from a non-blocking socket until the just-written
     * message becomes readable.
     */
    private function readUntilAvailable(StdioServerTransport $transport): JsonRpcMessage
    {
        for ($i = 0; $i < 100; $i++) {
            $message = $transport->readMessage();
            if ($message !== null) {
                return $message;
            }
            usleep(10000);
        }
        $this->fail('message written to the socket pair never became readable');
    }
}
