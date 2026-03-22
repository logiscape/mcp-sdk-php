<?php

declare(strict_types=1);

namespace Mcp\Tests\Client;

use PHPUnit\Framework\TestCase;
use Mcp\Client\ClientSession;
use Mcp\Shared\MemoryStream;
use Mcp\Shared\Version;
use Mcp\Types\InitializeResult;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\Implementation;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\RequestId;
use Mcp\Types\LoggingLevel;
use Mcp\Types\PromptReference;
use Mcp\Types\ResourceReference;
use Mcp\Types\ProgressToken;

/**
 * Tests for all RPC methods on ClientSession.
 *
 * Each test creates a restored session (skipping the initialization handshake),
 * preloads a canned response on the read stream, calls the RPC method, and then
 * inspects the write stream to verify the correct JSON-RPC request was sent.
 */
final class ClientSessionRpcMethodsTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function createInitResult(): InitializeResult
    {
        return new InitializeResult(
            capabilities: new ServerCapabilities(),
            serverInfo: new Implementation(name: 'test-server', version: '1.0.0'),
            protocolVersion: Version::LATEST_PROTOCOL_VERSION
        );
    }

    private function createRestoredSession(
        MemoryStream $readStream,
        MemoryStream $writeStream,
        int $nextRequestId = 0,
        ?string $protocolVersion = null
    ): ClientSession {
        return ClientSession::createRestored(
            readStream: $readStream,
            writeStream: $writeStream,
            initResult: $this->createInitResult(),
            negotiatedProtocolVersion: $protocolVersion ?? Version::LATEST_PROTOCOL_VERSION,
            nextRequestId: $nextRequestId,
            readTimeout: 2.0
        );
    }

    private function preloadResponse(MemoryStream $readStream, int $requestId, array $resultData = []): void
    {
        $readStream->send(new JsonRpcMessage(
            new JSONRPCResponse(
                jsonrpc: '2.0',
                id: new RequestId($requestId),
                result: $resultData
            )
        ));
    }

    /**
     * Decode a sent message from the write stream into an associative array.
     *
     * @return array<string, mixed>
     */
    private function decodeSentMessage(MemoryStream $writeStream): array
    {
        $msg = $writeStream->receive();
        $this->assertInstanceOf(JsonRpcMessage::class, $msg);
        return json_decode(json_encode($msg), true);
    }

    // -----------------------------------------------------------------------
    // 1. sendPing
    // -----------------------------------------------------------------------

    /**
     * Verify that sendPing() emits a JSON-RPC request with method "ping"
     * and returns an EmptyResult.
     */
    public function testSendPingWritesCorrectRequest(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $this->preloadResponse($readStream, 0);

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $result  = $session->sendPing();

        $sent = $this->decodeSentMessage($writeStream);
        $this->assertSame('ping', $sent['method']);
        $this->assertSame(0, $sent['id']);
        $this->assertInstanceOf(\Mcp\Types\EmptyResult::class, $result);
    }

    // -----------------------------------------------------------------------
    // 2. sendPing before initialization
    // -----------------------------------------------------------------------

    /**
     * Verify that calling sendPing() on an un-initialized session throws
     * a RuntimeException with an appropriate message.
     */
    public function testSendPingFailsBeforeInitialization(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        // Construct a raw (non-restored) session — not initialized
        $session = new ClientSession($readStream, $writeStream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not initialized');
        $session->sendPing();
    }

    // -----------------------------------------------------------------------
    // 3. callTool – request shape
    // -----------------------------------------------------------------------

    /**
     * Verify that callTool() emits a "tools/call" request with the correct
     * tool name and arguments in the params.
     */
    public function testCallToolWritesCorrectRequest(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $this->preloadResponse($readStream, 0, [
            'content' => [['type' => 'text', 'text' => 'result']],
            'isError' => false,
        ]);

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $session->callTool('my-tool', ['arg1' => 'val1']);

        $sent = $this->decodeSentMessage($writeStream);
        $this->assertSame('tools/call', $sent['method']);
        $this->assertSame('my-tool', $sent['params']['name']);
        $this->assertSame(['arg1' => 'val1'], $sent['params']['arguments']);
    }

    // -----------------------------------------------------------------------
    // 4. callTool – return type
    // -----------------------------------------------------------------------

    /**
     * Verify that callTool() returns a CallToolResult whose content matches
     * the preloaded response data.
     */
    public function testCallToolReturnsCallToolResult(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $this->preloadResponse($readStream, 0, [
            'content' => [['type' => 'text', 'text' => 'hello']],
            'isError' => false,
        ]);

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $result  = $session->callTool('my-tool', ['x' => '1']);

        $this->assertInstanceOf(\Mcp\Types\CallToolResult::class, $result);
        $this->assertCount(1, $result->content);
    }

    // -----------------------------------------------------------------------
    // 5. listTools – request shape
    // -----------------------------------------------------------------------

    /**
     * Verify that listTools() emits a "tools/list" request.
     */
    public function testListToolsWritesCorrectRequest(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $this->preloadResponse($readStream, 0, ['tools' => []]);

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $session->listTools();

        $sent = $this->decodeSentMessage($writeStream);
        $this->assertSame('tools/list', $sent['method']);
    }

    // -----------------------------------------------------------------------
    // 6. listTools – return value
    // -----------------------------------------------------------------------

    /**
     * Verify that listTools() returns a ListToolsResult containing the tools
     * described in the preloaded response.
     */
    public function testListToolsReturnsResult(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $this->preloadResponse($readStream, 0, [
            'tools' => [
                ['name' => 'add', 'inputSchema' => ['type' => 'object']],
            ],
        ]);

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $result  = $session->listTools();

        $this->assertInstanceOf(\Mcp\Types\ListToolsResult::class, $result);
        $this->assertCount(1, $result->tools);
    }

    // -----------------------------------------------------------------------
    // 7. listResources – request shape
    // -----------------------------------------------------------------------

    /**
     * Verify that listResources() emits a "resources/list" request.
     */
    public function testListResourcesWritesCorrectRequest(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $this->preloadResponse($readStream, 0, ['resources' => []]);

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $session->listResources();

        $sent = $this->decodeSentMessage($writeStream);
        $this->assertSame('resources/list', $sent['method']);
    }

    // -----------------------------------------------------------------------
    // 8. readResource – request shape
    // -----------------------------------------------------------------------

    /**
     * Verify that readResource() emits a "resources/read" request with the
     * given URI in the params.
     */
    public function testReadResourceWritesCorrectRequest(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $this->preloadResponse($readStream, 0, [
            'contents' => [['uri' => 'file:///test', 'text' => 'content']],
        ]);

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $session->readResource('file:///test');

        $sent = $this->decodeSentMessage($writeStream);
        $this->assertSame('resources/read', $sent['method']);
        $this->assertSame('file:///test', $sent['params']['uri']);
    }

    // -----------------------------------------------------------------------
    // 9. listPrompts – request shape
    // -----------------------------------------------------------------------

    /**
     * Verify that listPrompts() emits a "prompts/list" request.
     */
    public function testListPromptsWritesCorrectRequest(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $this->preloadResponse($readStream, 0, ['prompts' => []]);

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $session->listPrompts();

        $sent = $this->decodeSentMessage($writeStream);
        $this->assertSame('prompts/list', $sent['method']);
    }

    // -----------------------------------------------------------------------
    // 10. getPrompt – request shape
    // -----------------------------------------------------------------------

    /**
     * Verify that getPrompt() emits a "prompts/get" request with the prompt
     * name and arguments.
     */
    public function testGetPromptWritesCorrectRequest(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $this->preloadResponse($readStream, 0, [
            'messages' => [[
                'role'    => 'assistant',
                'content' => ['type' => 'text', 'text' => 'Hi'],
            ]],
        ]);

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $session->getPrompt('greet', ['name' => 'World']);

        $sent = $this->decodeSentMessage($writeStream);
        $this->assertSame('prompts/get', $sent['method']);
        $this->assertSame('greet', $sent['params']['name']);
        $this->assertSame('World', $sent['params']['arguments']['name']);
    }

    // -----------------------------------------------------------------------
    // 11. getPrompt – non-string argument value throws
    // -----------------------------------------------------------------------

    /**
     * Verify that passing a non-string value in the arguments array to
     * getPrompt() throws an InvalidArgumentException, since prompt arguments
     * must be string-valued per the MCP schema.
     */
    public function testGetPromptInvalidArgumentsThrows(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);

        $this->expectException(\InvalidArgumentException::class);
        // PromptArguments constructor enforces string values
        $session->getPrompt('test', ['key' => 123]);
    }

    // -----------------------------------------------------------------------
    // 12. complete – request shape
    // -----------------------------------------------------------------------

    /**
     * Verify that complete() emits a "completion/complete" request with the
     * correct reference and argument payload.
     */
    public function testCompleteWritesCorrectRequest(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $this->preloadResponse($readStream, 0, [
            'completion' => ['values' => ['foo', 'bar']],
        ]);

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $session->complete(
            new ResourceReference(uri: 'test://uri'),
            ['name' => 'arg', 'value' => 'val']
        );

        $sent = $this->decodeSentMessage($writeStream);
        $this->assertSame('completion/complete', $sent['method']);
        $this->assertSame('arg', $sent['params']['argument']['name']);
        $this->assertSame('val', $sent['params']['argument']['value']);
    }

    // -----------------------------------------------------------------------
    // 13. complete – missing name throws
    // -----------------------------------------------------------------------

    /**
     * Verify that calling complete() with an empty "name" in the argument
     * array throws an InvalidArgumentException.
     */
    public function testCompleteMissingNameThrows(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);

        $this->expectException(\InvalidArgumentException::class);
        $session->complete(
            new ResourceReference(uri: 'test://uri'),
            ['name' => '', 'value' => 'val']
        );
    }

    // -----------------------------------------------------------------------
    // 14. complete – missing value throws
    // -----------------------------------------------------------------------

    /**
     * Verify that calling complete() without a "value" key in the argument
     * array throws an InvalidArgumentException.
     */
    public function testCompleteMissingValueThrows(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);

        $this->expectException(\InvalidArgumentException::class);
        $session->complete(
            new ResourceReference(uri: 'test://uri'),
            ['name' => 'arg']
        );
    }

    // -----------------------------------------------------------------------
    // 15. subscribeResource – request shape
    // -----------------------------------------------------------------------

    /**
     * Verify that subscribeResource() emits a "resources/subscribe" request
     * with the correct URI.
     */
    public function testSubscribeResourceWritesCorrectRequest(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $this->preloadResponse($readStream, 0);

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $session->subscribeResource('file:///test');

        $sent = $this->decodeSentMessage($writeStream);
        $this->assertSame('resources/subscribe', $sent['method']);
        $this->assertSame('file:///test', $sent['params']['uri']);
    }

    // -----------------------------------------------------------------------
    // 16. unsubscribeResource – request shape
    // -----------------------------------------------------------------------

    /**
     * Verify that unsubscribeResource() emits a "resources/unsubscribe"
     * request with the correct URI.
     */
    public function testUnsubscribeResourceWritesCorrectRequest(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $this->preloadResponse($readStream, 0);

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $session->unsubscribeResource('file:///test');

        $sent = $this->decodeSentMessage($writeStream);
        $this->assertSame('resources/unsubscribe', $sent['method']);
        $this->assertSame('file:///test', $sent['params']['uri']);
    }

    // -----------------------------------------------------------------------
    // 17. setLoggingLevel – request shape
    // -----------------------------------------------------------------------

    /**
     * Verify that setLoggingLevel() emits a "logging/setLevel" request with
     * the correct level value.
     */
    public function testSetLoggingLevelWritesCorrectRequest(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $this->preloadResponse($readStream, 0);

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $session->setLoggingLevel(LoggingLevel::WARNING);

        $sent = $this->decodeSentMessage($writeStream);
        $this->assertSame('logging/setLevel', $sent['method']);
        $this->assertSame('warning', $sent['params']['level']);
    }

    // -----------------------------------------------------------------------
    // 18. sendProgressNotification – notification shape
    // -----------------------------------------------------------------------

    /**
     * Verify that sendProgressNotification() emits a JSON-RPC notification
     * (no "id" field) with method "notifications/progress" and the correct
     * progress token, progress, and total values.
     */
    public function testSendProgressNotificationWritesNotification(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        // Notifications don't need a preloaded response
        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $session->sendProgressNotification(
            new ProgressToken('test-token'),
            progress: 50.0,
            total: 100.0,
            message: 'Halfway there'
        );

        $sent = $this->decodeSentMessage($writeStream);
        $this->assertSame('notifications/progress', $sent['method']);
        $this->assertArrayNotHasKey('id', $sent, 'Notifications must not have an id field');
        $this->assertSame('test-token', $sent['params']['progressToken']);
        $this->assertEquals(50.0, $sent['params']['progress']);
        $this->assertEquals(100.0, $sent['params']['total']);
    }

    // -----------------------------------------------------------------------
    // 19. sendProgressNotification – old protocol omits message
    // -----------------------------------------------------------------------

    /**
     * Verify that when the session was negotiated with the old protocol
     * version "2024-11-05" (which does not support the progress_message
     * feature), the "message" field is omitted from the notification params
     * even when a message is provided by the caller.
     */
    public function testProgressNotificationOmitsMessageForOldProtocol(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $session = $this->createRestoredSession(
            $readStream,
            $writeStream,
            nextRequestId: 0,
            protocolVersion: '2024-11-05'
        );

        $session->sendProgressNotification(
            new ProgressToken('old-token'),
            progress: 1.0,
            total: 10.0,
            message: 'Should be omitted'
        );

        $sent = $this->decodeSentMessage($writeStream);
        $this->assertSame('notifications/progress', $sent['method']);
        $this->assertArrayNotHasKey(
            'message',
            $sent['params'],
            'The message field must be omitted for protocol versions that do not support progress_message'
        );
    }

    // -----------------------------------------------------------------------
    // 20. sendRootsListChanged – notification shape
    // -----------------------------------------------------------------------

    /**
     * Verify that sendRootsListChanged() emits a JSON-RPC notification with
     * method "notifications/rootsListChanged" and no "id" field.
     */
    public function testSendRootsListChangedNotification(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $session->sendRootsListChanged();

        $sent = $this->decodeSentMessage($writeStream);
        $this->assertSame('notifications/roots/list_changed', $sent['method']);
        $this->assertArrayNotHasKey('id', $sent, 'Notifications must not have an id field');
    }

    // -----------------------------------------------------------------------
    // 21. getTask – request shape
    // -----------------------------------------------------------------------

    /**
     * Verify that getTask() emits a "tasks/get" request with the correct
     * taskId in the params.
     */
    public function testGetTaskWritesCorrectRequest(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $this->preloadResponse($readStream, 0, [
            'taskId' => 'task-1',
            'status' => 'working',
        ]);

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $result  = $session->getTask('task-1');

        $sent = $this->decodeSentMessage($writeStream);
        $this->assertSame('tasks/get', $sent['method']);
        $this->assertSame('task-1', $sent['params']['taskId']);
        $this->assertInstanceOf(\Mcp\Types\TaskGetResult::class, $result);
    }

    // -----------------------------------------------------------------------
    // 22. cancelTask – request shape
    // -----------------------------------------------------------------------

    /**
     * Verify that cancelTask() emits a "tasks/cancel" request with the
     * correct taskId in the params.
     */
    public function testCancelTaskWritesCorrectRequest(): void
    {
        $readStream  = new MemoryStream();
        $writeStream = new MemoryStream();

        $this->preloadResponse($readStream, 0, [
            'taskId' => 'task-1',
            'status' => 'cancelled',
        ]);

        $session = $this->createRestoredSession($readStream, $writeStream, nextRequestId: 0);
        $result  = $session->cancelTask('task-1');

        $sent = $this->decodeSentMessage($writeStream);
        $this->assertSame('tasks/cancel', $sent['method']);
        $this->assertSame('task-1', $sent['params']['taskId']);
        $this->assertInstanceOf(\Mcp\Types\TaskGetResult::class, $result);
    }
}
