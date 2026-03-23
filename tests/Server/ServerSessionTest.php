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

namespace Mcp\Tests\Server;

use Mcp\Server\InitializationOptions;
use Mcp\Server\InitializationState;
use Mcp\Server\ServerSession;
use Mcp\Server\Transport\Transport;
use Mcp\Shared\RequestResponder;
use Mcp\Shared\Version;
use Mcp\Types\ClientCapabilities;
use Mcp\Types\ClientRequest;
use Mcp\Types\Implementation;
use Mcp\Types\InitializeRequest;
use Mcp\Types\InitializeRequestParams;
use Mcp\Types\InitializeResult;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\RequestId;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Mcp\Types\AudioContent;
use Mcp\Types\Annotations;
use Mcp\Shared\ErrorData;
use Mcp\Shared\McpError;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\ListToolsResult;
use Mcp\Types\NotificationParams;
use Mcp\Types\PingRequest;
use Mcp\Types\RequestParams;
use Mcp\Types\Result;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ServerSession initialization and protocol version negotiation.
 *
 * Validates that the server correctly:
 * - Receives 'initialize' requests from clients
 * - Negotiates protocol versions based on client capabilities
 * - Responds with InitializeResult containing negotiated version
 * - Transitions through proper initialization states
 * - Stores client parameters and negotiated protocol version
 * - Handles version downgrade when client requests older supported version
 * - Handles version fallback when client requests unsupported version
 *
 * Critical for ensuring servers can communicate with clients using
 * different protocol versions (backward compatibility).
 */
final class ServerSessionTest extends TestCase
{
    /**
     * Test that server negotiates to an older protocol version when requested.
     *
     * Protocol version negotiation scenario:
     * - Client requests: '2024-11-05' (older but supported version)
     * - Server prefers: '2025-03-26' (latest version)
     * - Expected result: Server should accept '2024-11-05' (common ground)
     *
     * This validates backward compatibility - servers must support clients
     * running older SDK versions.
     *
     * Corresponds to ServerSession.php:268-269 (supported version branch)
     */
    public function testInitializeNegotiatesCommonProtocolVersion(): void
    {
        // Arrange: Create server session with in-memory transport
        $transport = new InMemoryTransport();
        $session = $this->createSession($transport);

        // Use the first (oldest) supported protocol version for this test
        // SUPPORTED_PROTOCOL_VERSIONS = ['2024-11-05', '2025-03-26']
        $clientRequestedVersion = Version::SUPPORTED_PROTOCOL_VERSIONS[0];

        // Verify test assumptions: ensure we're actually testing version downgrade
        $this->assertNotSame(
            Version::LATEST_PROTOCOL_VERSION,
            $clientRequestedVersion,
            'Test setup assumes an older supported protocol exists'
        );

        // Create a mock initialize request from the client
        $request = $this->createInitializeClientRequest($clientRequestedVersion);

        // Create the responder context (simulates BaseSession's request handling)
        $responder = new RequestResponder(
            requestId: new RequestId(7),
            params: [
                'protocolVersion' => $clientRequestedVersion,
                'capabilities' => [],
                'clientInfo' => [
                    'name' => 'test-client',
                    'version' => '0.1.0',
                ],
            ],
            request: $request,
            session: $session
        );

        // Act: Handle the initialize request
        $session->handleRequest($responder);

        // Assert: Verify server sent exactly one response
        $written = $transport->writtenMessages;
        $this->assertCount(1, $written, 'Server should respond exactly once');

        // Verify response structure and type
        $responseMessage = $written[0];
        $this->assertInstanceOf(JsonRpcMessage::class, $responseMessage);
        $this->assertInstanceOf(JSONRPCResponse::class, $responseMessage->message);
        $this->assertInstanceOf(InitializeResult::class, $responseMessage->message->result);

        // Verify the negotiated protocol version in the response
        $this->assertSame(
            $clientRequestedVersion,
            $responseMessage->message->result->protocolVersion,
            'Server response should reflect negotiated protocol (client requested version)'
        );

        // Verify internal state: negotiatedProtocolVersion is stored correctly
        $this->assertSame(
            $clientRequestedVersion,
            $this->readProperty($session, 'negotiatedProtocolVersion'),
            'Server should store the negotiated protocol version'
        );

        // Verify state transition: should be in Initialized state
        $this->assertSame(
            InitializationState::Initialized,
            $this->readProperty($session, 'initializationState'),
            'Server should transition to Initialized state after handling initialize request'
        );
    }

