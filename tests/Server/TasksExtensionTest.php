<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Filename: tests/Server/TasksExtensionTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\Elicitation\ElicitationContext;
use Mcp\Server\HttpServerRunner;
use Mcp\Server\InitializationOptions;
use Mcp\Server\McpServer;
use Mcp\Server\NotificationOptions;
use Mcp\Server\TaskSupport;
use Mcp\Server\ServerSession;
use Mcp\Server\Transport\Http\BufferedIo;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Server\Transport\Transport;
use Mcp\Shared\McpError;
use Mcp\Types\ExtensionIds;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\MetaKeys;
use Mcp\Types\RequestId;
use Mcp\Types\RequestParams;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end coverage of the SEP-2663 Tasks extension over the modern
 * (2026-07-28) Streamable HTTP path: server-directed task creation from
 * tools/call, the flat CreateTaskResult / DetailedTask wire shapes, the
 * in-task input mechanism (tasks/get inputRequests → tasks/update), cancel,
 * capability gating (-32021), the removed tasks/list & tasks/result methods
 * (-32601), and the extension declaration in server/discover.
 */
final class TasksExtensionTest extends TestCase
{
    private function makeRunner(): HttpServerRunner
    {
        $mcp = new McpServer('tasks-test');
        $mcp->enableTasks(sys_get_temp_dir() . '/mcp_tasks_test_' . bin2hex(random_bytes(4)), 60000, 500);

        // A quick task tool that completes synchronously.
        $mcp->tool(
            'slow_compute',
            'Computes a value',
            fn (string $label = 'x'): string => "computed:{$label}",
            taskSupport: TaskSupport::OPTIONAL,
        );

        // A task-required tool: undeclared clients are rejected -32021.
        $mcp->tool(
            'required_job',
            'A job that can only run as a task',
            fn (): string => 'job done',
            taskSupport: TaskSupport::REQUIRED,
        );

        // A task tool whose execution fails as a protocol error → failed.
        $mcp->tool(
            'protocol_error_job',
            'A job that raises a protocol error',
            function (): string {
                throw new McpError(new \Mcp\Shared\ErrorData(code: -32011, message: 'boom'));
            },
            taskSupport: TaskSupport::OPTIONAL,
        );

        // A task tool whose execution fails as a tool error → completed+isError.
        $mcp->tool(
            'failing_job',
            'A job whose tool body throws',
            function (): string {
                throw new \RuntimeException('kaboom');
            },
            taskSupport: TaskSupport::OPTIONAL,
        );

        // A task tool that gathers input mid-flight (in-task input).
        $mcp->tool(
            'confirm_delete',
            'Deletes after confirming the name',
            function (ElicitationContext $elicit): string {
                $result = $elicit->form(
                    'Confirm name?',
                    ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
                    inputKey: 'name'
                );
                $content = $result?->content;
                $name = is_array($content) ? ($content['name'] ?? null) : (is_object($content) ? ($content->name ?? null) : null);
                return 'deleted:' . (is_string($name) ? $name : '?');
            },
            taskSupport: TaskSupport::OPTIONAL,
        );

        // A non-task tool, to prove forbidden tools never create tasks.
        $mcp->tool('greet', 'Greets', fn (string $who = 'world'): string => "Hello, {$who}!");

        $server = $mcp->getServer();
        $initOptions = new InitializationOptions(
            serverName: 'tasks-test',
            serverVersion: '1.0.0',
            capabilities: $server->getCapabilities(new NotificationOptions(), []),
        );
        return new HttpServerRunner($server, $initOptions, [], null, null, new BufferedIo());
    }

    /**
     * @param array<string, mixed>|null $capabilities
     * @return array<string, mixed>
     */
    private function envelope(?array $capabilities = null): array
    {
        return [
            MetaKeys::PROTOCOL_VERSION => '2026-07-28',
            MetaKeys::CLIENT_INFO => ['name' => 'tasks-client', 'version' => '1.0.0'],
            MetaKeys::CLIENT_CAPABILITIES => $capabilities ?? [
                'elicitation' => new \stdClass(),
                'extensions' => [ExtensionIds::TASKS => new \stdClass()],
            ],
        ];
    }

