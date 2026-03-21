<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2025 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 */

declare(strict_types=1);

namespace Mcp\Tests\Shared;

use Mcp\Shared\BaseSession;
use Mcp\Shared\McpError;
use Mcp\Shared\RequestResponder;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JsonRpcErrorObject;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\RequestId;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BaseSession JSON-RPC error propagation and response handling.
 *
 * Validates that BaseSession correctly:
 * - Converts JSONRPCError responses into McpError exceptions
 * - Embeds error code, message, and data into the exception
 * - Cleans up response handlers after error responses (prevents memory leaks)
 * - Cleans up response handlers after successful responses
 * - Does not hang when receiving error responses
 * - Properly correlates responses with requests via request IDs
 *
 * Critical for ensuring robust error handling in JSON-RPC communication.
 * If error propagation is broken, clients will hang waiting for responses
 * that will never arrive, or leak memory from uncleaned handlers.
 */
final class BaseSessionTest extends TestCase
{
    /**
     * Test that sendRequest() throws McpError when receiving JSONRPCError response.
     *
     * Error propagation flow:
     * 1. Client sends request with ID 0
     * 2. Server responds with JSONRPCError (instead of JSONRPCResponse)
     * 3. Response handler (BaseSession.php:126-148) detects error type
     * 4. Handler creates ErrorData from JsonRpcErrorObject (lines 132-136)
     * 5. Handler throws McpError containing the ErrorData (line 137)
     * 6. Exception propagates out of waitForResponse loop (line 390)
     * 7. Client receives McpError with embedded error details
     *
     * This is the critical error path - if broken, clients will:
     * - Receive exceptions with missing error details
     * - Or hang forever waiting for a success response
     *
     * Corresponds to BaseSession.php:129-137 (error response handling)
     */
    public function testSendRequestThrowsMcpErrorOnJsonRpcError(): void
    {
        // Arrange: Create a fake session that can queue incoming messages
        $session = new FakeSession();

        // Queue a JSONRPCError response message
        // This simulates the server returning an error instead of success
        $errorMessage = new JsonRpcMessage(
            new JSONRPCError(
                jsonrpc: '2.0',
                id: new RequestId(0), // Response for request ID 0
                error: new JsonRpcErrorObject(
                    code: 1234,
                    message: 'boom',
                    data: ['detail' => 'failure']
                )
            )
        );
        $session->queueIncoming($errorMessage);

        // Create a dummy request to send
        $request = new DummyOutgoingRequest('tools/list');

        // Act & Assert: sendRequest should throw McpError
        try {
            $session->sendRequest($request, DummyResult::class);
            $this->fail('Expected McpError to be thrown when receiving JSONRPCError');
        } catch (McpError $error) {
            // Verify error code, message, and data are correctly embedded
            $this->assertSame(1234, $error->error->code, 'Error code should be embedded in McpError');
            $this->assertSame('boom', $error->error->message, 'Error message should be embedded in McpError');
            $this->assertSame(
                ['detail' => 'failure'],
                $error->error->data,
                'Error data should be embedded in McpError'
            );
        }

        // Verify response handler was cleaned up (critical for preventing memory leaks)
        // BaseSession.php:302 removes handler BEFORE calling it, ensuring cleanup even on exception
        $this->assertSame(
            0,
            $this->countResponseHandlers($session),
            'Response handler should be removed after error to prevent memory leak'
        );
    }

    /**
     * Test that sendRequest() cleans up response handler on successful response.
     *
     * Handler cleanup is critical for preventing memory leaks in long-lived sessions.
     * The cleanup happens in handleIncomingMessage() at line 302, which removes
     * the handler BEFORE calling it, ensuring cleanup regardless of success or error.
     *
     * This test verifies the success path also cleans up properly.
     *
     * Corresponds to BaseSession.php:297-303 (response handler cleanup)
     */
    public function testSendRequestCleansUpHandlerOnSuccessResponse(): void
    {
        // Arrange: Create session and queue a successful response
        $session = new FakeSession();

        $successMessage = new JsonRpcMessage(
            new JSONRPCResponse(
                jsonrpc: '2.0',
                id: new RequestId(0), // Response for request ID 0
                result: ['success' => true]
            )
        );
        $session->queueIncoming($successMessage);

        // Create request
        $request = new DummyOutgoingRequest('tools/list');

        // Act: Send request and receive successful response
        $result = $session->sendRequest($request, DummyResult::class);

        // Assert: Result should be returned successfully
        $this->assertInstanceOf(DummyResult::class, $result, 'Should return result object on success');

        // Verify response handler was cleaned up
        $this->assertSame(
            0,
            $this->countResponseHandlers($session),
            'Response handler should be removed after successful response to prevent memory leak'
        );
    }