    /**
     * Test that server accepts the latest protocol version when requested.
     *
     * Protocol version negotiation scenario:
     * - Client requests: '2025-03-26' (latest version)
     * - Server prefers: '2025-03-26' (latest version)
     * - Expected result: Server should accept '2025-03-26' (perfect match)
     *
     * This is the happy path where client and server are both on the latest version.
     *
     * Corresponds to ServerSession.php:263-264 (latest version branch)
     */
    public function testInitializeAcceptsLatestProtocolVersion(): void
    {
        // Arrange: Create server session
        $transport = new InMemoryTransport();
        $session = $this->createSession($transport);

        // Client requests the latest protocol version
        $clientRequestedVersion = Version::LATEST_PROTOCOL_VERSION;

        // Create mock initialize request
        $request = $this->createInitializeClientRequest($clientRequestedVersion);

        $responder = new RequestResponder(
            requestId: new RequestId(1),
            params: [
                'protocolVersion' => $clientRequestedVersion,
                'capabilities' => [],
                'clientInfo' => [
                    'name' => 'modern-client',
                    'version' => '2.0.0',
                ],
            ],
            request: $request,
            session: $session
        );

        // Act: Handle the initialize request
        $session->handleRequest($responder);

        // Assert: Verify server responds with latest version
        $written = $transport->writtenMessages;
        $this->assertCount(1, $written, 'Server should respond exactly once');

        $responseMessage = $written[0];
        $this->assertInstanceOf(InitializeResult::class, $responseMessage->message->result);

        $this->assertSame(
            Version::LATEST_PROTOCOL_VERSION,
            $responseMessage->message->result->protocolVersion,
            'Server should accept and return latest protocol version'
        );

        // Verify internal state
        $this->assertSame(
            Version::LATEST_PROTOCOL_VERSION,
            $this->readProperty($session, 'negotiatedProtocolVersion'),
            'Server should store latest protocol version'
        );

        $this->assertSame(
            InitializationState::Initialized,
            $this->readProperty($session, 'initializationState'),
            'Server should transition to Initialized state'
        );
    }

    /**
     * Test that server falls back to latest when client requests unsupported version.
     *
     * Protocol version negotiation scenario:
     * - Client requests: '2099-99-99' (unsupported future version)
     * - Server supports: ['2024-11-05', '2025-03-26']
     * - Expected result: Server should fallback to '2025-03-26' (latest supported)
     *
     * This validates that servers can handle clients requesting unknown/future
     * protocol versions by falling back to the latest version the server supports.
     *
     * Corresponds to ServerSession.php:272-276 (unsupported version fallback)
     */
    public function testInitializeFallsBackToLatestForUnsupportedVersion(): void
    {
        // Arrange: Create server session
        $transport = new InMemoryTransport();
        $session = $this->createSession($transport);

        // Client requests an unsupported (future) protocol version
        $clientRequestedVersion = '2099-99-99';

        // Verify test assumptions: version is actually unsupported
        $this->assertNotContains(
            $clientRequestedVersion,
            Version::SUPPORTED_PROTOCOL_VERSIONS,
            'Test setup assumes version is not in supported list'
        );

        // Create mock initialize request with unsupported version
        $request = $this->createInitializeClientRequest($clientRequestedVersion);

        $responder = new RequestResponder(
            requestId: new RequestId(2),
            params: [
                'protocolVersion' => $clientRequestedVersion,
                'capabilities' => [],
                'clientInfo' => [
                    'name' => 'future-client',
                    'version' => '99.0.0',
                ],
            ],
            request: $request,
            session: $session
        );

        // Act: Handle the initialize request
        $session->handleRequest($responder);

        // Assert: Verify server falls back to latest supported version
        $written = $transport->writtenMessages;
        $this->assertCount(1, $written, 'Server should respond exactly once');

        $responseMessage = $written[0];
        $this->assertInstanceOf(InitializeResult::class, $responseMessage->message->result);

        $this->assertSame(
            Version::LATEST_PROTOCOL_VERSION,
            $responseMessage->message->result->protocolVersion,
            'Server should fallback to latest supported version for unsupported client version'
        );

        // Verify internal state uses fallback version
        $this->assertSame(
            Version::LATEST_PROTOCOL_VERSION,
            $this->readProperty($session, 'negotiatedProtocolVersion'),
            'Server should store latest protocol version as fallback'
        );

        $this->assertSame(
            InitializationState::Initialized,
            $this->readProperty($session, 'initializationState'),
            'Server should transition to Initialized state even with version fallback'
        );
    }

