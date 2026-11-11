<?php

declare(strict_types=1);

namespace Mcp\Tests\Client\Transport;

use Mcp\Client\Transport\StdioServerParameters;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCResponse;
use PHPUnit\Framework\TestCase;

/**
 * The client reads the server's stdout without blocking, so fgets() also
 * returns the part of a line that has arrived so far. receive() dropped that
 * part between calls and decoded whatever ended with "}" or "]", so a large
 * response, e.g. a tool result of 500 KB or more, failed with a JsonException.
 */
final class StdioTransportPartialLineTest extends TestCase
{
    private ?StdioTransport $transport = null;

    protected function tearDown(): void
    {
        $this->transport?->close();
    }

    public function testResponseArrivingInTwoReadsIsReceivedWhole(): void
    {
        $message = $this->receiveFrom('100000');

        $this->assertInstanceOf(JsonRpcMessage::class, $message);
        $this->assertInstanceOf(JSONRPCResponse::class, $message->message);
        $this->assertSame(1, $message->message->id->getValue());
    }

    public function testLastResponseWithoutNewlineIsReceivedAtEof(): void
    {
        $message = $this->receiveFrom('1000', 'no-newline');

        $this->assertInstanceOf(JsonRpcMessage::class, $message);
        $this->assertInstanceOf(JSONRPCResponse::class, $message->message);
    }

    private function receiveFrom(string ...$args): mixed
    {
        $this->transport = new StdioTransport(new StdioServerParameters(
            PHP_BINARY,
            [__DIR__ . '/Fixtures/stdio_server_split_writes.php', ...$args],
        ));
        [$readStream] = $this->transport->connect();

        // Poll like ClientSession::readNextMessage() does
        $until = microtime(true) + 5.0;
        do {
            $message = $readStream->receive();
            if ($message !== null) {
                return $message;
            }
            usleep(10000);
        } while (microtime(true) < $until);

        $this->fail('no message received');
    }
}