    /**
     * Test that errors do not cause the client to hang.
     *
     * Edge case validation:
     * - When server sends error instead of success, client must throw (not hang)
     * - The waitForResponse loop (BaseSession.php:387-392) must exit on error
     * - This is ensured by the exception propagating out of handleIncomingMessage
     *
     * This test uses a timeout to verify the request completes immediately
     * instead of hanging forever.
     *
     * Corresponds to BaseSession.php:387-392 (waitForResponse loop)
     */
    public function testErrorResponseDoesNotHang(): void
    {
        // Arrange: Create session with error response
        $session = new FakeSession();

        $errorMessage = new JsonRpcMessage(
            new JSONRPCError(
                jsonrpc: '2.0',
                id: new RequestId(0),
                error: new JsonRpcErrorObject(
                    code: -32601,
                    message: 'Method not found',
                    data: null
                )
            )
        );
        $session->queueIncoming($errorMessage);

        $request = new DummyOutgoingRequest('unknown/method');

        // Act: Measure execution time
        $startTime = microtime(true);
        $exceptionThrown = false;

        try {
            $session->sendRequest($request, DummyResult::class);
        } catch (McpError $error) {
            $exceptionThrown = true;
        }

        $executionTime = microtime(true) - $startTime;

        // Assert: Should throw immediately (not hang)
        $this->assertTrue($exceptionThrown, 'McpError should be thrown');
        $this->assertLessThan(
            1.0,
            $executionTime,
            'Request should complete immediately (< 1 second), not hang'
        );
    }

    /**
     * Test that different error codes and messages are correctly propagated.
     *
     * Validates that all error details (code, message, data) are preserved
     * when converting JsonRpcErrorObject → ErrorData → McpError.
     *
     * Tests common JSON-RPC error codes:
     * - -32700: Parse error
     * - -32600: Invalid Request
     * - -32601: Method not found
     * - -32602: Invalid params
     * - -32603: Internal error
     * - Custom error codes (application-defined)
     *
     * Corresponds to BaseSession.php:132-137 (ErrorData creation)
     */
    public function testDifferentErrorCodesArePropagated(): void
    {
        $testCases = [
            [
                'code' => -32700,
                'message' => 'Parse error',
                'data' => ['raw' => 'invalid json']
            ],
            [
                'code' => -32600,
                'message' => 'Invalid Request',
                'data' => ['field' => 'missing id']
            ],
            [
                'code' => -32601,
                'message' => 'Method not found',
                'data' => ['method' => 'unknown/method']
            ],
            [
                'code' => -32602,
                'message' => 'Invalid params',
                'data' => ['param' => 'toolName', 'reason' => 'required']
            ],
            [
                'code' => -32603,
                'message' => 'Internal error',
                'data' => ['exception' => 'NullPointerException']
            ],
            [
                'code' => 5000,
                'message' => 'Custom application error',
                'data' => ['context' => 'business logic validation failed']
            ],
        ];

        foreach ($testCases as $testCase) {
            // Arrange: Create session with specific error
            $session = new FakeSession();

            // Each new session starts with request ID 0, so response must also use ID 0
            $errorMessage = new JsonRpcMessage(
                new JSONRPCError(
                    jsonrpc: '2.0',
                    id: new RequestId(0),
                    error: new JsonRpcErrorObject(
                        code: $testCase['code'],
                        message: $testCase['message'],
                        data: $testCase['data']
                    )
                )
            );
            $session->queueIncoming($errorMessage);

            $request = new DummyOutgoingRequest('test/method');

            // Act & Assert
            try {
                $session->sendRequest($request, DummyResult::class);
                $this->fail("Expected McpError for error code {$testCase['code']}");
            } catch (McpError $error) {
                $this->assertSame(
                    $testCase['code'],
                    $error->error->code,
                    "Error code {$testCase['code']} should be propagated"
                );
                $this->assertSame(
                    $testCase['message'],
                    $error->error->message,
                    "Error message should be propagated for code {$testCase['code']}"
                );
                $this->assertSame(
                    $testCase['data'],
                    $error->error->data,
                    "Error data should be propagated for code {$testCase['code']}"
                );
            }
        }
    }