    /**
     * Test that server correctly populates InitializeResult with server info.
     *
     * Verifies that the InitializeResult response contains:
     * - Negotiated protocol version
     * - Server capabilities (from InitializationOptions)
     * - Server name and version (from InitializationOptions)
     *
     * Corresponds to ServerSession.php:243-250 (InitializeResult construction)
     */
    public function testInitializeReturnsCorrectServerInfo(): void
    {
        // Arrange: Create server session with specific server info
        $transport = new InMemoryTransport();
        $session = $this->createSession($transport);

        $clientRequestedVersion = Version::LATEST_PROTOCOL_VERSION;
        $request = $this->createInitializeClientRequest($clientRequestedVersion);

        $responder = new RequestResponder(
            requestId: new RequestId(3),
            params: [
                'protocolVersion' => $clientRequestedVersion,
                'capabilities' => [],
                'clientInfo' => [
                    'name' => 'test-client',
                    'version' => '1.0.0',
                ],
            ],
            request: $request,
            session: $session
        );

        // Act: Handle the initialize request
        $session->handleRequest($responder);

        // Assert: Verify InitializeResult contains correct server info
        $written = $transport->writtenMessages;
        $responseMessage = $written[0];

        /** @var InitializeResult $result */
        $result = $responseMessage->message->result;

        $this->assertSame(
            'test-server',
            $result->serverInfo->name,
            'InitializeResult should contain server name from InitializationOptions'
        );

        $this->assertSame(
            '1.0.0',
            $result->serverInfo->version,
            'InitializeResult should contain server version from InitializationOptions'
        );

        $this->assertInstanceOf(
            ServerCapabilities::class,
            $result->capabilities,
            'InitializeResult should contain ServerCapabilities from InitializationOptions'
        );
    }

    /**
     * Test that initialize requests are properly routed through BaseSession message handling.
     *
     * Integration test flow:
     * 1. Create raw JSON-RPC initialize request message
     * 2. Feed message through BaseSession::handleIncomingMessage() (not direct handleRequest)
     * 3. Verify message is validated, routed, and processed correctly
     * 4. Confirm initialization state transitions occur
     * 5. Validate negotiated protocol version is stored
     *
     * This is critical because it tests the ACTUAL message intake path that production
     * code uses, unlike unit tests that call handleRequest() directly and bypass
     * BaseSession's JSON-RPC validation, parameter extraction, and routing logic.
     *
     * Without this test, bugs in BaseSession::handleIncomingMessage() (lines 250-320)
     * could break initialization without being detected by other tests.
     *
     * Corresponds to BaseSession.php:250-320 (handleIncomingMessage) and
     * ServerSession.php:231-254 (handleInitialize)
     */
    public function testIncomingInitializeMessageFlowsThroughBaseSession(): void
    {
        $transport = new InMemoryTransport();
        $options = new InitializationOptions(
            serverName: 'test-server',
            serverVersion: '1.0.0',
            capabilities: new ServerCapabilities()
        );
        $session = new TestableServerSession($transport, $options);

        $initialize = new JsonRpcMessage(
            new JSONRPCRequest(
                jsonrpc: '2.0',
                id: new RequestId(9),
                method: 'initialize',
                params: new RawInitializeParams(
                    protocolVersion: Version::LATEST_PROTOCOL_VERSION,
                    capabilities: ['roots' => null],
                    clientInfo: ['name' => 'test-client', 'version' => '1.2.3']
                )
            )
        );

        $session->processIncoming($initialize);

        $this->assertCount(1, $transport->writtenMessages, 'Initialize should produce a response via BaseSession routing');
        $this->assertSame(
            InitializationState::Initialized,
            $this->readProperty($session, 'initializationState'),
            'Initialization should advance state through BaseSession path'
        );
        $this->assertSame(
            Version::LATEST_PROTOCOL_VERSION,
            $this->readProperty($session, 'negotiatedProtocolVersion'),
            'Negotiated version should be recorded after BaseSession dispatch'
        );
    }

