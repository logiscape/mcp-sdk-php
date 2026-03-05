<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\McpServer;
use Mcp\Server\McpServerException;
use Mcp\Types\CallToolResult;
use Mcp\Types\TaskGetResult;
use Mcp\Types\TaskListResult;
use Mcp\Types\TaskStatus;
use Mcp\Types\TextContent;
use PHPUnit\Framework\TestCase;

/**
 * Tests that task handler responses produce the correct wire JSON shapes
 * per the MCP 2025-11-25 specification.
 *
 * - tasks/get: Task fields at root level (allOf[Result, Task])
 * - tasks/cancel: Task fields at root level (allOf[Result, Task])
 * - tasks/list: { tasks: [...] }
 * - tasks/result: Underlying result type with _meta["io.modelcontextprotocol/related-task"]
 */
final class TaskWireFormatTest extends TestCase
{
    private McpServer $server;
    private array $handlers;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/mcp_task_wire_test_' . bin2hex(random_bytes(8));
        $this->server = new McpServer('test');
        $this->server->tool('slow-tool', 'A slow tool', fn() => 'done');
        $this->server->enableTasks($this->storagePath);
        $this->handlers = $this->server->getServer()->getHandlers();
    }

    protected function tearDown(): void
    {
        $files = glob($this->storagePath . '/*.json');
        if ($files) {
            array_map('unlink', $files);
        }
        if (is_dir($this->storagePath)) {
            @rmdir($this->storagePath);
        }
    }

    /**
     * Test that tasks/get returns Task fields at the root level of the result,
     * not nested under a "task" key.
     *
     * Spec: GetTaskResult is allOf[Result, Task], meaning taskId, status, etc.
     * appear directly in the result object.
     */
    public function testTasksGetWireFormat(): void
    {
        $task = $this->server->getTaskManager()->createTask(ttl: 30000, pollInterval: 5000);

        $params = new \stdClass();
        $params->taskId = $task->taskId;
        $result = ($this->handlers['tasks/get'])($params);

        // Must be TaskGetResult, not generic Result with dynamic fields
        $this->assertInstanceOf(TaskGetResult::class, $result);

        // Serialize and verify wire shape
        $json = json_decode(json_encode($result), true);

        // Task fields MUST be at root level
        $this->assertArrayHasKey('taskId', $json);
        $this->assertArrayHasKey('status', $json);
        $this->assertArrayHasKey('createdAt', $json);
        $this->assertArrayHasKey('lastUpdatedAt', $json);
        $this->assertArrayHasKey('ttl', $json);
        $this->assertArrayHasKey('pollInterval', $json);

        // Must NOT have a nested "task" key
        $this->assertArrayNotHasKey('task', $json);

        // Verify values
        $this->assertEquals($task->taskId, $json['taskId']);
        $this->assertEquals(TaskStatus::WORKING, $json['status']);
        $this->assertEquals(30000, $json['ttl']);
        $this->assertEquals(5000, $json['pollInterval']);
    }

    /**
     * Test that tasks/cancel returns Task fields at the root level with
     * status "cancelled".
     *
     * Spec: CancelTaskResult is allOf[Result, Task].
     */
    public function testTasksCancelWireFormat(): void
    {
        $task = $this->server->getTaskManager()->createTask();

        $params = new \stdClass();
        $params->taskId = $task->taskId;
        $result = ($this->handlers['tasks/cancel'])($params);

        $this->assertInstanceOf(TaskGetResult::class, $result);

        $json = json_decode(json_encode($result), true);

        // Task fields at root
        $this->assertArrayHasKey('taskId', $json);
        $this->assertArrayHasKey('status', $json);
        $this->assertArrayNotHasKey('task', $json);

        $this->assertEquals($task->taskId, $json['taskId']);
        $this->assertEquals(TaskStatus::CANCELLED, $json['status']);
    }

    /**
     * Test that tasks/cancel for a terminal task returns a JSON-RPC error
     * with code -32602 per spec.
     */
    public function testTasksCancelTerminalReturnsError(): void
    {
        $task = $this->server->getTaskManager()->createTask();
        $this->server->getTaskManager()->updateStatus($task->taskId, TaskStatus::COMPLETED);

        $params = new \stdClass();
        $params->taskId = $task->taskId;

        $this->expectException(McpServerException::class);
        ($this->handlers['tasks/cancel'])($params);
    }

    /**
     * Test that tasks/list returns { tasks: [...] } with each task having
     * fields at root level.
     *
     * Spec: ListTasksResult has a "tasks" array of Task objects.
     */
    public function testTasksListWireFormat(): void
    {
        $task1 = $this->server->getTaskManager()->createTask(ttl: 10000);
        $task2 = $this->server->getTaskManager()->createTask(ttl: 20000);

        $params = new \stdClass();
        $result = ($this->handlers['tasks/list'])($params);

        $this->assertInstanceOf(TaskListResult::class, $result);

        $json = json_decode(json_encode($result), true);

        $this->assertArrayHasKey('tasks', $json);
        $this->assertIsArray($json['tasks']);
        $this->assertCount(2, $json['tasks']);

        // Each task in the array should have root-level fields
        foreach ($json['tasks'] as $taskJson) {
            $this->assertArrayHasKey('taskId', $taskJson);
            $this->assertArrayHasKey('status', $taskJson);
            $this->assertArrayHasKey('createdAt', $taskJson);
        }

        $taskIds = array_column($json['tasks'], 'taskId');
        $this->assertContains($task1->taskId, $taskIds);
        $this->assertContains($task2->taskId, $taskIds);
    }

    /**
     * Test that tasks/result returns the underlying CallToolResult directly
     * with _meta["io.modelcontextprotocol/related-task"] = { taskId: ... }.
     *
     * Spec: GetTaskPayloadResult returns exactly what the underlying request
     * would have returned. For tool calls, that is a CallToolResult.
     * The _meta field MUST include io.modelcontextprotocol/related-task.
     */
    public function testTasksResultWireFormat(): void
    {
        $task = $this->server->getTaskManager()->createTask();
        $this->server->getTaskManager()->updateStatus($task->taskId, TaskStatus::COMPLETED);

        // Store a CallToolResult as the task's result
        $toolResult = new CallToolResult(
            content: [new TextContent('Weather: sunny, 72°F')],
            isError: false,
        );
        $this->server->getTaskManager()->setResult(
            $task->taskId,
            json_decode(json_encode($toolResult), true)
        );

        $params = new \stdClass();
        $params->taskId = $task->taskId;
        $result = ($this->handlers['tasks/result'])($params);

        // Must be CallToolResult, not a generic wrapper
        $this->assertInstanceOf(CallToolResult::class, $result);

        $json = json_decode(json_encode($result), true);

        // Must have CallToolResult fields at root
        $this->assertArrayHasKey('content', $json);
        $this->assertIsArray($json['content']);
        $this->assertCount(1, $json['content']);
        $this->assertEquals('text', $json['content'][0]['type']);
        $this->assertEquals('Weather: sunny, 72°F', $json['content'][0]['text']);

        // Must NOT have nested "task" or "result" keys
        $this->assertArrayNotHasKey('task', $json);
        $this->assertArrayNotHasKey('result', $json);

        // Must have _meta with related-task
        $this->assertArrayHasKey('_meta', $json);
        $this->assertArrayHasKey('io.modelcontextprotocol/related-task', $json['_meta']);
        $this->assertEquals(
            ['taskId' => $task->taskId],
            $json['_meta']['io.modelcontextprotocol/related-task']
        );
    }

    /**
     * Test that tasks/result preserves existing _meta from the stored result
     * while adding the related-task metadata.
     */
    public function testTasksResultPreservesExistingMeta(): void
    {
        $task = $this->server->getTaskManager()->createTask();
        $this->server->getTaskManager()->updateStatus($task->taskId, TaskStatus::COMPLETED);

        // Store a result that already has _meta
        $storedResult = [
            'content' => [['type' => 'text', 'text' => 'result']],
            'isError' => false,
            '_meta' => ['custom-key' => 'custom-value'],
        ];
        $this->server->getTaskManager()->setResult($task->taskId, $storedResult);

        $params = new \stdClass();
        $params->taskId = $task->taskId;
        $result = ($this->handlers['tasks/result'])($params);

        $json = json_decode(json_encode($result), true);

        // Both the original _meta key and the related-task key should be present
        $this->assertArrayHasKey('_meta', $json);
        $this->assertArrayHasKey('custom-key', $json['_meta']);
        $this->assertEquals('custom-value', $json['_meta']['custom-key']);
        $this->assertArrayHasKey('io.modelcontextprotocol/related-task', $json['_meta']);
        $this->assertEquals($task->taskId, $json['_meta']['io.modelcontextprotocol/related-task']['taskId']);
    }

    /**
     * Test that tasks/result for a task without a stored result throws an error.
     */
    public function testTasksResultNotAvailableThrows(): void
    {
        $task = $this->server->getTaskManager()->createTask();

        $params = new \stdClass();
        $params->taskId = $task->taskId;

        $this->expectException(McpServerException::class);
        ($this->handlers['tasks/result'])($params);
    }

    /**
     * Test that tasks/get for a nonexistent task throws an error.
     */
    public function testTasksGetNotFoundThrows(): void
    {
        $params = new \stdClass();
        $params->taskId = 'nonexistent-id';

        $this->expectException(McpServerException::class);
        ($this->handlers['tasks/get'])($params);
    }
}