    /**
     * Test that error with null data is handled correctly.
     *
     * The JSON-RPC spec allows error.data to be null or omitted.
     * This validates that null data doesn't cause issues.
     *
     * Corresponds to BaseSession.php:135 (data: $innerMessage->error->data)
     */
    public function testErrorWithNullDataIsHandled(): void
    {
        // Arrange
        $session = new FakeSession();

        $errorMessage = new JsonRpcMessage(
            new JSONRPCError(
                jsonrpc: '2.0',
                id: new RequestId(0),
                error: new JsonRpcErrorObject(
                    code: -32603,
                    message: 'Internal error',
                    data: null // No additional data
                )
            )
        );
        $session->queueIncoming($errorMessage);

        $request = new DummyOutgoingRequest('tools/list');

        // Act & Assert
        try {
            $session->sendRequest($request, DummyResult::class);
            $this->fail('Expected McpError to be thrown');
        } catch (McpError $error) {
            $this->assertSame(-32603, $error->error->code);
            $this->assertSame('Internal error', $error->error->message);
            $this->assertNull($error->error->data, 'Null error data should be preserved');
        }
    }

    /**
     * Test that multiple sequential requests each clean up their handlers.
     *
     * Validates that handler cleanup works correctly for multiple requests,
     * ensuring no memory leaks in realistic usage scenarios.
     *
     * Corresponds to BaseSession.php:302 (handler cleanup)
     */
    public function testMultipleRequestsCleanUpHandlers(): void
    {
        // Arrange
        $session = new FakeSession();

        // Send 3 requests and verify each cleans up
        for ($i = 0; $i < 3; $i++) {
            // Queue success response for request ID $i
            $successMessage = new JsonRpcMessage(
                new JSONRPCResponse(
                    jsonrpc: '2.0',
                    id: new RequestId($i),
                    result: ['request' => $i]
                )
            );
            $session->queueIncoming($successMessage);

            // Send request
            $request = new DummyOutgoingRequest('test/method');
            $result = $session->sendRequest($request, DummyResult::class);

            // Assert handler was cleaned up after each request
            $this->assertSame(
                0,
                $this->countResponseHandlers($session),
                "Response handler should be cleaned up after request $i"
            );
        }
    }

    /**
     * Test that incoming requests are properly dispatched through RequestResponder.
     *
     * Server-side request dispatch flow:
     * 1. JSON-RPC request message arrives via handleIncomingMessage()
     * 2. BaseSession validates JSON-RPC version and message type
     * 3. BaseSession extracts method, params, and request ID
     * 4. BaseSession creates RequestResponder with extracted data
     * 5. RequestResponder is passed to registered onRequest handlers
     * 6. Handler calls responder.sendResponse() to send reply
     * 7. JSON-RPC response is written with correlated request ID
     *
     * This tests the complete server-side request intake and response path,
     * which is critical for all server functionality. If this path is broken,
     * servers cannot process ANY client requests.
     *
     * The test also validates metadata extraction (_meta field) and proper
     * response correlation (matching request/response IDs).
     *
     * Corresponds to BaseSession.php:266-296 (request dispatch logic)
     */
    public function testIncomingRequestDispatchesThroughResponder(): void
    {
        $session = new FakeSession();
        $responseSent = false;
        $capturedRequest = null;
        $capturedMeta = null;

        $session->onRequest(function (RequestResponder $responder) use (&$responseSent, &$capturedRequest, &$capturedMeta): void {
            $capturedRequest = $responder->getRequest();
            $capturedMeta = $responder->getMeta();
            $responder->sendResponse(new DummyResult());
            $responseSent = true;
        });

        $requestMessage = new JsonRpcMessage(
            new JSONRPCRequest(
                jsonrpc: '2.0',
                id: new RequestId(5),
                method: 'dummy/request',
                params: new TestRequestParams(['foo' => 'bar', '_meta' => ['trace' => 'abc']])
            )
        );

        $session->processIncoming($requestMessage);

        $this->assertTrue($responseSent, 'Request handler should be invoked');
        $this->assertSame('dummy/request', $capturedRequest->method);
        $this->assertSame(['trace' => 'abc'], $capturedMeta?->jsonSerialize());
        $this->assertCount(1, $session->writtenMessages, 'Response should be written');
        $this->assertInstanceOf(
            JSONRPCResponse::class,
            $session->writtenMessages[0]->message,
            'Expected a JSON-RPC response to be sent'
        );
        $this->assertSame(5, $session->writtenMessages[0]->message->id->getValue(), 'Response should correlate ID');
    }