    /**
     * Test that server adapts responses for clients using older protocol versions.
     *
     * Protocol adaptation flow:
     * 1. Client negotiates to older protocol version (2024-11-05)
     * 2. Server generates response with new features (AudioContent, Annotations)
     * 3. Server's writeMessage() detects older protocol and adapts response
     * 4. AudioContent is stripped (not supported in older version)
     * 5. Annotations are removed from TextContent (not supported in older version)
     * 6. Adapted response is sent to client
     *
     * This ensures backward compatibility - servers can talk to clients running
     * older SDK versions without sending features the client can't understand.
     *
     * Example: A client on 2024-11-05 calls a tool that returns AudioContent.
     * The server must remove AudioContent from the response since the client
     * doesn't know how to handle it.
     *
     * Corresponds to ServerSession.php:442-539 (adaptResponseForClient methods)
     * and ServerSession.php:587-607 (writeMessage adaptation logic)
     */
    public function testResponsesAdaptedForOlderProtocolVersion(): void
    {
        $transport = new InMemoryTransport();
        $options = new InitializationOptions(
            serverName: 'test-server',
            serverVersion: '1.0.0',
            capabilities: new ServerCapabilities()
        );
        $session = new TestableServerSession($transport, $options);

        $initialize = new JsonRpcMessage(
            new JSONRPCRequest(
                jsonrpc: '2.0',
                id: new RequestId(4),
                method: 'initialize',
                params: new RawInitializeParams(
                    protocolVersion: Version::SUPPORTED_PROTOCOL_VERSIONS[0],
                    capabilities: ['roots' => null],
                    clientInfo: ['name' => 'legacy', 'version' => '0.1.0']
                )
            )
        );

        $session->processIncoming($initialize);

        $callResult = new CallToolResult([
            new AudioContent('abc', 'audio/wav'),
            new TextContent('hello', new Annotations(priority: 0.5)),
        ]);

        $session->sendResponseForTest(
            new JsonRpcMessage(
                new JSONRPCResponse(
                    jsonrpc: '2.0',
                    id: new RequestId(10),
                    result: $callResult
                )
            )
        );

        $this->assertCount(2, $transport->writtenMessages, 'Initialize and adapted response should be written');
        $adapted = $transport->writtenMessages[1]->message->result;
        $this->assertInstanceOf(CallToolResult::class, $adapted);
        $this->assertCount(1, $adapted->content, 'Audio content should be stripped for older protocol');
        $this->assertInstanceOf(TextContent::class, $adapted->content[0]);
        $this->assertNull($adapted->content[0]->annotations, 'Annotations should be removed for older protocol');
        $this->assertSame('hello', $adapted->content[0]->text, 'Existing text content should be preserved');
    }

    /**
     * Test that registered notification handlers are dispatched through the session path.
     *
     * Verifies the fix for a bug where ServerSession::handleNotification() special-cased
     * notifications/initialized but silently dropped all other notifications without
     * consulting $this->methodNotificationHandlers. This meant notification handlers
     * registered via registerNotificationHandlers() were never invoked at runtime.
     *
     * Integration test flow:
     * 1. Initialize session via BaseSession message path
     * 2. Register a notification handler on the session
     * 3. Send a notifications/roots/list_changed through BaseSession::handleIncomingMessage()
     * 4. Verify the handler was invoked with the expected params
     *
     * Uses notifications/roots/list_changed (a parameterless notification) to test
     * the dispatch path cleanly without depending on notification type internals.
     *
     * Corresponds to ServerSession.php:229-258 (handleNotification method)
     */
    public function testRegisteredNotificationHandlersAreDispatched(): void
    {
        $transport = new InMemoryTransport();
        $options = new InitializationOptions(
            serverName: 'test-server',
            serverVersion: '1.0.0',
            capabilities: new ServerCapabilities()
        );
        $session = new TestableServerSession($transport, $options);

        // Step 1: Initialize the session
        $initialize = new JsonRpcMessage(
            new JSONRPCRequest(
                jsonrpc: '2.0',
                id: new RequestId(1),
                method: 'initialize',
                params: new RawInitializeParams(
                    protocolVersion: Version::LATEST_PROTOCOL_VERSION,
                    capabilities: [],
                    clientInfo: ['name' => 'test-client', 'version' => '1.0.0']
                )
            )
        );
        $session->processIncoming($initialize);

        // Step 2: Register a notification handler
        $handlerInvoked = false;
        $capturedParams = null;
        $session->registerNotificationHandlers([
            'notifications/roots/list_changed' => function ($params) use (&$handlerInvoked, &$capturedParams): void {
                $handlerInvoked = true;
                $capturedParams = $params;
            },
        ]);

        // Step 3: Send the notifications/initialized to complete initialization
        $initialized = new JsonRpcMessage(
            new JSONRPCNotification(
                jsonrpc: '2.0',
                method: 'notifications/initialized',
                params: null
            )
        );
        $session->processIncoming($initialized);

        // Step 4: Send a notifications/roots/list_changed through the base session path
        $rootsChanged = new JsonRpcMessage(
            new JSONRPCNotification(
                jsonrpc: '2.0',
                method: 'notifications/roots/list_changed',
                params: null
            )
        );
        $session->processIncoming($rootsChanged);

        // Assert: Handler was invoked
        $this->assertTrue($handlerInvoked, 'Registered notification handler should be dispatched');
    }

