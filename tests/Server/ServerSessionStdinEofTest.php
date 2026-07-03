<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\InitializationOptions;
use Mcp\Server\ServerSession;
use Mcp\Server\Transport\Transport;
use Mcp\Server\Transport\TransportClosedException;
use Mcp\Shared\Version;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\RequestId;
use Mcp\Types\RequestParams;
use Mcp\Types\Result;
use Mcp\Types\ServerCapabilities;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for GitHub issue #61 at the session level: when the
 * transport reports that the client closed the connection
 * (TransportClosedException — stdio EOF), ServerSession::start()'s message
 * loop must terminate and stop the session, instead of spinning forever.
 * Before the fix these tests would hang: the stdio transport returned null
 * at EOF and readNextMessage() usleep-looped on null indefinitely.
 */
final class ServerSessionStdinEofTest extends TestCase
{
    /**
     * Main-loop shutdown: after the handshake, EOF must make start() return
     * with the session fully stopped (isInitialized() false — a bare loop
     * exit would leave direct ServerSession users in an initialized state),
     * having written nothing beyond the initialize response.
     */
    public function testStartReturnsAndStopsSessionOnEof(): void
    {
        $transport = new StdinEofTransport();
        $session = $this->buildSession($transport);

        $transport->enqueue($this->initializeRequest());
        $transport->enqueue($this->initializedNotification());

        $session->start();

        $this->assertFalse($session->isInitialized(), 'EOF shutdown must close the session, not just exit the loop');
        $this->assertCount(1, $transport->written, 'only the initialize response should have been written');
        $this->assertInstanceOf(JSONRPCResponse::class, $transport->written[0]->message);
    }

    /**
     * Mid-handler shutdown: a request handler blocked in a client round-trip
     * (stdio elicitation) sees TransportClosedException surface from its
     * blocking read. The generic handler catches must rethrow it — EOF may
     * not be converted into a -32603 error response aimed at the dead
     * stream — and the session must still shut down cleanly.
     */
    public function testEofInsideBlockingHandlerIsNotSwallowedIntoErrorResponse(): void
    {
        $transport = new StdinEofTransport();
        // 'tools/call' rather than an invented method: session intake
        // constructs typed requests and answers unknown methods -32601
        // before they can reach a registered handler.
        $session = $this->buildSession($transport, [
            'tools/call' => function () use (&$session): Result {
                // Blocks in waitForResponse() → readNextMessage(); the queue
                // is drained, so the next transport read reports EOF.
                return $session->sendElicitationRequest(
                    message: 'What is your name?',
                    requestedSchema: [
                        'type' => 'object',
                        'properties' => ['name' => ['type' => 'string']],
                        'required' => ['name'],
                    ],
                );
            },
        ]);

        $transport->enqueue($this->initializeRequest());
        $transport->enqueue($this->initializedNotification());
        $params = new RequestParams();
        $params->name = 'blocking-tool';
        $params->arguments = [];
        $transport->enqueue(new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId(2),
            method: 'tools/call',
            params: $params,
        )));

        $session->start();

        $this->assertFalse($session->isInitialized());
        // Written: the initialize response, then the outgoing
        // elicitation/create request — and nothing after EOF.
        $this->assertCount(2, $transport->written, 'no response may be written for the request interrupted by EOF');
        $this->assertInstanceOf(JSONRPCRequest::class, $transport->written[1]->message);
        $this->assertSame('elicitation/create', $transport->written[1]->message->method);
        foreach ($transport->written as $message) {
            $this->assertNotInstanceOf(
                JSONRPCError::class,
                $message->message,
                'EOF must never be converted into a JSON-RPC error response'
            );
        }
    }

    /**
     * @param array<string, callable> $handlers
     */
    private function buildSession(StdinEofTransport $transport, array $handlers = []): ServerSession
    {
        $session = new ServerSession(
            $transport,
            new InitializationOptions(
                serverName: 'stdin-eof-test',
                serverVersion: '1.0.0',
                capabilities: new ServerCapabilities(),
            ),
        );
        $session->registerHandlers($handlers);
        $session->registerNotificationHandlers([]);

        return $session;
    }

    private function initializeRequest(): JsonRpcMessage
    {
        return new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId(1),
            method: 'initialize',
            params: new RawInitializeParamsForEofTest(
                protocolVersion: Version::LATEST_PROTOCOL_VERSION,
                capabilities: ['elicitation' => []],
                clientInfo: ['name' => 'test-client', 'version' => '1.0.0'],
            ),
        ));
    }

    private function initializedNotification(): JsonRpcMessage
    {
        return new JsonRpcMessage(new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/initialized',
            params: null,
        ));
    }
}

/**
 * Queue-backed transport that reports a closed connection (stdio EOF) once
 * its queue is drained — the post-fix StdioServerTransport contract — and
 * captures every write for assertions.
 */
final class StdinEofTransport implements Transport
{
    /** @var JsonRpcMessage[] */
    public array $written = [];

    /** @var JsonRpcMessage[] */
    private array $incoming = [];

    public function enqueue(JsonRpcMessage $message): void
    {
        $this->incoming[] = $message;
    }

    public function start(): void {}
    public function stop(): void {}

    public function readMessage(): ?JsonRpcMessage
    {
        $message = array_shift($this->incoming);
        if ($message === null) {
            throw new TransportClosedException('stdin closed (EOF) — client disconnected');
        }
        return $message;
    }

    public function writeMessage(JsonRpcMessage $message): void
    {
        $this->written[] = $message;
    }
}

/**
 * Raw initialize params that serialize to wire-format arrays, declaring the
 * elicitation capability so sendElicitationRequest() is allowed.
 */
final class RawInitializeParamsForEofTest extends RequestParams
{
    /**
     * @param array<string, mixed> $capabilities
     * @param array<string, string> $clientInfo
     */
    public function __construct(
        private readonly string $protocolVersion,
        private readonly array $capabilities,
        private readonly array $clientInfo,
    ) {
        parent::__construct();
    }

    public function validate(): void {}

    public function jsonSerialize(): mixed
    {
        return [
            'protocolVersion' => $this->protocolVersion,
            'capabilities' => $this->capabilities,
            'clientInfo' => $this->clientInfo,
        ];
    }
}
