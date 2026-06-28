<?php

declare(strict_types=1);

namespace Mcp\Server;

use Mcp\Types\Task;
use Mcp\Types\TaskStatus;

/**
 * Server-side task lifecycle store for the SEP-2663 Tasks extension
 * (revision 2026-07-28).
 *
 * File-based for shared-hosting (cPanel/Apache/FPM) compatibility — no
 * long-running process is required, and a task created in one request can be
 * advanced (completed/failed/resumed) from another. Each task is one JSON
 * record holding the task handle plus the inlined detail the `tasks/get`
 * response needs: the completed `result`, the failed `error`, or the pending
 * `inputRequests` (with the signed `requestState` that lets `tasks/update`
 * resume the parked tool).
 *
 * State machine (SEP-2663):
 *   working ⇄ input_required
 *   working → completed | failed | cancelled
 *   input_required → completed | failed | cancelled
 *   {completed, failed, cancelled} are terminal (immutable).
 *
 * TTL is `ttlMs` measured from `createdAt` (null = unlimited). Once elapsed
 * the record is treated as gone and removed on the next access.
 */
class TaskManager
{
    private string $storagePath;

    private const VALID_TRANSITIONS = [
        TaskStatus::WORKING => [TaskStatus::INPUT_REQUIRED, TaskStatus::COMPLETED, TaskStatus::FAILED, TaskStatus::CANCELLED],
        TaskStatus::INPUT_REQUIRED => [TaskStatus::WORKING, TaskStatus::COMPLETED, TaskStatus::FAILED, TaskStatus::CANCELLED],
        TaskStatus::COMPLETED => [],
        TaskStatus::FAILED => [],
        TaskStatus::CANCELLED => [],
    ];

    private const TERMINAL_STATES = [
        TaskStatus::COMPLETED,
        TaskStatus::FAILED,
        TaskStatus::CANCELLED,
    ];