    /**
     * Test that notification handlers for notifications/progress receive params.
     *
     * Unlike some notification types (e.g. CancelledNotification) that store data
     * as direct properties, ProgressNotification passes ProgressNotificationParams
     * to its parent Notification::$params. This test verifies that param-carrying
     * notification types deliver their params to handlers correctly.
     *
     * Corresponds to ServerSession.php:229-258 (handleNotification method)
     */
    public function testNotificationHandlerReceivesParams(): void
    {
        $transport = new InMemoryTransport();
        $options = new InitializationOptions(
            serverName: 'test-server',
            serverVersion: '1.0.0',
            capabilities: new ServerCapabilities()
        );
        $session = new TestableServerSession($transport, $options);

        // Initialize session
        $session->processIncoming(new JsonRpcMessage(
            new JSONRPCRequest(
                jsonrpc: '2.0',
                id: new RequestId(1),
                method: 'initialize',
                params: new RawInitializeParams(
                    protocolVersion: Version::LATEST_PROTOCOL_VERSION,
                    capabilities: [],
                    clientInfo: ['name' => 'test-client', 'version' => '1.0.0']
                )
            )
        ));
        $session->processIncoming(new JsonRpcMessage(
            new JSONRPCNotification(jsonrpc: '2.0', method: 'notifications/initialized', params: null)
        ));

        // Register handler for progress notifications
        $capturedParams = null;
        $session->registerNotificationHandlers([
            'notifications/progress' => function ($params) use (&$capturedParams): void {
                $capturedParams = $params;
            },
        ]);

        // Send a progress notification with params
        $progressParams = new NotificationParams();
        $progressParams->progressToken = 'tok-1';
        $progressParams->progress = 0.5;

        $session->processIncoming(new JsonRpcMessage(
            new JSONRPCNotification(
                jsonrpc: '2.0',
                method: 'notifications/progress',
                params: $progressParams
            )
        ));

        // Assert: Handler received the ProgressNotificationParams
        $this->assertNotNull($capturedParams, 'Progress notification handler should receive params');
    }

    /**
     * Test that notification handlers for notifications/cancelled receive synthesized params.
     *
     * CancelledNotification stores its payload as direct properties rather than in
     * Notification::$params, so the session normalizes it back into NotificationParams
     * before invoking registered handlers. This preserves the existing callback shape
     * while keeping the wire payload available to handlers.
     *
     * Corresponds to ServerSession.php:247-275 (notification dispatch and param normalization)
     */
    public function testCancelledNotificationHandlerReceivesSynthesizedParams(): void
    {
        $transport = new InMemoryTransport();
        $options = new InitializationOptions(
            serverName: 'test-server',
            serverVersion: '1.0.0',
            capabilities: new ServerCapabilities()
        );
        $session = new TestableServerSession($transport, $options);

        $session->processIncoming(new JsonRpcMessage(
            new JSONRPCRequest(
                jsonrpc: '2.0',
                id: new RequestId(1),
                method: 'initialize',
                params: new RawInitializeParams(
                    protocolVersion: Version::LATEST_PROTOCOL_VERSION,
                    capabilities: [],
                    clientInfo: ['name' => 'test-client', 'version' => '1.0.0']
                )
            )
        ));
        $session->processIncoming(new JsonRpcMessage(
            new JSONRPCNotification(jsonrpc: '2.0', method: 'notifications/initialized', params: null)
        ));

        $capturedParams = null;
        $session->registerNotificationHandlers([
            'notifications/cancelled' => function ($params) use (&$capturedParams): void {
                $capturedParams = $params;
            },
        ]);

        $cancelledParams = new NotificationParams();
        $cancelledParams->requestId = 'req-42';
        $cancelledParams->reason = 'test cancellation';

        $session->processIncoming(new JsonRpcMessage(
            new JSONRPCNotification(
                jsonrpc: '2.0',
                method: 'notifications/cancelled',
                params: $cancelledParams
            )
        ));

        $this->assertInstanceOf(
            NotificationParams::class,
            $capturedParams,
            'Cancelled notification handler should receive synthesized params'
        );
        $this->assertSame(
            'req-42',
            $capturedParams->requestId,
            'Synthesized params should preserve the requestId from the wire payload'
        );
        $this->assertSame(
            'test cancellation',
            $capturedParams->reason,
            'Synthesized params should preserve the reason from the wire payload'
        );
    }