    /**
     * POST a modern request with conforming SEP-2243 headers; returns the
     * decoded JSON-RPC body.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rpc(HttpServerRunner $runner, string $method, array $params, int $id): array
    {
        $body = (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
        $request = new HttpMessage($body);
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('MCP-Protocol-Version', '2026-07-28');
        $request->setHeader('Mcp-Method', $method);
        $name = \Mcp\Shared\McpHeaders::expectedNameValue($method, $params);
        if ($name !== null) {
            $request->setHeader('Mcp-Name', $name);
        }
        $response = $runner->handleRequest($request);
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($decoded, 'Every task exchange is a JSON-RPC body');
        return $decoded;
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed>|null $capabilities
     * @return array<string, mixed>
     */
    private function callTool(HttpServerRunner $runner, string $tool, array $extra = [], int $id = 1, ?array $capabilities = null): array
    {
        return $this->rpc($runner, 'tools/call', array_merge([
            'name' => $tool,
            'arguments' => new \stdClass(),
            '_meta' => $this->envelope($capabilities),
        ], $extra), $id);
    }

    /**
     * Legacy-style POST (no modern envelope/headers); returns the decoded
     * body and any minted session id.
     *
     * @param array<string, mixed> $params
     * @return array{body: array<string, mixed>|null, sessionId: string|null}
     */
    private function legacyRpc(HttpServerRunner $runner, string $method, array $params, int $id, ?string $sessionId): array
    {
        $body = (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
        $request = new HttpMessage($body);
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', 'application/json');
        if ($sessionId !== null) {
            $request->setHeader('Mcp-Session-Id', $sessionId);
        }
        $response = $runner->handleRequest($request);
        $decoded = json_decode((string) $response->getBody(), true);
        return [
            'body' => is_array($decoded) ? $decoded : null,
            'sessionId' => $response->getHeader('Mcp-Session-Id'),
        ];
    }

    public function testLegacyCallerCannotUseTaskMethods(): void
    {
        $runner = $this->makeRunner();
        // Establish a legacy session (no modern envelope, no extension).
        $init = $this->legacyRpc($runner, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'legacy', 'version' => '1.0'],
        ], 1, null);
        $sessionId = $init['sessionId'];
        $this->assertNotNull($sessionId);

