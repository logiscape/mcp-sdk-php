<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\TaskManager;
use Mcp\Server\Tasks\TaskTransitionRejectedException;
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
        $task = $this->manager->createTask(ttlMs: 3600000, pollIntervalMs: 5000);

        $this->assertNotEmpty($task->taskId);
        $this->assertEquals(TaskStatus::WORKING, $task->status);
        $this->assertNotNull($task->createdAt);
        $this->assertEquals(3600000, $task->ttlMs);
        $this->assertEquals(5000, $task->pollIntervalMs);
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

    /**
     * complete() moves a task to terminal `completed` and inlines the tool
     * result into the stored record (the inlined `result` the tasks/get
     * response carries). Replaces the removed setResult()/getResult() pair.
     */
    public function testCompleteInlinesResult(): void {
        $task = $this->manager->createTask();
        $completed = $this->manager->complete($task->taskId, ['answer' => 42]);

        $this->assertNotNull($completed);
        $this->assertEquals(TaskStatus::COMPLETED, $completed->status);

        $record = $this->manager->getRecord($task->taskId);
        $this->assertNotNull($record);
        $this->assertEquals(['answer' => 42], $record['result']);
    }

    public function testCancelTask(): void {
        $task = $this->manager->createTask();
        $cancelled = $this->manager->cancelTask($task->taskId);

        $this->assertNotNull($cancelled);
        $this->assertEquals(TaskStatus::CANCELLED, $cancelled->status);
    }

    public function testDeleteTask(): void {
        $task = $this->manager->createTask();
        $this->manager->complete($task->taskId, ['data' => true]);
        $this->manager->deleteTask($task->taskId);

        $this->assertNull($this->manager->getTask($task->taskId));
        $this->assertNull($this->manager->getRecord($task->taskId));
    }

    public function testTtlExpiry(): void {
        $task = $this->manager->createTask(ttlMs: 0);
        // ttlMs of 0 means it expires immediately (null would be unlimited)
        sleep(1);
        $this->assertNull($this->manager->getTask($task->taskId));
    }

    /**
     * The working → working self-transition is legal: it is how an
     * out-of-band worker refreshes progress (statusMessage) on a deferred
     * task without changing state.
     */
    public function testWorkingToWorkingSelfTransitionAllowed(): void {
        $task = $this->manager->createTask();

        $updated = $this->manager->updateStatus($task->taskId, TaskStatus::WORKING, 'crunching batch 3/10');
        $this->assertNotNull($updated);
        $this->assertEquals(TaskStatus::WORKING, $updated->status);
        $this->assertEquals('crunching batch 3/10', $updated->statusMessage);

        // A refresh without a message is also legal (clears the previous one).
        $again = $this->manager->updateStatus($task->taskId, TaskStatus::WORKING);
        $this->assertNotNull($again);
        $this->assertEquals(TaskStatus::WORKING, $again->status);
        $this->assertNull($again->statusMessage);
    }

    /**
     * Moving to `working` clears pending input state: a working task must
     * surface handle-only via tasks/get, so a resumed tool that defers (or a
     * worker reviving an input_required task) must not leave stale
     * inputRequests/requestState in the record.
     */
    public function testUpdateStatusToWorkingClearsPendingInput(): void {
        $task = $this->manager->createTask();
        $this->manager->setInputRequired($task->taskId, [
            'name' => ['method' => 'elicitation/create', 'params' => []],
        ], 'signed-state');

        $record = $this->manager->getRecord($task->taskId);
        $this->assertArrayHasKey('inputRequests', $record);
        $this->assertArrayHasKey('requestState', $record);

        $this->manager->updateStatus($task->taskId, TaskStatus::WORKING, 'resumed by worker');

        $record = $this->manager->getRecord($task->taskId);
        $this->assertNotNull($record);
        $this->assertEquals(TaskStatus::WORKING, $record['status']);
        $this->assertArrayNotHasKey('inputRequests', $record);
        $this->assertArrayNotHasKey('requestState', $record);
    }

    /**
     * State transitions serialize per record through a `.lock` sidecar
     * (flock LOCK_EX over the whole read-validate-write). The sidecar is
     * created by the first mutation and removed with the record.
     */
    public function testMutationLockSidecarLifecycle(): void {
        $task = $this->manager->createTask();
        $this->assertCount(0, glob($this->storagePath . '/task_*.json.lock') ?: []);

        $this->manager->updateStatus($task->taskId, TaskStatus::WORKING, 'progress');
        $this->assertCount(1, glob($this->storagePath . '/task_*.json.lock') ?: []);

        $this->manager->deleteTask($task->taskId);
        $this->assertCount(0, glob($this->storagePath . '/task_*.json') ?: []);
        $this->assertCount(0, glob($this->storagePath . '/task_*.json.lock') ?: []);
    }

    /**
     * cleanup() removes records that are genuinely corrupt (not valid JSON
     * objects) while leaving live tasks untouched — the corrupt-check reads
     * under LOCK_SH so a mid-write record can never be mistaken for corrupt.
     */
    public function testCleanupRemovesCorruptRecords(): void {
        $live = $this->manager->createTask();
        file_put_contents($this->storagePath . '/task_corrupt.json', '{not json');

        $this->manager->cleanup();

        $this->assertFileDoesNotExist($this->storagePath . '/task_corrupt.json');
        $this->assertNotNull($this->manager->getTask($live->taskId));
    }

    /**
     * cleanup() sweeps lock sidecars whose record is gone, but never a live
     * task's sidecar.
     */
    public function testCleanupRemovesOrphanedLockFiles(): void {
        $task = $this->manager->createTask();
        $this->manager->updateStatus($task->taskId, TaskStatus::WORKING); // creates live sidecar
        file_put_contents($this->storagePath . '/task_orphan.json.lock', '');

        $this->manager->cleanup();

        $this->assertFileDoesNotExist($this->storagePath . '/task_orphan.json.lock');
        $this->assertCount(1, glob($this->storagePath . '/task_*.json.lock') ?: []);
        $this->assertNotNull($this->manager->getTask($task->taskId));
    }

    /**
     * sweep() bounds the work of a single call to maxFiles records and
     * returns a cursor that resumes the pass exactly where it stopped, so
     * a cron job on a fixed budget can walk an arbitrarily large store
     * across runs. A completed pass returns a null cursor. Deletion
     * results across the bounded runs match one unbounded cleanup().
     */
    public function testSweepBoundedAndResumable(): void {
        $expired = [];
        for ($i = 0; $i < 3; $i++) {
            $expired[] = $this->manager->createTask(ttlMs: 0);
        }
        $live = $this->manager->createTask(); // ttlMs null = unlimited
        sleep(1);

        $calls = 0;
        $examined = 0;
        $deleted = 0;
        $cursor = null;
        do {
            $result = $this->manager->sweep(maxFiles: 2, cursor: $cursor);
            $this->assertLessThanOrEqual(2, $result['examined']);
            $calls++;
            $examined += $result['examined'];
            $deleted += $result['deleted'];
            $cursor = $result['cursor'];
        } while ($cursor !== null);

        $this->assertGreaterThanOrEqual(2, $calls, 'Four records at maxFiles 2 must take multiple calls');
        $this->assertSame(4, $examined);
        $this->assertSame(3, $deleted);
        $this->assertNotNull($this->manager->getTask($live->taskId));
        foreach ($expired as $task) {
            $this->assertNull($this->manager->getTask($task->taskId));
        }
    }

    /**
     * The orphaned-lock-sidecar sweep runs only on a call that reaches the
     * end of the listing (null cursor) — a bounded mid-pass call stays
     * bounded and leaves the orphan for the completing call.
     */
    public function testSweepOrphanSidecarsRemovedOnlyOnCompletingCall(): void {
        $this->manager->createTask();
        $this->manager->createTask();
        $this->manager->createTask();
        file_put_contents($this->storagePath . '/task_orphan.json.lock', '');

        $partial = $this->manager->sweep(maxFiles: 1);
        $this->assertNotNull($partial['cursor']);
        $this->assertFileExists($this->storagePath . '/task_orphan.json.lock');

        $completing = $this->manager->sweep(cursor: $partial['cursor']);
        $this->assertNull($completing['cursor']);
        $this->assertSame(2, $completing['examined']);
        $this->assertFileDoesNotExist($this->storagePath . '/task_orphan.json.lock');
        $this->assertCount(3, glob($this->storagePath . '/task_*.json') ?: []);
    }

    public function testSweepRejectsNonPositiveMaxFiles(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxFiles must be at least 1');
        $this->manager->sweep(maxFiles: 0);
    }

    /**
     * `deleted` counts only confirmed removals: when unlink fails (a
     * concurrent open handle on Windows, a filesystem error elsewhere) the
     * record is examined but not counted, stays on disk, and is removed by
     * a later pass once the obstacle clears.
     */
    public function testSweepDoesNotCountFailedUnlinkAsDeleted(): void {
        $expired = $this->manager->createTask(ttlMs: 0);
        sleep(1);
        $files = glob($this->storagePath . '/task_*.json') ?: [];
        $this->assertCount(1, $files);

        if (PHP_OS_FAMILY === 'Windows') {
            // The read-only attribute blocks unlink on Windows (open
            // handles do not: PHP opens streams with FILE_SHARE_DELETE).
            chmod($files[0], 0444);
        } else {
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                $this->markTestSkipped('Directory permissions do not block unlink for root');
            }
            // A non-writable directory blocks unlink of its entries.
            chmod($this->storagePath, 0555);
        }

        try {
            $result = $this->manager->sweep();
            $this->assertSame(1, $result['examined']);
            $this->assertSame(0, $result['deleted'], 'A failed unlink must not be counted as deleted');
            $this->assertFileExists($files[0]);
        } finally {
            if (PHP_OS_FAMILY === 'Windows') {
                chmod($files[0], 0644);
            } else {
                chmod($this->storagePath, 0755);
            }
        }

        $retry = $this->manager->sweep();
        $this->assertSame(1, $retry['deleted'], 'The record is removed once the obstacle clears');
        $this->assertNull($this->manager->getTask($expired->taskId));
    }

    public function testCannotTransitionFromTerminalState(): void {
        $task = $this->manager->createTask();
        $this->manager->updateStatus($task->taskId, TaskStatus::COMPLETED, 'Done');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid task state transition');
        $this->manager->updateStatus($task->taskId, TaskStatus::WORKING);
    }

    /**
     * An illegal transition throws the dedicated
     * TaskTransitionRejectedException — the precise type a worker catches
     * in the documented settlement race, distinguishable from a genuine
     * \InvalidArgumentException programming error — carrying the observed
     * store status (the terminal status, in the lost-race case) and the
     * rejected target. The message stays byte-identical to the previous
     * bare \InvalidArgumentException, which the test above pins as the
     * still-working parent-type catch.
     */
    public function testTerminalRejectionThrowsDedicatedTypeWithStatuses(): void {
        $task = $this->manager->createTask();
        $this->manager->cancelTask($task->taskId);

        try {
            $this->manager->complete($task->taskId, ['content' => []]);
            $this->fail('Expected TaskTransitionRejectedException');
        } catch (TaskTransitionRejectedException $e) {
            $this->assertSame(TaskStatus::CANCELLED, $e->fromStatus);
            $this->assertSame(TaskStatus::COMPLETED, $e->toStatus);
            $this->assertSame(
                "Invalid task state transition from 'cancelled' to 'completed'",
                $e->getMessage()
            );
        }
    }

    /**
     * SEP-2663: cancellation is cooperative and idempotent. Cancelling a task
     * already in a terminal state is a no-throw ack that leaves the task
     * unchanged (it keeps its terminal status), rather than the old behavior
     * of throwing "Cannot cancel task".
     */
    public function testCancelCompletedTaskIsIdempotent(): void {
        $task = $this->manager->createTask();
        $this->manager->updateStatus($task->taskId, TaskStatus::COMPLETED, 'Done');

        $result = $this->manager->cancelTask($task->taskId);

        $this->assertNotNull($result);
        $this->assertEquals(TaskStatus::COMPLETED, $result->status, 'Terminal task must keep its status');
    }

    public function testCancelAlreadyCancelledTaskIsIdempotent(): void {
        $task = $this->manager->createTask();
        $this->manager->cancelTask($task->taskId);

        $result = $this->manager->cancelTask($task->taskId);

        $this->assertNotNull($result);
        $this->assertEquals(TaskStatus::CANCELLED, $result->status);
    }

    public function testCancelFailedTaskIsIdempotent(): void {
        $task = $this->manager->createTask();
        $this->manager->updateStatus($task->taskId, TaskStatus::FAILED, 'Error');

        $result = $this->manager->cancelTask($task->taskId);

        $this->assertNotNull($result);
        $this->assertEquals(TaskStatus::FAILED, $result->status, 'Terminal task must keep its status');
    }

    public function testValidTransitions(): void {
        $task = $this->manager->createTask();
        $this->assertEquals(TaskStatus::WORKING, $task->status);

        // working -> input_required
        $task = $this->manager->updateStatus($task->taskId, TaskStatus::INPUT_REQUIRED);
        $this->assertEquals(TaskStatus::INPUT_REQUIRED, $task->status);

        // input_required -> working
        $task = $this->manager->updateStatus($task->taskId, TaskStatus::WORKING);
        $this->assertEquals(TaskStatus::WORKING, $task->status);

        // working -> completed
        $task = $this->manager->updateStatus($task->taskId, TaskStatus::COMPLETED);
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
    }

    public function testTaskLifecycle(): void {
        // Create
        $task = $this->manager->createTask(ttlMs: 300000);
        $this->assertEquals(TaskStatus::WORKING, $task->status);

        // Update to input_required
        $this->manager->updateStatus($task->taskId, TaskStatus::INPUT_REQUIRED, 'Need more info');
        $task = $this->manager->getTask($task->taskId);
        $this->assertEquals(TaskStatus::INPUT_REQUIRED, $task->status);

        // Update back to working
        $this->manager->updateStatus($task->taskId, TaskStatus::WORKING, 'Resuming');
        $task = $this->manager->getTask($task->taskId);
        $this->assertEquals(TaskStatus::WORKING, $task->status);

        // Complete, inlining the tool result
        $this->manager->complete($task->taskId, ['output' => 'success']);
        $task = $this->manager->getTask($task->taskId);
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);

        $record = $this->manager->getRecord($task->taskId);
        $this->assertEquals(['output' => 'success'], $record['result']);
    }
}