    /**
     * Test that extra wire fields on notifications/cancelled survive param normalization.
     *
     * The MCP spec allows unofficial extension fields on notification params for forward
     * compatibility. ClientNotification::createCancelledNotification() preserves extra
     * fields via ExtraFieldsTrait, and getHandlerNotificationParams() must carry them
     * into the synthesized NotificationParams so handlers see the full wire payload.
     *
     * Corresponds to ServerSession.php:276-281 (extra field forwarding)
     */
    public function testCancelledNotificationPreservesExtraFields(): void
    {
        $transport = new InMemoryTransport();
        $options = new InitializationOptions(
            serverName: 'test-server',
            serverVersion: '1.0.0',
            capabilities: new ServerCapabilities()
        );
        $session = new TestableServerSession($transport, $options);

        $session->processIncoming(new JsonRpcMessage(
            new JSONRPCRequest(
                jsonrpc: '2.0',
                id: new RequestId(1),
                method: 'initialize',
                params: new RawInitializeParams(
                    protocolVersion: Version::LATEST_PROTOCOL_VERSION,
                    capabilities: [],
                    clientInfo: ['name' => 'test-client', 'version' => '1.0.0']
                )
            )
        ));
        $session->processIncoming(new JsonRpcMessage(
            new JSONRPCNotification(jsonrpc: '2.0', method: 'notifications/initialized', params: null)
        ));

        $capturedParams = null;
        $session->registerNotificationHandlers([
            'notifications/cancelled' => function ($params) use (&$capturedParams): void {
                $capturedParams = $params;
            },
        ]);

        // Include an extra field beyond requestId and reason
        $cancelledParams = new NotificationParams();
        $cancelledParams->requestId = 'req-99';
        $cancelledParams->reason = 'timeout';
        $cancelledParams->customExtension = 'extra-value';

        $session->processIncoming(new JsonRpcMessage(
            new JSONRPCNotification(
                jsonrpc: '2.0',
                method: 'notifications/cancelled',
                params: $cancelledParams
            )
        ));

        $this->assertInstanceOf(NotificationParams::class, $capturedParams);
        $this->assertSame('req-99', $capturedParams->requestId);
        $this->assertSame('timeout', $capturedParams->reason);
        $this->assertSame(
            'extra-value',
            $capturedParams->customExtension,
            'Extra wire fields should be preserved through param normalization'
        );
    }

    /**
     * Test that handler exceptions produce a JSON-RPC error response instead of being silently swallowed.
     *
     * Before this fix, a handler that threw an exception would only log the error,
     * leaving the client without any response (causing hangs on stdio or timeouts on HTTP).
     *
     * Corresponds to ServerSession.php handleRequest() catch block.
     */
    public function testHandlerExceptionReturnsJsonRpcError(): void
    {
        $transport = new InMemoryTransport();
        $session = $this->createInitializedSession($transport);

        // Register a handler that throws
        $session->registerHandlers([
            'tools/list' => function ($params) {
                throw new \RuntimeException('Something went wrong in the handler');
            },
        ]);

        $request = new ClientRequest(new \Mcp\Types\ListToolsRequest());
        $responder = new RequestResponder(
            requestId: new RequestId(10),
            params: [],
            request: $request,
            session: $session
        );

        $session->handleRequest($responder);

        $this->assertCount(1, $transport->writtenMessages, 'Server should send exactly one error response');

        $response = $transport->writtenMessages[0];
        $this->assertInstanceOf(JSONRPCError::class, $response->message);
        $this->assertSame(-32603, $response->message->error->code, 'Should use JSON-RPC Internal Error code');
        $this->assertSame('Something went wrong in the handler', $response->message->error->message);
    }