    /**
     * Test that incoming notifications invoke registered handlers without sending responses.
     *
     * Notification dispatch flow:
     * 1. JSON-RPC notification message arrives (no 'id' field)
     * 2. BaseSession validates JSON-RPC version
     * 3. BaseSession routes to notification handler path (not request path)
     * 4. Notification is converted to typed object via fromMethodAndParams()
     * 5. Registered onNotification handlers are invoked with typed notification
     * 6. Handlers process notification
     * 7. NO response is sent (notifications are fire-and-forget per JSON-RPC spec)
     *
     * This validates the one-way notification path, which is critical for
     * events like progress updates, resource changes, and log messages.
     *
     * Unlike requests, notifications must NEVER generate responses. This test
     * verifies that constraint is enforced.
     *
     * Corresponds to BaseSession.php:308-320 (notification handling)
     */
    public function testIncomingNotificationInvokesRegisteredHandlers(): void
    {
        $session = new FakeSession();
        $receivedMethod = null;
        $receivedParams = null;

        $session->onNotification(function (DummyIncomingNotification $notification) use (&$receivedMethod, &$receivedParams): void {
            $receivedMethod = $notification->method;
            $receivedParams = $notification->params;
        });

        $notificationMessage = new JsonRpcMessage(
            new JSONRPCNotification(
                jsonrpc: '2.0',
                method: 'notifications/test',
                params: new TestNotificationParams(['ping' => true])
            )
        );

        $session->processIncoming($notificationMessage);

        $this->assertSame('notifications/test', $receivedMethod);
        $this->assertSame(['ping' => true], $receivedParams);
        $this->assertCount(0, $session->writtenMessages, 'Notifications should not emit responses');
    }

    /**
     * Test that messages with invalid JSON-RPC version are rejected.
     *
     * JSON-RPC version validation flow:
     * 1. Message arrives with jsonrpc field
     * 2. handleIncomingMessage() calls validateMessage()
     * 3. validateMessage() checks if jsonrpc === '2.0'
     * 4. If version is not '2.0', InvalidArgumentException is thrown
     * 5. Message is rejected without processing
     *
     * The JSON-RPC 2.0 specification requires the "jsonrpc" field to be
     * exactly "2.0". Older versions (1.0, 1.1) or invalid values must be rejected.
     *
     * This validation is critical for protocol compliance. Without it, the SDK
     * might attempt to process messages with incompatible semantics (e.g.,
     * JSON-RPC 1.0 uses different error codes and lacks batch support).
     *
     * Corresponds to BaseSession.php:323-327 (validateMessage)
     */
    public function testInvalidJsonRpcVersionRejectsMessage(): void
    {
        $session = new FakeSession();

        $invalid = new JsonRpcMessage(
            new JSONRPCRequest(
                jsonrpc: '1.0',
                id: new RequestId(0),
                method: 'dummy',
                params: null
            )
        );

        $this->expectException(\InvalidArgumentException::class);
        $session->processIncoming($invalid);
    }

    /**
     * Use reflection to count response handlers for verification.
     *
     * Response handlers are stored in BaseSession::$responseHandlers (private property).
     * This helper uses reflection to verify handlers are properly cleaned up.
     *
     * @param BaseSession $session The session to inspect
     * @return int The number of pending response handlers
     */
    private function countResponseHandlers(BaseSession $session): int
    {
        $prop = new \ReflectionProperty(BaseSession::class, 'responseHandlers');
        $prop->setAccessible(true);
        $handlers = $prop->getValue($session);
        return is_array($handlers) ? count($handlers) : 0;
    }
}

/**
 * Fake session implementation for testing BaseSession behavior.
 *
 * This test double allows tests to:
 * - Queue incoming messages (simulating server responses)
 * - Inspect written messages (verifying requests sent)
 * - Test BaseSession logic without real transport layers
 *
 * Unlike production sessions (ClientSession, ServerSession), this session
 * uses in-memory queues instead of network I/O.
 */
final class FakeSession extends BaseSession
{
    /** @var JsonRpcMessage[] Queue of incoming messages to process */
    private array $incoming = [];

    /** @var JsonRpcMessage[] Messages written by the session */
    public array $writtenMessages = [];

    /**
     * Construct a fake session with dummy request/notification types.
     */
    public function __construct()
    {
        parent::__construct(
            receiveRequestType: DummyIncomingRequest::class,
            receiveNotificationType: DummyIncomingNotification::class
        );
    }

    public function processIncoming(JsonRpcMessage $message): void
    {
        $this->handleIncomingMessage($message);
    }

    /**
     * Queue an incoming message to be processed by the session.
     *
     * This simulates receiving a message from the transport layer.
     *
     * @param JsonRpcMessage $message The message to queue
     */
    public function queueIncoming(JsonRpcMessage $message): void
    {
        $this->incoming[] = $message;
    }