    public function __construct(string $storagePath = '')
    {
        $this->storagePath = $storagePath ?: sys_get_temp_dir() . '/mcp_tasks';
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0755, true);
            if (!is_dir($this->storagePath)) {
                throw new \RuntimeException("Failed to create task storage directory: {$this->storagePath}");
            }
        }
    }

    /**
     * Create a new task in the `working` state.
     *
     * @param int|null $ttlMs Lifetime from creation in milliseconds (null = unlimited)
     * @param int|null $pollIntervalMs Suggested client poll interval in milliseconds
     * @param string|null $toolName Originating tool (enables tasks/update resume)
     * @param array<string, mixed>|null $toolArguments Original tool arguments
     */
    public function createTask(
        ?int $ttlMs = null,
        ?int $pollIntervalMs = null,
        ?string $toolName = null,
        ?array $toolArguments = null,
    ): Task {
        $taskId = bin2hex(random_bytes(16));
        $now = self::now();

        $task = new Task(
            taskId: $taskId,
            status: TaskStatus::WORKING,
            createdAt: $now,
            lastUpdatedAt: $now,
            ttlMs: $ttlMs,
            pollIntervalMs: $pollIntervalMs,
        );

        $record = $this->taskToRecord($task);
        if ($toolName !== null) {
            $record['toolName'] = $toolName;
        }
        if ($toolArguments !== null) {
            $record['toolArguments'] = $toolArguments;
        }
        $this->saveRecord($record);

        return $task;
    }

    /**
     * Return the task handle, or null when it does not exist or has expired
     * (the record is removed on expiry).
     */
    public function getTask(string $taskId): ?Task
    {
        $record = $this->getRecord($taskId);
        return $record === null ? null : Task::fromArray($record);
    }

    /**
     * Return the full stored record (handle fields plus result/error/
     * inputRequests/requestState/toolName/toolArguments), or null when the
     * task does not exist or has expired.
     *
     * @return array<string, mixed>|null
     */
    public function getRecord(string $taskId): ?array
    {
        $record = $this->readRecord($taskId);
        if ($record === null) {
            return null;
        }
        if ($this->isExpired($record)) {
            $this->deleteTask($taskId);
            return null;
        }
        return $record;
    }

    /**
     * Apply a validated state transition (used for working ⇄ input_required
     * and other explicit moves). Returns null when the task is gone; throws
     * on an illegal transition.
     */
    public function updateStatus(string $taskId, string $status, ?string $statusMessage = null): ?Task
    {
        $record = $this->getRecord($taskId);
        if ($record === null) {
            return null;
        }
        $this->assertTransition((string) $record['status'], $status);

        $record['status'] = $status;
        $record['statusMessage'] = $statusMessage;
        $record['lastUpdatedAt'] = self::now();
        $this->saveRecord($record);

        return Task::fromArray($record);
    }

    /**
     * Move a task to `completed`, inlining the tool result the `tasks/get`
     * response will carry. Clears any pending input requests.
     *
     * @param array<string, mixed> $result The original result (e.g. a
     *        CallToolResult-shaped array)
     */
    public function complete(string $taskId, array $result): ?Task
    {
        $record = $this->getRecord($taskId);
        if ($record === null) {
            return null;
        }
        $this->assertTransition((string) $record['status'], TaskStatus::COMPLETED);

        $record['status'] = TaskStatus::COMPLETED;
        $record['lastUpdatedAt'] = self::now();
        $record['result'] = $result;
        unset($record['error'], $record['inputRequests'], $record['requestState']);
        $this->saveRecord($record);

        return Task::fromArray($record);
    }

    /**
     * Move a task to `failed`, inlining the JSON-RPC error the `tasks/get`
     * response will carry.
     *
     * @param array{code: int, message: string, data?: mixed} $error
     */
    public function fail(string $taskId, array $error, ?string $statusMessage = null): ?Task
    {
        $record = $this->getRecord($taskId);
        if ($record === null) {
            return null;
        }
        $this->assertTransition((string) $record['status'], TaskStatus::FAILED);

        $record['status'] = TaskStatus::FAILED;
        $record['statusMessage'] = $statusMessage;
        $record['lastUpdatedAt'] = self::now();
        $record['error'] = $error;
        unset($record['result'], $record['inputRequests'], $record['requestState']);
        $this->saveRecord($record);

        return Task::fromArray($record);
    }

    /**
     * Park a task in `input_required`, recording the outstanding input
     * requests (keyed map of {method, params}) and the signed requestState a
     * later `tasks/update` echoes to resume the tool.
     *
     * @param array<string, array{method: string, params: mixed}> $inputRequests
     */
    public function setInputRequired(string $taskId, array $inputRequests, ?string $requestState = null): ?Task
    {
        $record = $this->getRecord($taskId);
        if ($record === null) {
            return null;
        }
        $current = (string) $record['status'];
        if ($current !== TaskStatus::INPUT_REQUIRED) {
            $this->assertTransition($current, TaskStatus::INPUT_REQUIRED);
            $record['status'] = TaskStatus::INPUT_REQUIRED;
        }
        $record['lastUpdatedAt'] = self::now();
        $record['inputRequests'] = $inputRequests;
        if ($requestState !== null) {
            $record['requestState'] = $requestState;
        }
        $this->saveRecord($record);

        return Task::fromArray($record);
    }

    /**
     * Acknowledge a cancellation request (SEP-2663: cooperative and
     * eventually consistent). A task already in a terminal state is left
     * untouched (idempotent ack); a live task transitions to `cancelled`.
     * Returns null only when the task is gone.
     */
    public function cancelTask(string $taskId): ?Task
    {
        $record = $this->getRecord($taskId);
        if ($record === null) {
            return null;
        }
        if (in_array($record['status'], self::TERMINAL_STATES, true)) {
            return Task::fromArray($record);
        }

        $record['status'] = TaskStatus::CANCELLED;
        $record['lastUpdatedAt'] = self::now();
        unset($record['inputRequests'], $record['requestState']);
        $this->saveRecord($record);

        return Task::fromArray($record);
    }

    public function deleteTask(string $taskId): void
    {
        @unlink($this->taskFile($taskId));
    }

    /**
     * Remove every expired task. Useful for a periodic cron sweep on
     * shared hosting where nothing else triggers expiry.
     */
    public function cleanup(): void
    {
        $files = glob($this->storagePath . '/task_*.json');
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            $record = $content === false ? null : json_decode($content, true);
            if (!is_array($record)) {
                @unlink($file);
                continue;
            }
            if ($this->isExpired($record)) {
                @unlink($file);
            }
        }
    }

    /**
     * Whether a status is terminal (immutable).
     */
    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATES, true);
    }

    private function assertTransition(string $from, string $to): void
    {
        $allowed = self::VALID_TRANSITIONS[$from] ?? [];
        if (!in_array($to, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Invalid task state transition from '{$from}' to '{$to}'"
            );
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function isExpired(array $record): bool
    {
        $ttlMs = $record['ttlMs'] ?? null;
        if ($ttlMs === null || !isset($record['createdAt'])) {
            return false;
        }
        $createdAt = strtotime((string) $record['createdAt']);
        if ($createdAt === false) {
            return false;
        }
        $ttlSeconds = ((int) $ttlMs) / 1000;
        return (time() - $createdAt) > $ttlSeconds;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readRecord(string $taskId): ?array
    {
        $content = @file_get_contents($this->taskFile($taskId));
        if ($content === false) {
            return null;
        }
        $record = json_decode($content, true);
        return is_array($record) ? $record : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function taskToRecord(Task $task): array
    {
        return [
            'taskId' => $task->taskId,
            'status' => $task->status,
            'statusMessage' => $task->statusMessage,
            'createdAt' => $task->createdAt,
            'lastUpdatedAt' => $task->lastUpdatedAt,
            'ttlMs' => $task->ttlMs,
            'pollIntervalMs' => $task->pollIntervalMs,
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function saveRecord(array $record): void
    {
        $file = $this->taskFile((string) $record['taskId']);
        file_put_contents($file, json_encode($record), LOCK_EX);
    }

    private function taskFile(string $taskId): string
    {
        return $this->storagePath . '/task_' . hash('sha256', $taskId) . '.json';
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