    /**
     * Test that McpError exceptions preserve their original error code, message, and data.
     *
     * Handlers use McpError/McpServerException to throw specific JSON-RPC errors
     * (e.g. -32602 for unknown tool). These must pass through unchanged rather than
     * being flattened to -32603 Internal Error.
     *
     * Corresponds to ServerSession.php handleRequest() catch (McpError) branch.
     */
    public function testMcpErrorPreservesOriginalErrorData(): void
    {
        $transport = new InMemoryTransport();
        $session = $this->createInitializedSession($transport);

        $session->registerHandlers([
            'tools/list' => function ($params) {
                throw new McpError(new ErrorData(
                    code: -32602,
                    message: 'Unknown tool: nonexistent',
                    data: ['toolName' => 'nonexistent']
                ));
            },
        ]);

        $request = new ClientRequest(new \Mcp\Types\ListToolsRequest());
        $responder = new RequestResponder(
            requestId: new RequestId(13),
            params: [],
            request: $request,
            session: $session
        );

        $session->handleRequest($responder);

        $this->assertCount(1, $transport->writtenMessages, 'Server should send exactly one error response');

        $response = $transport->writtenMessages[0];
        $this->assertInstanceOf(JSONRPCError::class, $response->message);
        $this->assertSame(-32602, $response->message->error->code, 'McpError code should be preserved, not flattened to -32603');
        $this->assertSame('Unknown tool: nonexistent', $response->message->error->message);
        $this->assertSame(['toolName' => 'nonexistent'], $response->message->error->data, 'McpError data should be preserved');
    }

    /**
     * Test that requests for unregistered methods produce a Method Not Found error response.
     *
     * Before this fix, an unregistered method would only log a message,
     * leaving the client without any response.
     *
     * Corresponds to ServerSession.php handleRequest() else branch.
     */
    public function testUnregisteredMethodReturnsMethodNotFoundError(): void
    {
        $transport = new InMemoryTransport();
        $session = $this->createInitializedSession($transport);

        // Don't register any handlers — use ping which requires no params
        $request = new ClientRequest(new PingRequest());
        $responder = new RequestResponder(
            requestId: new RequestId(11),
            params: [],
            request: $request,
            session: $session
        );

        $session->handleRequest($responder);

        $this->assertCount(1, $transport->writtenMessages, 'Server should send exactly one error response');

        $response = $transport->writtenMessages[0];
        $this->assertInstanceOf(JSONRPCError::class, $response->message);
        $this->assertSame(-32601, $response->message->error->code, 'Should use JSON-RPC Method Not Found code');
        $this->assertStringContainsString('Method not found', $response->message->error->message);
    }

    /**
     * Test that a successful handler still works correctly after the error handling fix.
     *
     * Ensures the fix doesn't break the happy path.
     */
    public function testSuccessfulHandlerStillReturnsResult(): void
    {
        $transport = new InMemoryTransport();
        $session = $this->createInitializedSession($transport);

        $session->registerHandlers([
            'tools/list' => function ($params) {
                return new ListToolsResult([]);
            },
        ]);

        $request = new ClientRequest(new \Mcp\Types\ListToolsRequest());
        $responder = new RequestResponder(
            requestId: new RequestId(12),
            params: [],
            request: $request,
            session: $session
        );

        $session->handleRequest($responder);

        $this->assertCount(1, $transport->writtenMessages, 'Server should send exactly one response');

        $response = $transport->writtenMessages[0];
        $this->assertInstanceOf(JSONRPCResponse::class, $response->message);
        $this->assertInstanceOf(ListToolsResult::class, $response->message->result);
    }

    /**
     * Create a ServerSession that is already in the Initialized state.
     *
     * Uses reflection to bypass the initialize handshake, allowing tests
     * to focus on post-initialization handler behavior.
     */
    private function createInitializedSession(InMemoryTransport $transport): ServerSession
    {
        $session = $this->createSession($transport);

        // Force into Initialized state via reflection
        $reflection = new \ReflectionProperty($session, 'initializationState');
        $reflection->setAccessible(true);
        $reflection->setValue($session, InitializationState::Initialized);

        return $session;
    }