    /**
     * Write a message to the outgoing queue.
     *
     * This captures messages sent by the session for test inspection.
     *
     * @param JsonRpcMessage $message The message to write
     */
    protected function writeMessage(JsonRpcMessage $message): void
    {
        $this->writtenMessages[] = $message;
    }

    /**
     * Read the next message from the incoming queue.
     *
     * @return JsonRpcMessage The next queued message
     * @throws \RuntimeException If no messages are queued
     */
    protected function readNextMessage(): JsonRpcMessage
    {
        if (empty($this->incoming)) {
            throw new \RuntimeException('No queued messages');
        }

        return array_shift($this->incoming);
    }

    /**
     * No-op for test session.
     */
    protected function startMessageProcessing(): void {}

    /**
     * No-op for test session.
     */
    protected function stopMessageProcessing(): void {}
}

/**
 * Dummy outgoing request for testing.
 *
 * Extends the protocol Request base type to be compatible with sendRequest().
 */
final class DummyOutgoingRequest extends \Mcp\Types\Request
{
    public function __construct(string $method)
    {
        parent::__construct($method);
    }
}

/**
 * Dummy result type for testing.
 *
 * Extends the protocol Result base type to be compatible with sendRequest().
 */
final class DummyResult extends \Mcp\Types\Result
{
    /** @param array<string, mixed> $data */
    public static function fromResponseData(array $data): self
    {
        return new self();
    }
}

/**
 * Test request parameters that wrap arbitrary key-value arrays.
 *
 * This helper allows tests to create request params with any structure
 * without defining specific typed parameter classes for each test case.
 * It's used for tests that need flexible parameter structures.
 */
final class TestRequestParams extends \Mcp\Types\RequestParams
{
    public function __construct(private array $values)
    {
        parent::__construct();
    }

    public function validate(): void {}

    public function jsonSerialize(): mixed
    {
        return $this->values ?: new \stdClass();
    }
}

/**
 * Test notification parameters that wrap arbitrary key-value arrays.
 *
 * Similar to TestRequestParams but for notifications. Allows tests to
 * create notification params with flexible structures without defining
 * typed classes for each test scenario.
 */
final class TestNotificationParams extends \Mcp\Types\NotificationParams
{
    public function __construct(private array $values)
    {
        parent::__construct();
    }

    public function validate(): void {}

    public function jsonSerialize(): mixed
    {
        return $this->values ?: new \stdClass();
    }
}

/**
 * Dummy incoming request for server-side request handling tests.
 *
 * This test double implements RequestWrapperInterface to simulate typed requests that
 * BaseSession creates when processing incoming JSON-RPC request messages.
 *
 * It supports:
 * - Custom method names for testing different request types
 * - Arbitrary parameter arrays for flexible test data
 * - Request ID correlation for response matching
 * - Conversion to JSONRPCRequest for message construction
 *
 * Unlike real request types (InitializeRequest, etc.), this class accepts
 * any method/params combination, making it useful for generic request tests.
 */
final class DummyIncomingRequest implements \Mcp\Types\RequestWrapperInterface
{
    public function __construct(
        public string $method = '',
        public array $params = [],
        public ?RequestId $id = null,
    ) {}

    public static function fromMethodAndParams(string $method, ?array $params): static
    {
        $params = $params ?? [];
        return new static(method: $method, params: $params, id: new RequestId($params['_id'] ?? 1));
    }

    public function getRequest(): \Mcp\Types\Request
    {
        return new \Mcp\Types\PingRequest();
    }

    public function validate(): void {}

    public function jsonSerialize(): mixed
    {
        return [];
    }
}

/**
 * Dummy incoming notification for notification handling tests.
 *
 * This test double implements McpModel to simulate typed notifications that
 * BaseSession creates when processing incoming JSON-RPC notification messages.
 *
 * It supports:
 * - Custom method names for testing different notification types
 * - Arbitrary parameter arrays for flexible test data
 * - Conversion to JSONRPCNotification for message construction
 *
 * Unlike real notification types (InitializedNotification, etc.), this class
 * accepts any method/params combination, making it useful for generic notification tests.
 */
final class DummyIncomingNotification implements \Mcp\Types\McpModel
{
    public function __construct(
        public string $method = '',
        public array $params = [],
    ) {}

    public static function fromMethodAndParams(string $method, array $params): self
    {
        return new self(method: $method, params: $params);
    }

    public function getNotification(): JSONRPCNotification
    {
        $notificationParams = $this->params ? new TestNotificationParams($this->params) : null;
        return new JSONRPCNotification(jsonrpc: '2.0', method: $this->method, params: $notificationParams);
    }

    public function validate(): void {}

    public function jsonSerialize(): mixed
    {
        return [];
    }
}