        // A legacy caller never declared the Tasks extension, so the task
        // methods must reject it (the extension opt-in is era-independent).
        $get = $this->legacyRpc($runner, 'tasks/get', ['taskId' => 'whatever'], 2, $sessionId);
        $this->assertArrayHasKey('error', $get['body']);
        $this->assertSame(McpError::MISSING_REQUIRED_CLIENT_CAPABILITY, $get['body']['error']['code']);
    }

    /**
     * SEP-2133 extension values MUST be objects. A malformed value — a scalar
     * (`true`) or a JSON array (`[1]`, which json_decode(assoc) renders as a
     * PHP list) — is NOT a valid opt-in and must not unlock Tasks.
     *
     * @dataProvider malformedExtensionValueProvider
     */
    public function testMalformedExtensionDeclarationDoesNotUnlockTasks(mixed $value): void
    {
        $runner = $this->makeRunner();
        $malformed = ['extensions' => [ExtensionIds::TASKS => $value]];

        $required = $this->callTool($runner, 'required_job', id: 1, capabilities: $malformed);
        $this->assertArrayHasKey('error', $required);
        $this->assertSame(McpError::MISSING_REQUIRED_CLIENT_CAPABILITY, $required['error']['code']);

        $get = $this->rpc($runner, 'tasks/get', [
            'taskId' => 'x',
            '_meta' => $this->envelope($malformed),
        ], 2);
        $this->assertArrayHasKey('error', $get);
        $this->assertSame(McpError::MISSING_REQUIRED_CLIENT_CAPABILITY, $get['error']['code']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function malformedExtensionValueProvider(): array
    {
        return [
            'scalar true' => [true],
            'scalar string' => ['yes'],
            'non-empty list array' => [[1]],
        ];
    }

    public function testServerDirectedTaskCreationReturnsFlatCreateTaskResult(): void
    {
        $runner = $this->makeRunner();
        $body = $this->callTool($runner, 'slow_compute', ['arguments' => ['label' => 'z']], id: 1);

        $this->assertArrayNotHasKey('error', $body);
        $result = $body['result'];
        // Flat CreateTaskResult: resultType "task", task fields at top level,
        // NO nested `task` wrapper, NO inlined result/error/inputRequests.
        $this->assertSame('task', $result['resultType']);
        $this->assertArrayHasKey('taskId', $result);
        $this->assertArrayNotHasKey('task', $result);
        $this->assertArrayNotHasKey('result', $result);
        $this->assertArrayNotHasKey('inputRequests', $result);
        $this->assertArrayNotHasKey('requestState', $result);
        // ttlMs is always present; legacy ttl/pollInterval keys never appear.
        $this->assertArrayHasKey('ttlMs', $result);
        $this->assertSame(60000, $result['ttlMs']);
        $this->assertSame(500, $result['pollIntervalMs']);
        $this->assertArrayNotHasKey('ttl', $result);
        $this->assertArrayNotHasKey('pollInterval', $result);
    }

    public function testPollCompletedTaskInlinesResult(): void
    {
        $runner = $this->makeRunner();
        $taskId = $this->callTool($runner, 'slow_compute', ['arguments' => ['label' => 'z']], id: 1)['result']['taskId'];

        $get = $this->rpc($runner, 'tasks/get', ['taskId' => $taskId, '_meta' => $this->envelope()], id: 2)['result'];
        $this->assertSame('complete', $get['resultType']);
        $this->assertSame('completed', $get['status']);
        // The original tool result is inlined under `result`.
        $this->assertSame('computed:z', $get['result']['content'][0]['text']);
        // The removed related-task _meta key must never appear.
        $this->assertArrayNotHasKey('io.modelcontextprotocol/related-task', $get['result']['_meta'] ?? []);
        $this->assertArrayNotHasKey('requestState', $get);
    }

    public function testToolExecutionErrorUsesCompletedStatusWithIsError(): void
    {
        $runner = $this->makeRunner();
        $taskId = $this->callTool($runner, 'failing_job', id: 1)['result']['taskId'];
        $get = $this->rpc($runner, 'tasks/get', ['taskId' => $taskId, '_meta' => $this->envelope()], id: 2)['result'];

        $this->assertSame('completed', $get['status']);
        $this->assertTrue($get['result']['isError']);
        $this->assertArrayNotHasKey('error', $get);
    }

    public function testProtocolErrorUsesFailedStatusWithError(): void
    {
        $runner = $this->makeRunner();
        $taskId = $this->callTool($runner, 'protocol_error_job', id: 1)['result']['taskId'];
        $get = $this->rpc($runner, 'tasks/get', ['taskId' => $taskId, '_meta' => $this->envelope()], id: 2)['result'];

        $this->assertSame('failed', $get['status']);
        $this->assertSame(-32011, $get['error']['code']);
        $this->assertSame('boom', $get['error']['message']);
        $this->assertArrayNotHasKey('result', $get);
    }

    public function testInTaskInputThenUpdateResumesToCompletion(): void
    {
        $runner = $this->makeRunner();

        // Create: the eliciting tool parks the task in input_required.
        $create = $this->callTool($runner, 'confirm_delete', id: 1)['result'];
        $this->assertSame('task', $create['resultType']);
        $this->assertSame('input_required', $create['status']);
        // The handle itself does not carry inputRequests (that is tasks/get).
        $this->assertArrayNotHasKey('inputRequests', $create);
        $taskId = $create['taskId'];

        // Poll: tasks/get surfaces the pending inputRequests.
        $get = $this->rpc($runner, 'tasks/get', ['taskId' => $taskId, '_meta' => $this->envelope()], id: 2)['result'];
        $this->assertSame('input_required', $get['status']);
        $this->assertArrayHasKey('name', $get['inputRequests']);
        $this->assertSame('elicitation/create', $get['inputRequests']['name']['method']);

        // Update: supplying the response resumes the tool to completion.
        $update = $this->rpc($runner, 'tasks/update', [
            'taskId' => $taskId,
            'inputResponses' => ['name' => ['action' => 'accept', 'content' => ['name' => 'report.txt']]],
            '_meta' => $this->envelope(),
        ], id: 3)['result'];
        // tasks/update is an empty ack: exactly {resultType: complete}.
        $this->assertSame(['resultType' => 'complete'], $update);

        $final = $this->rpc($runner, 'tasks/get', ['taskId' => $taskId, '_meta' => $this->envelope()], id: 4)['result'];
        $this->assertSame('completed', $final['status']);
        $this->assertSame('deleted:report.txt', $final['result']['content'][0]['text']);
    }

    public function testCancelAcksAndSettlesTask(): void
    {
        $runner = $this->makeRunner();
        // Park a task so it is live (non-terminal) when cancelled.
        $taskId = $this->callTool($runner, 'confirm_delete', id: 1)['result']['taskId'];

        $cancel = $this->rpc($runner, 'tasks/cancel', ['taskId' => $taskId, '_meta' => $this->envelope()], id: 2)['result'];
        $this->assertSame(['resultType' => 'complete'], $cancel);

        $get = $this->rpc($runner, 'tasks/get', ['taskId' => $taskId, '_meta' => $this->envelope()], id: 3)['result'];
        $this->assertSame('cancelled', $get['status']);

        // Cancel is idempotent on a terminal task: still an empty ack.
        $again = $this->rpc($runner, 'tasks/cancel', ['taskId' => $taskId, '_meta' => $this->envelope()], id: 4)['result'];
        $this->assertSame(['resultType' => 'complete'], $again);
    }

    public function testRequiredToolRejectsUndeclaredClient(): void
    {
        $runner = $this->makeRunner();
        // Client declares NO tasks extension.
        $body = $this->callTool($runner, 'required_job', id: 1, capabilities: ['elicitation' => new \stdClass()]);

        $this->assertArrayHasKey('error', $body);
        $this->assertSame(McpError::MISSING_REQUIRED_CLIENT_CAPABILITY, $body['error']['code']);
        // data.requiredCapabilities.extensions[<id>] is an OBJECT.
        $required = $body['error']['data']['requiredCapabilities']['extensions'];
        $this->assertArrayHasKey(ExtensionIds::TASKS, $required);
    }

    public function testOptionalToolFallsBackToSyncForUndeclaredClient(): void
    {
        $runner = $this->makeRunner();
        $body = $this->callTool($runner, 'slow_compute', id: 1, capabilities: ['elicitation' => new \stdClass()]);

        $this->assertArrayNotHasKey('error', $body);
        // No task: an ordinary CallToolResult, never resultType "task".
        $this->assertNotSame('task', $body['result']['resultType'] ?? null);
        $this->assertArrayNotHasKey('taskId', $body['result']);
        $this->assertSame('computed:x', $body['result']['content'][0]['text']);
    }

    public function testForbiddenToolNeverCreatesTask(): void
    {
        $runner = $this->makeRunner();
        $body = $this->callTool($runner, 'greet', ['arguments' => ['who' => 'Ada']], id: 1);
        $this->assertNotSame('task', $body['result']['resultType'] ?? null);
        $this->assertSame('Hello, Ada!', $body['result']['content'][0]['text']);
    }

    public function testTaskMethodsRejectUndeclaredClient(): void
    {
        $runner = $this->makeRunner();
        // First create a task (declared), then call tasks/get undeclared.
        $taskId = $this->callTool($runner, 'slow_compute', id: 1)['result']['taskId'];

        foreach (['tasks/get', 'tasks/cancel'] as $i => $method) {
            $body = $this->rpc($runner, $method, [
                'taskId' => $taskId,
                '_meta' => $this->envelope(['elicitation' => new \stdClass()]),
            ], id: 10 + $i);
            $this->assertArrayHasKey('error', $body, "$method must reject an undeclared client");
            $this->assertSame(McpError::MISSING_REQUIRED_CLIENT_CAPABILITY, $body['error']['code']);
        }

        $update = $this->rpc($runner, 'tasks/update', [
            'taskId' => $taskId,
            'inputResponses' => new \stdClass(),
            '_meta' => $this->envelope(['elicitation' => new \stdClass()]),
        ], id: 20);
        $this->assertArrayHasKey('error', $update);
        $this->assertSame(McpError::MISSING_REQUIRED_CLIENT_CAPABILITY, $update['error']['code']);
    }

    public function testUnknownTaskIdIsInvalidParams(): void
    {
        $runner = $this->makeRunner();
        foreach (['tasks/get', 'tasks/cancel'] as $i => $method) {
            $body = $this->rpc($runner, $method, ['taskId' => 'does-not-exist', '_meta' => $this->envelope()], id: 30 + $i);
            $this->assertArrayHasKey('error', $body);
            $this->assertSame(-32602, $body['error']['code']);
        }
    }

    public function testRemovedTasksListAndTasksResultAreMethodNotFound(): void
    {
        $runner = $this->makeRunner();
        foreach (['tasks/list', 'tasks/result'] as $i => $method) {
            $params = $method === 'tasks/result'
                ? ['taskId' => 'x', '_meta' => $this->envelope()]
                : ['_meta' => $this->envelope()];
            $body = $this->rpc($runner, $method, $params, id: 40 + $i);
            $this->assertArrayHasKey('error', $body, "$method must be removed");
            $this->assertSame(-32601, $body['error']['code'], "$method → Method Not Found");
        }
    }

    public function testDiscoverAdvertisesTasksExtension(): void
    {
        $runner = $this->makeRunner();
        $body = $this->rpc($runner, 'server/discover', ['_meta' => $this->envelope()], id: 1)['result'];

        $this->assertArrayHasKey('extensions', $body['capabilities']);
        $this->assertArrayHasKey(ExtensionIds::TASKS, $body['capabilities']['extensions']);
        // No legacy v1 `tasks` capability slot on the modern path.
        $this->assertArrayNotHasKey('tasks', $body['capabilities']);
    }

    // ---- stdio (non-HTTP) transport coverage --------------------------------
    //
    // The Tasks surface lives in transport-independent layers (McpServer
    // handlers, TaskManager, ServerSession capability gating). These tests
    // drive the same modern dispatch over a plain in-memory Transport — the
    // path the stdio runner uses — to prove the flow works off HTTP too.

    /** @return array{TaskStdioTransport, TaskStdioSession, McpServer} */
    private function makeStdioSession(): array
    {
        $mcp = new McpServer('tasks-stdio');
        $mcp->enableTasks(sys_get_temp_dir() . '/mcp_tasks_stdio_' . bin2hex(random_bytes(4)));
        $mcp->tool('slow_compute', 'Computes', fn (string $label = 'x'): string => "computed:{$label}", taskSupport: TaskSupport::OPTIONAL);
        $mcp->tool(
            'confirm_delete',
            'Confirms then deletes',
            function (ElicitationContext $elicit): string {
                $r = $elicit->form('Name?', ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]], inputKey: 'name');
                $content = $r?->content;
                $name = is_array($content) ? ($content['name'] ?? null) : (is_object($content) ? ($content->name ?? null) : null);
                return 'deleted:' . (is_string($name) ? $name : '?');
            },
            taskSupport: TaskSupport::OPTIONAL,
        );

        $server = $mcp->getServer();
        $transport = new TaskStdioTransport();
        $session = new TaskStdioSession($transport, new InitializationOptions(
            serverName: 'tasks-stdio',
            serverVersion: '1.0.0',
            capabilities: $server->getCapabilities(new NotificationOptions(), []),
        ));
        $server->setSession($session);
        $session->registerHandlers($server->getHandlers());
        $session->registerNotificationHandlers($server->getNotificationHandlers());
        return [$transport, $session, $mcp];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function stdioRpc(TaskStdioTransport $transport, TaskStdioSession $session, string $method, array $params, int $id): array
    {
        $transport->writtenMessages = [];
        // Mirror a real transport: JSON round-trip so nested objects arrive
        // as associative arrays (json_decode assoc), exactly as the stdio
        // transport decodes the wire.
        $decoded = json_decode((string) json_encode($params), true);
        $session->processIncoming(new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId($id),
            method: $method,
            params: new TaskRawParams(is_array($decoded) ? $decoded : []),
        )));
        $this->assertNotEmpty($transport->writtenMessages, "Expected a response for {$method}");
        $wire = json_decode((string) json_encode($transport->writtenMessages[0]), true);
        $this->assertIsArray($wire);
        return $wire;
    }

    public function testStdioTaskLifecycleCompletes(): void
    {
        [$transport, $session] = $this->makeStdioSession();

        $create = $this->stdioRpc($transport, $session, 'tools/call', [
            'name' => 'slow_compute',
            'arguments' => ['label' => 'q'],
            '_meta' => $this->envelope(),
        ], 1)['result'];
        $this->assertSame('task', $create['resultType']);
        $taskId = $create['taskId'];

        $get = $this->stdioRpc($transport, $session, 'tasks/get', [
            'taskId' => $taskId,
            '_meta' => $this->envelope(),
        ], 2)['result'];
        $this->assertSame('completed', $get['status']);
        $this->assertSame('computed:q', $get['result']['content'][0]['text']);

        $cancel = $this->stdioRpc($transport, $session, 'tasks/cancel', [
            'taskId' => $taskId,
            '_meta' => $this->envelope(),
        ], 3)['result'];
        $this->assertSame(['resultType' => 'complete'], $cancel);
    }

    public function testStdioInTaskInputThenUpdate(): void
    {
        [$transport, $session] = $this->makeStdioSession();

        $taskId = $this->stdioRpc($transport, $session, 'tools/call', [
            'name' => 'confirm_delete',
            'arguments' => [],
            '_meta' => $this->envelope(),
        ], 1)['result']['taskId'];

        $get = $this->stdioRpc($transport, $session, 'tasks/get', [
            'taskId' => $taskId,
            '_meta' => $this->envelope(),
        ], 2)['result'];
        $this->assertSame('input_required', $get['status']);
        $this->assertArrayHasKey('name', $get['inputRequests']);

        $this->stdioRpc($transport, $session, 'tasks/update', [
            'taskId' => $taskId,
            'inputResponses' => ['name' => ['action' => 'accept', 'content' => ['name' => 'log.txt']]],
            '_meta' => $this->envelope(),
        ], 3);

        $final = $this->stdioRpc($transport, $session, 'tasks/get', [
            'taskId' => $taskId,
            '_meta' => $this->envelope(),
        ], 4)['result'];
        $this->assertSame('completed', $final['status']);
        $this->assertSame('deleted:log.txt', $final['result']['content'][0]['text']);
    }
}

