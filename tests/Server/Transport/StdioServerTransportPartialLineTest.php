<?php

declare(strict_types=1);

namespace Mcp\Tests\Server\Transport;

use Mcp\Server\Transport\StdioServerTransport;
use Mcp\Server\Transport\TransportClosedException;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCRequest;
use PHPUnit\Framework\TestCase;

/**
 * On the non-blocking stdin of a stdio server, fgets() returns whatever has
 * arrived so far. A message larger than the pipe buffer (a tool call with a
 * big argument) therefore arrives in several parts, and each part used to be
 * decoded as a whole message.
 */
final class StdioServerTransportPartialLineTest extends TestCase
{
    public function testMessageArrivingInPartsIsReadOnceComplete(): void
    {
        [$readEnd, $writeEnd] = $this->socketPair();
        $transport = $this->startedTransport($readEnd);

        $message = '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"length","arguments":{"text":"' . str_repeat('a', 2000) . '"}}}';
        $half = intdiv(strlen($message), 2);

        fwrite($writeEnd, substr($message, 0, $half));
        $this->assertNull($this->readFor($transport, 0.1), 'the first part must wait for the rest of the message');

        fwrite($writeEnd, substr($message, $half) . "\n");
        $read = $this->readFor($transport, 2.0);

        $this->assertInstanceOf(JsonRpcMessage::class, $read);
        $this->assertInstanceOf(JSONRPCRequest::class, $read->message);
        $this->assertSame('tools/call', $read->message->method);
        $this->assertSame(7, $read->message->id->getValue());
    }

    public function testLastMessageWithoutNewlineIsReadAtEof(): void
    {
        [$readEnd, $writeEnd] = $this->socketPair();
        $transport = $this->startedTransport($readEnd);

        fwrite($writeEnd, '{"jsonrpc":"2.0","id":3,');
        $this->assertNull($this->readFor($transport, 0.1));

        fwrite($writeEnd, '"method":"ping"}');
        fclose($writeEnd);

        $read = $this->readFor($transport, 2.0);
        $this->assertInstanceOf(JsonRpcMessage::class, $read);
        $this->assertSame('ping', $read->message->method);

        $this->expectException(TransportClosedException::class);
        $this->readFor($transport, 2.0);
    }

    /**
     * @return array{resource, resource}
     */
    private function socketPair(): array
    {
        $domain = PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX;
        $pair = @stream_socket_pair($domain, STREAM_SOCK_STREAM, $domain === STREAM_PF_INET ? STREAM_IPPROTO_IP : 0);
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair() is unavailable on this platform');
        }

        return $pair;
    }

    /**
     * @param resource $readEnd
     */
    private function startedTransport($readEnd): StdioServerTransport
    {
        $stdout = fopen('php://memory', 'r+');
        $this->assertIsResource($stdout);

        $transport = new StdioServerTransport($readEnd, $stdout);
        $transport->start();
        // start() only switches to non-blocking mode on non-Windows; force it
        // so the test runs the same configuration on every platform
        $this->assertTrue(stream_set_blocking($readEnd, false));

        return $transport;
    }

    /**
     * Polls readMessage() like the session loop does, for at most $seconds.
     */
    private function readFor(StdioServerTransport $transport, float $seconds): ?JsonRpcMessage
    {
        $until = microtime(true) + $seconds;
        do {
            $message = $transport->readMessage();
            if ($message !== null) {
                return $message;
            }
            usleep(10000);
        } while (microtime(true) < $until);

        return null;
    }
}
