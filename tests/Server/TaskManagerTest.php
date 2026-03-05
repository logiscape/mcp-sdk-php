<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\TaskManager;
use Mcp\Types\TaskStatus;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the TaskManager file-based task lifecycle manager.
 */
final class TaskManagerTest extends TestCase
{
    private string $storagePath;
    private TaskManager $manager;

    protected function setUp(): void {
        $this->storagePath = sys_get_temp_dir() . '/mcp_test_tasks_' . bin2hex(random_bytes(4));
        $this->manager = new TaskManager($this->storagePath);
    }

    protected function tearDown(): void {
        // Clean up test files
        $files = glob($this->storagePath . '/*');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->storagePath)) {
            rmdir($this->storagePath);
        }
    }

    public function testCreateTask(): void {
        $task = $this->manager->createTask(ttl: 3600, pollInterval: 5);

        $this->assertNotEmpty($task->taskId);
        $this->assertEquals(TaskStatus::WORKING, $task->status);
        $this->assertNotNull($task->createdAt);
        $this->assertEquals(3600, $task->ttl);
        $this->assertEquals(5, $task->pollInterval);
    }

    public function testGetTask(): void {
        $task = $this->manager->createTask();
        $retrieved = $this->manager->getTask($task->taskId);

        $this->assertNotNull($retrieved);
        $this->assertEquals($task->taskId, $retrieved->taskId);
        $this->assertEquals(TaskStatus::WORKING, $retrieved->status);
    }

    public function testGetNonExistentTask(): void {
        $result = $this->manager->getTask('nonexistent');
        $this->assertNull($result);
    }

    public function testUpdateStatus(): void {
        $task = $this->manager->createTask();
        $updated = $this->manager->updateStatus($task->taskId, TaskStatus::COMPLETED, 'Done!');

        $this->assertNotNull($updated);
        $this->assertEquals(TaskStatus::COMPLETED, $updated->status);
        $this->assertEquals('Done!', $updated->statusMessage);
    }

    public function testSetAndGetResult(): void {
        $task = $this->manager->createTask();
        $this->manager->setResult($task->taskId, ['answer' => 42]);

        $result = $this->manager->getResult($task->taskId);
        $this->assertEquals(['answer' => 42], $result);
    }

    public function testListTasks(): void {
        $this->manager->createTask();
        $this->manager->createTask();
        $this->manager->createTask();

        $tasks = $this->manager->listTasks();
        $this->assertCount(3, $tasks);
    }

    public function testCancelTask(): void {
        $task = $this->manager->createTask();
        $cancelled = $this->manager->cancelTask($task->taskId);

        $this->assertNotNull($cancelled);
        $this->assertEquals(TaskStatus::CANCELLED, $cancelled->status);
    }

    public function testDeleteTask(): void {
        $task = $this->manager->createTask();
        $this->manager->setResult($task->taskId, 'data');
        $this->manager->deleteTask($task->taskId);

        $this->assertNull($this->manager->getTask($task->taskId));
        $this->assertNull($this->manager->getResult($task->taskId));
    }

    public function testTtlExpiry(): void {
        $task = $this->manager->createTask(ttl: 0);
        // TTL of 0 means it expires immediately
        sleep(1);
        $this->assertNull($this->manager->getTask($task->taskId));
    }

    public function testTaskLifecycle(): void {
        // Create
        $task = $this->manager->createTask(ttl: 300);
        $this->assertEquals(TaskStatus::WORKING, $task->status);

        // Update to input_required
        $this->manager->updateStatus($task->taskId, TaskStatus::INPUT_REQUIRED, 'Need more info');
        $task = $this->manager->getTask($task->taskId);
        $this->assertEquals(TaskStatus::INPUT_REQUIRED, $task->status);

        // Update back to working
        $this->manager->updateStatus($task->taskId, TaskStatus::WORKING, 'Resuming');
        $task = $this->manager->getTask($task->taskId);
        $this->assertEquals(TaskStatus::WORKING, $task->status);

        // Complete
        $this->manager->updateStatus($task->taskId, TaskStatus::COMPLETED, 'All done');
        $this->manager->setResult($task->taskId, ['output' => 'success']);
        $task = $this->manager->getTask($task->taskId);
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);

        $result = $this->manager->getResult($task->taskId);
        $this->assertEquals(['output' => 'success'], $result);
    }
}