/**
 * Minimal in-memory Transport capturing written messages (stdio-equivalent).
 */
final class TaskStdioTransport implements Transport
{
    /** @var JsonRpcMessage[] */
    public array $writtenMessages = [];

    public function start(): void
    {
    }

    public function stop(): void
    {
    }

    public function readMessage(): ?JsonRpcMessage
    {
        return null;
    }

    public function writeMessage(JsonRpcMessage $message): void
    {
        $this->writtenMessages[] = $message;
    }
}

/**
 * Exposes the protected wire-intake path for direct stdio-style dispatch.
 */
final class TaskStdioSession extends ServerSession
{
    public function processIncoming(JsonRpcMessage $message): void
    {
        $this->handleIncomingMessage($message);
    }
}

/**
 * Wire-shaped params: materializes `_meta` into a Meta instance (as a real
 * transport does) and serializes exactly the given array.
 */
final class TaskRawParams extends RequestParams
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
        $meta = null;
        if (isset($data['_meta']) && is_array($data['_meta'])) {
            $meta = new \Mcp\Types\Meta();
            foreach ($data['_meta'] as $key => $value) {
                $meta->setField($key, $value);
            }
        }
        parent::__construct($meta);
    }

    public function jsonSerialize(): mixed
    {
        return $this->data !== [] ? $this->data : new \stdClass();
    }
}