    /**
     * Create a test ServerSession with standard initialization options.
     *
     * @param InMemoryTransport $transport The transport to use for the session
     * @return ServerSession The configured server session
     */
    private function createSession(InMemoryTransport $transport): ServerSession
    {
        $options = new InitializationOptions(
            serverName: 'test-server',
            serverVersion: '1.0.0',
            capabilities: new ServerCapabilities()
        );

        return new ServerSession($transport, $options);
    }

    /**
     * Create a mock initialize request from a client.
     *
     * @param string $protocolVersion The protocol version the client requests
     * @return ClientRequest The mock client request
     */
    private function createInitializeClientRequest(string $protocolVersion): ClientRequest
    {
        $params = new InitializeRequestParams(
            protocolVersion: $protocolVersion,
            capabilities: new ClientCapabilities(),
            clientInfo: new Implementation('test-client', '0.1.0')
        );

        return new ClientRequest(new InitializeRequest($params));
    }

    /**
     * Use reflection to read private/protected properties for testing.
     *
     * @param object $object The object to read from
     * @param string $property The property name to read
     * @return mixed The property value
     */
    private function readProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        return $reflection->getValue($object);
    }
}

/**
 * In-memory transport implementation for testing ServerSession.
 *
 * This mock transport captures all messages written by the ServerSession
 * so tests can inspect the exact responses sent to clients.
 *
 * Unlike MemoryStream (used for client tests), this implements the
 * Transport interface which ServerSession requires.
 */
final class InMemoryTransport implements Transport
{
    /** @var JsonRpcMessage[] Messages written by ServerSession */
    public array $writtenMessages = [];

    /**
     * No-op start method (required by Transport interface).
     */
    public function start(): void
    {
        // No-op for tests
    }

    /**
     * No-op stop method (required by Transport interface).
     */
    public function stop(): void
    {
        // No-op for tests
    }

    /**
     * Returns null - tests don't simulate incoming messages via transport.
     * Instead, tests call handleRequest() directly.
     */
    public function readMessage(): ?JsonRpcMessage
    {
        return null;
    }

    /**
     * Capture messages written by ServerSession for inspection.
     *
     * @param JsonRpcMessage $message The message to write
     */
    public function writeMessage(JsonRpcMessage $message): void
    {
        $this->writtenMessages[] = $message;
    }
}

/**
 * Testable ServerSession that exposes BaseSession's protected methods for integration testing.
 *
 * This test double extends ServerSession to expose handleIncomingMessage() and writeMessage()
 * as public methods, allowing tests to:
 * - Feed raw JSON-RPC messages through the complete BaseSession routing path
 * - Simulate server responses without going through actual transport layers
 * - Test the full message flow: JSON-RPC → validation → dispatch → handler → response
 *
 * Unlike unit tests that call handleRequest() directly (bypassing BaseSession),
 * this enables integration tests that exercise the actual production code path.
 *
 * Usage:
 * ```php
 * $session = new TestableServerSession($transport, $options);
 * $session->processIncoming($jsonRpcMessage); // Routes through BaseSession
 * ```
 */
final class TestableServerSession extends ServerSession
{
    public function processIncoming(JsonRpcMessage $message): void
    {
        $this->handleIncomingMessage($message);
    }

    public function sendResponseForTest(JsonRpcMessage $message): void
    {
        $this->writeMessage($message);
    }
}

/**
 * Raw initialize parameters that serialize to wire-format arrays instead of typed objects.
 *
 * This test helper differs from InitializeRequestParams by serializing to plain arrays
 * rather than nested objects. This is necessary for integration tests that need to
 * simulate actual JSON-RPC messages as they arrive over the wire.
 *
 * When JSON is decoded, params arrive as arrays (e.g., ['protocolVersion' => '2025-03-26']),
 * not as typed InitializeRequestParams objects. This class replicates that wire format.
 *
 * Without this, integration tests would use typed params that skip BaseSession's
 * parameter extraction and validation logic (lines 273-283).
 *
 * Usage:
 * ```php
 * $params = new RawInitializeParams(
 *     protocolVersion: '2025-03-26',
 *     capabilities: ['roots' => null],
 *     clientInfo: ['name' => 'test', 'version' => '1.0']
 * );
 * // Serializes to: ['protocolVersion' => '2025-03-26', 'capabilities' => [...], ...]
 * ```
 */
final class RawInitializeParams extends RequestParams
{
    public function __construct(
        private readonly string $protocolVersion,
        private readonly array $capabilities = [],
        private readonly array $clientInfo
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
