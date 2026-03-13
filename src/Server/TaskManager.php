<?php

declare(strict_types=1);

namespace Mcp\Server;

use Mcp\Types\Task;
use Mcp\Types\TaskStatus;

/**
 * Server-side task lifecycle manager (experimental).
 *
 * Uses file-based storage for PHP/cPanel compatibility — no long-running process required.
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

    public function createTask(?int $ttl = null, ?int $pollInterval = null): Task {
        $taskId = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $task = new Task(
            taskId: $taskId,
            status: TaskStatus::WORKING,
            createdAt: $now,
            lastUpdatedAt: $now,
            ttl: $ttl,
            pollInterval: $pollInterval,
        );

        $this->save($task);
        return $task;
    }

    public function getTask(string $taskId): ?Task {
        $data = $this->readTaskData($taskId);
        if ($data === null) {
            return null;
        }

        if ($this->isExpired($data)) {
            $this->deleteTask($taskId);
            return null;
        }

        return Task::fromArray($data);
    }

    public function updateStatus(string $taskId, string $status, ?string $statusMessage = null): ?Task {
        $task = $this->getTask($taskId);
        if ($task === null) {
            return null;
        }

        $allowedNext = self::VALID_TRANSITIONS[$task->status] ?? [];
        if (!in_array($status, $allowedNext, true)) {
            throw new \InvalidArgumentException(
                "Invalid task state transition from '{$task->status}' to '{$status}'"
            );
        }

        $task->status = $status;
        $task->statusMessage = $statusMessage;
        $task->lastUpdatedAt = gmdate('Y-m-d\TH:i:s\Z');

        $this->save($task);
        return $task;
    }

    public function setResult(string $taskId, mixed $result): void {
        $file = $this->resultFile($taskId);
        file_put_contents($file, json_encode($result), LOCK_EX);
    }

    public function getResult(string $taskId): mixed {
        $content = @file_get_contents($this->resultFile($taskId));
        if ($content === false) {
            return null;
        }
        return json_decode($content, true);
    }

    /**
     * @return Task[]
     */
    public function listTasks(): array {
        $tasks = [];
        $files = glob($this->storagePath . '/task_*.json');
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }

            $taskId = $data['taskId'] ?? '';
            if ($this->isExpired($data)) {
                $this->deleteTask($taskId);
                continue;
            }

            $tasks[] = Task::fromArray($data);
        }

        return $tasks;
    }

    public function cancelTask(string $taskId): ?Task {
        $task = $this->getTask($taskId);
        if ($task === null) {
            return null;
        }
        if (in_array($task->status, self::TERMINAL_STATES, true)) {
            throw new \InvalidArgumentException(
                "Cannot cancel task '{$taskId}' in terminal state '{$task->status}'"
            );
        }
        return $this->updateStatus($taskId, TaskStatus::CANCELLED);
    }

    public function deleteTask(string $taskId): void {
        @unlink($this->taskFile($taskId));
        @unlink($this->resultFile($taskId));
    }

    public function cleanup(): void {
        $files = glob($this->storagePath . '/task_*.json');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!is_array($data)) {
                @unlink($file);
                continue;
            }

            if ($this->isExpired($data)) {
                $this->deleteTask($data['taskId'] ?? '');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isExpired(array $data): bool {
        if (!isset($data['ttl'], $data['createdAt'])) {
            return false;
        }
        $createdAt = strtotime($data['createdAt']);
        // TTL is in milliseconds per spec, convert to seconds for comparison
        $ttlSeconds = $data['ttl'] / 1000;
        return $createdAt !== false && (time() - $createdAt) > $ttlSeconds;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readTaskData(string $taskId): ?array {
        $content = @file_get_contents($this->taskFile($taskId));
        if ($content === false) {
            return null;
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    private function save(Task $task): void {
        $file = $this->taskFile($task->taskId);
        file_put_contents($file, json_encode($task), LOCK_EX);
    }

    private function taskFile(string $taskId): string {
        return $this->storagePath . '/task_' . hash('sha256', $taskId) . '.json';
    }

    private function resultFile(string $taskId): string {
        return $this->storagePath . '/result_' . hash('sha256', $taskId) . '.json';
    }
}
