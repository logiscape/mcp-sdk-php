<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\McpServer;
use Mcp\Server\McpServerException;
use Mcp\Types\CallToolResult;
use Mcp\Types\TaskCancelResult;
use Mcp\Types\TaskGetResult;
use Mcp\Types\TaskStatus;
use Mcp\Types\TextContent;
use PHPUnit\Framework\TestCase;

/**
 * Tests that task handler responses produce the correct wire JSON shapes
 * per the SEP-2663 Tasks extension (revision 2026-07-28).
 *
 * - tasks/get: FLAT Result & DetailedTask, resultType "complete", task fields
 *   at root level, inlining result/error/inputRequests by status.
 * - tasks/cancel: empty ack {"resultType":"complete"} (idempotent).
 * - tasks/list and tasks/result no longer exist (removed in SEP-2663).
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
        $task = $this->server->getTaskManager()->createTask(ttlMs: 30000, pollIntervalMs: 5000);

        $params = new \stdClass();
        $params->taskId = $task->taskId;
        $result = ($this->handlers['tasks/get'])($params);

        // Must be TaskGetResult, not generic Result with dynamic fields
        $this->assertInstanceOf(TaskGetResult::class, $result);

        // Serialize and verify wire shape
        $json = json_decode(json_encode($result), true);

        // FLAT Result & DetailedTask discriminated by resultType "complete"
        $this->assertEquals('complete', $json['resultType']);

        // Task fields MUST be at root level, using the SEP-2663 field names
        $this->assertArrayHasKey('taskId', $json);
        $this->assertArrayHasKey('status', $json);
        $this->assertArrayHasKey('createdAt', $json);
        $this->assertArrayHasKey('lastUpdatedAt', $json);
        $this->assertArrayHasKey('ttlMs', $json);
        $this->assertArrayHasKey('pollIntervalMs', $json);

        // Must NOT have a nested "task" key nor the legacy field names
        $this->assertArrayNotHasKey('task', $json);
        $this->assertArrayNotHasKey('ttl', $json);
        $this->assertArrayNotHasKey('pollInterval', $json);

        // Verify values
        $this->assertEquals($task->taskId, $json['taskId']);
        $this->assertEquals(TaskStatus::WORKING, $json['status']);
        $this->assertEquals(30000, $json['ttlMs']);
        $this->assertEquals(5000, $json['pollIntervalMs']);
    }

    /**
     * Test that tasks/get on a completed task inlines the original tool result
     * at the top-level `result` key (the SEP-2663 replacement for the removed
     * tasks/result method), with no related-task _meta anywhere.
     */
    public function testTasksGetCompletedInlinesResult(): void
    {
        $task = $this->server->getTaskManager()->createTask();
        $toolResult = new CallToolResult(
            content: [new TextContent('Weather: sunny, 72°F')],
            isError: false,
        );
        $this->server->getTaskManager()->complete(
            $task->taskId,
            json_decode(json_encode($toolResult), true),
        );

        $params = new \stdClass();
        $params->taskId = $task->taskId;
        $result = ($this->handlers['tasks/get'])($params);

        $this->assertInstanceOf(TaskGetResult::class, $result);

        $json = json_decode(json_encode($result), true);

        $this->assertEquals('complete', $json['resultType']);
        $this->assertEquals(TaskStatus::COMPLETED, $json['status']);

        // The original tool result is inlined under "result"
        $this->assertArrayHasKey('result', $json);
        $this->assertArrayHasKey('content', $json['result']);
        $this->assertEquals('text', $json['result']['content'][0]['type']);
        $this->assertEquals('Weather: sunny, 72°F', $json['result']['content'][0]['text']);

        // The removed related-task _meta key must not appear anywhere
        $encoded = json_encode($json);
        $this->assertStringNotContainsString('io.modelcontextprotocol/related-task', $encoded);
    }

    /**
     * Test that tasks/cancel returns the empty acknowledgement
     * {"resultType":"complete"} — SEP-2663 cancellation is cooperative and
     * eventually-consistent, so the ack carries no task fields. The cancelled
     * task itself is observed via the TaskManager.
     */
    public function testTasksCancelWireFormat(): void
    {
        $task = $this->server->getTaskManager()->createTask();

        $params = new \stdClass();
        $params->taskId = $task->taskId;
        $result = ($this->handlers['tasks/cancel'])($params);

        $this->assertInstanceOf(TaskCancelResult::class, $result);

        $json = json_decode(json_encode($result), true);

        // Empty ack: exactly {"resultType":"complete"}, no task fields
        $this->assertSame(['resultType' => 'complete'], $json);
        $this->assertArrayNotHasKey('taskId', $json);
        $this->assertArrayNotHasKey('status', $json);
        $this->assertArrayNotHasKey('task', $json);

        // The underlying task has transitioned to cancelled
        $cancelled = $this->server->getTaskManager()->getTask($task->taskId);
        $this->assertEquals(TaskStatus::CANCELLED, $cancelled->status);
    }

    /**
     * Test that tasks/cancel on a terminal task is an idempotent no-throw ack
     * (SEP-2663) — the previous -32602 "not cancellable" error is obsolete —
     * and that the task keeps its terminal status.
     */
    public function testTasksCancelTerminalIsIdempotentAck(): void
    {
        $task = $this->server->getTaskManager()->createTask();
        $this->server->getTaskManager()->updateStatus($task->taskId, TaskStatus::COMPLETED);

        $params = new \stdClass();
        $params->taskId = $task->taskId;

        $result = ($this->handlers['tasks/cancel'])($params);

        $this->assertInstanceOf(TaskCancelResult::class, $result);
        $this->assertSame(['resultType' => 'complete'], json_decode(json_encode($result), true));

        // Terminal task is left untouched
        $unchanged = $this->server->getTaskManager()->getTask($task->taskId);
        $this->assertEquals(TaskStatus::COMPLETED, $unchanged->status);
    }

    // tasks/list and tasks/result were removed in SEP-2663: the methods are no
    // longer registered (calling them answers -32601 Method Not Found), and the
    // completed payload is now inlined into tasks/get's `result` field (covered
    // by testTasksGetCompletedInlinesResult above).

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
