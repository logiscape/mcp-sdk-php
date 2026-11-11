<?php

declare(strict_types=1);

namespace Mcp\Tests\Server\Transport;

use Mcp\Server\Transport\StdioServerTransport;
use Mcp\Types\JSONRPCRequest;
use PHPUnit\Framework\TestCase;

/**
 * readMessage() used to throw McpError on a line that is not a JSON-RPC
 * message. Nothing in the stdio session loop caught it, so one bad line from
 * the client ended the whole server process. The line is now answered with a
 * JSON-RPC error and the next message is read as usual.
 */
final class StdioServerTransportMalformedLineTest extends TestCase
{
    public function testInvalidJsonIsAnsweredWithAParseErrorAndReadingGoesOn(): void
    {
        [$transport, $stdout] = $this->transportReading(
            '{"jsonrpc":"2.0","id":5,"method":"ping"' . "\n"
            . '{"jsonrpc":"2.0","id":6,"method":"ping"}' . "\n"
        );

        $this->assertNull($transport->readMessage());

        $message = $transport->readMessage();
        $this->assertInstanceOf(JSONRPCRequest::class, $message?->message);
        $this->assertSame(6, $message->message->id->getValue());

        $responses = $this->writtenMessages($stdout);
        $this->assertCount(1, $responses);
        $this->assertSame('2.0', $responses[0]['jsonrpc']);
        $this->assertNull($responses[0]['id']);
        $this->assertSame(-32700, $responses[0]['error']['code']);
    }

    public function testInvalidRequestIsAnsweredWithItsId(): void
    {
        [$transport, $stdout] = $this->transportReading('{"id":8,"method":"ping"}' . "\n");

        $this->assertNull($transport->readMessage());

        $responses = $this->writtenMessages($stdout);
        $this->assertCount(1, $responses);
        $this->assertSame(8, $responses[0]['id']);
        $this->assertSame(-32600, $responses[0]['error']['code']);
    }

    public function testJsonThatIsNotAnObjectIsAnInvalidRequest(): void
    {
        [$transport, $stdout] = $this->transportReading('42' . "\n");

        $this->assertNull($transport->readMessage());

        $responses = $this->writtenMessages($stdout);
        $this->assertCount(1, $responses);
        $this->assertNull($responses[0]['id']);
        $this->assertSame(-32600, $responses[0]['error']['code']);
    }

    public function testBlankLineIsSkippedWithoutAnswer(): void
    {
        [$transport, $stdout] = $this->transportReading("\n" . '{"jsonrpc":"2.0","id":1,"method":"ping"}' . "\n");

        $this->assertNull($transport->readMessage());
        $this->assertInstanceOf(JSONRPCRequest::class, $transport->readMessage()?->message);
        $this->assertSame([], $this->writtenMessages($stdout));
    }

    /**
     * @return array{StdioServerTransport, resource}
     */
    private function transportReading(string $input): array
    {
        $stdin = fopen('php://memory', 'r+');
        $stdout = fopen('php://memory', 'r+');
        $this->assertIsResource($stdin);
        $this->assertIsResource($stdout);

        fwrite($stdin, $input);
        rewind($stdin);

        $transport = new StdioServerTransport($stdin, $stdout);
        $transport->start();

        return [$transport, $stdout];
    }

    /**
     * @param resource $stdout
     *
     * @return list<array<string, mixed>>
     */
    private function writtenMessages($stdout): array
    {
        rewind($stdout);
        $lines = array_filter(explode("\n", (string) stream_get_contents($stdout)));

        return array_values(array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines));
    }
}
