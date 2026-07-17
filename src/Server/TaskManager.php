<?php

declare(strict_types=1);

namespace Mcp\Server;

use Mcp\Server\Tasks\TaskTransitionRejectedException;
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
 *   working → working (self-transition: statusMessage/progress refresh)
 *   working → completed | failed | cancelled
 *   input_required → completed | failed | cancelled
 *   {completed, failed, cancelled} are terminal (immutable).
 *
 * TTL is `ttlMs` measured from `createdAt` (null = unlimited). Once elapsed
 * the record is treated as gone and removed on the next access.
 *
 * Concurrency: every state transition runs its complete read-validate-write
 * under a per-record advisory lock (a `.lock` sidecar file, flock LOCK_EX),
 * reads take LOCK_SH against the record file, and writes open non-truncating
 * ('c') and truncate only after LOCK_EX is held — so two processes (an
 * out-of-band worker vs. a `tasks/cancel` request, say) cannot interleave a
 * stale read into a write that resurrects a terminal task, and a reader
 * never observes a half-written record. The locks are advisory and assume a
 * local filesystem, like the file store itself.
 */
class TaskManager
{
    private string $storagePath;

    private const VALID_TRANSITIONS = [
        TaskStatus::WORKING => [TaskStatus::WORKING, TaskStatus::INPUT_REQUIRED, TaskStatus::COMPLETED, TaskStatus::FAILED, TaskStatus::CANCELLED],
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
     * and other explicit moves, including the working → working progress
     * refresh an out-of-band worker sends). Returns null when the task is
     * gone; throws on an illegal transition.
     *
     * A move to `working` clears any pending `inputRequests`/`requestState`:
     * a working task must surface as a handle-only `tasks/get` response, so
     * stale input state (e.g. a resumed tool deferring instead of re-parking)
     * must not ride along.
     *
     * @throws TaskTransitionRejectedException On an illegal transition
     */
    public function updateStatus(string $taskId, string $status, ?string $statusMessage = null): ?Task
    {
        return $this->withRecordLock($taskId, function () use ($taskId, $status, $statusMessage): ?Task {
            $record = $this->getRecord($taskId);
            if ($record === null) {
                return null;
            }
            $this->assertTransition((string) $record['status'], $status);

            $record['status'] = $status;
            $record['statusMessage'] = $statusMessage;
            $record['lastUpdatedAt'] = self::now();
            if ($status === TaskStatus::WORKING) {
                unset($record['inputRequests'], $record['requestState']);
            }
            $this->saveRecord($record);

            return Task::fromArray($record);
        });
    }

    /**
     * Move a task to `completed`, inlining the tool result the `tasks/get`
     * response will carry. Clears any pending input requests.
     *
     * @param array<string, mixed> $result The original result (e.g. a
     *        CallToolResult-shaped array)
     * @throws TaskTransitionRejectedException When the task is already
     *         terminal (e.g. cancelled first — see "Cancellation races"
     *         in the Tasks guide)
     */
    public function complete(string $taskId, array $result): ?Task
    {
        return $this->withRecordLock($taskId, function () use ($taskId, $result): ?Task {
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
        });
    }

    /**
     * Move a task to `failed`, inlining the JSON-RPC error the `tasks/get`
     * response will carry.
     *
     * @param array{code: int, message: string, data?: mixed} $error
     * @throws TaskTransitionRejectedException When the task is already
     *         terminal (e.g. cancelled first — see "Cancellation races"
     *         in the Tasks guide)
     */
    public function fail(string $taskId, array $error, ?string $statusMessage = null): ?Task
    {
        return $this->withRecordLock($taskId, function () use ($taskId, $error, $statusMessage): ?Task {
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
        });
    }

    /**
     * Park a task in `input_required`, recording the outstanding input
     * requests (keyed map of {method, params}) and the signed requestState a
     * later `tasks/update` echoes to resume the tool.
     *
     * @param array<string, array{method: string, params: mixed}> $inputRequests
     * @throws TaskTransitionRejectedException On an illegal transition
     */
    public function setInputRequired(string $taskId, array $inputRequests, ?string $requestState = null): ?Task
    {
        return $this->withRecordLock($taskId, function () use ($taskId, $inputRequests, $requestState): ?Task {
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
        });
    }

    /**
     * Acknowledge a cancellation request (SEP-2663: cooperative and
     * eventually consistent). A task already in a terminal state is left
     * untouched (idempotent ack); a live task transitions to `cancelled`.
     * Returns null only when the task is gone.
     */
    public function cancelTask(string $taskId): ?Task
    {
        return $this->withRecordLock($taskId, function () use ($taskId): ?Task {
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
        });
    }

    public function deleteTask(string $taskId): void
    {
        @unlink($this->taskFile($taskId));
        @unlink($this->taskFile($taskId) . '.lock');
    }

    /**
     * Remove every expired task. Useful for a periodic cron sweep on
     * shared hosting where nothing else triggers expiry. For a store too
     * large to examine in one cron budget, use {@see sweep()} — this method
     * is exactly an unbounded `sweep()`.
     */
    public function cleanup(): void
    {
        $this->sweep();
    }

    /**
     * Bounded, resumable variant of {@see cleanup()} for stores too large
     * to examine within one cron budget.
     *
     * `$maxFiles` bounds the expensive per-record work — the locked read,
     * expiry check, and deletion of each examined record. The directory
     * enumeration itself (one `glob()` plus an in-memory sort/filter of
     * the filenames) still covers the whole store on every call, and a
     * completing call additionally stats each lock sidecar for the orphan
     * sweep; both are cheap relative to per-record locking but do grow
     * with store size.
     *
     * Examines up to `$maxFiles` task records in stable (lexicographic
     * filename) order, deleting expired or corrupt ones, and returns a
     * cursor for the next call to resume after:
     *
     * ```php
     * $state = json_decode(@file_get_contents($stateFile), true);
     * $r = $tasks->sweep(maxFiles: 500, cursor: $state['cursor'] ?? null);
     * file_put_contents($stateFile, json_encode(['cursor' => $r['cursor']]));
     * ```
     *
     * A returned `cursor` of `null` means the pass reached the end of the
     * store (start over from the beginning next run); orphaned lock
     * sidecars are swept only on such a completing call, keeping bounded
     * calls' per-record work bounded. Records created during a multi-run
     * pass may sort before the cursor and be picked up by the next pass
     * instead, and a record whose deletion fails (e.g. a concurrent open
     * handle on Windows) is left uncounted, keeps its lock sidecar, and is
     * retried on a later pass — expiry is also enforced on every record
     * access, so a sweep is a space-reclamation aid, never a correctness
     * requirement.
     *
     * @param int|null $maxFiles Maximum records to examine (null = all)
     * @param string|null $cursor Resume after this record filename (a
     *        previous call's `cursor`); null starts from the beginning
     * @return array{examined: int, deleted: int, cursor: string|null}
     *         Records examined and deleted this call, and the resume
     *         cursor (null when the store's listing was completed)
     * @throws \InvalidArgumentException If $maxFiles is less than 1
     */
    public function sweep(?int $maxFiles = null, ?string $cursor = null): array
    {
        if ($maxFiles !== null && $maxFiles < 1) {
            throw new \InvalidArgumentException("maxFiles must be at least 1 or null, got {$maxFiles}");
        }

        $files = glob($this->storagePath . '/task_*.json') ?: [];
        sort($files, SORT_STRING);
        if ($cursor !== null) {
            $files = array_values(array_filter(
                $files,
                static fn (string $file): bool => strcmp(basename($file), $cursor) > 0
            ));
        }
        $slice = $maxFiles === null ? $files : array_slice($files, 0, $maxFiles);

        $deleted = 0;
        foreach ($slice as $file) {
            // Locked read: an unlocked read here could catch a concurrent
            // save mid-write and delete a LIVE task as "corrupt".
            $record = $this->readRecordFile($file);
            if ($record === null || $this->isExpired($record)) {
                // Count only confirmed removals, and keep the lock sidecar
                // whenever the record remains (Windows share violations,
                // filesystem errors): a still-present record must keep its
                // lock, and the record is re-examined on a later pass.
                if (@unlink($file)) {
                    @unlink($file . '.lock');
                    $deleted++;
                }
            }
        }

        if (count($slice) < count($files)) {
            return [
                'examined' => count($slice),
                'deleted' => $deleted,
                'cursor' => basename($slice[count($slice) - 1]),
            ];
        }

        // Listing completed: sweep lock sidecars whose record is gone (a
        // mutation attempted on a missing/expired task leaves one behind;
        // deleteTask can also fail to remove a lock currently held on
        // Windows).
        foreach (glob($this->storagePath . '/task_*.json.lock') ?: [] as $lockFile) {
            if (!file_exists(substr($lockFile, 0, -5))) {
                @unlink($lockFile);
            }
        }

        return ['examined' => count($slice), 'deleted' => $deleted, 'cursor' => null];
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
            throw new TaskTransitionRejectedException($from, $to);
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
     * Run a state transition's complete read-validate-write as a critical
     * section, serialized per record against other processes via an
     * exclusive advisory lock on a `.lock` sidecar (the sidecar keeps the
     * lock handle distinct from saveRecord()'s own LOCK_EX on the record
     * file). Degrades to the unlocked behavior when the sidecar cannot be
     * opened, mirroring the store's best-effort @-suppressed file handling.
     *
     * @param \Closure(): ?Task $fn
     */
    private function withRecordLock(string $taskId, \Closure $fn): ?Task
    {
        $handle = @fopen($this->taskFile($taskId) . '.lock', 'c');
        if ($handle === false) {
            return $fn();
        }
        try {
            flock($handle, LOCK_EX);
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Read a record under a shared lock, so a concurrent saveRecord() (which
     * truncates then writes under LOCK_EX on the same file) can never be
     * observed half-written as a corrupt — hence seemingly missing — task.
     *
     * @return array<string, mixed>|null
     */
    private function readRecord(string $taskId): ?array
    {
        return $this->readRecordFile($this->taskFile($taskId));
    }

    /**
     * @return array<string, mixed>|null Null when the file is gone or its
     *         content is not a JSON object.
     */
    private function readRecordFile(string $file): ?array
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return null;
        }
        try {
            flock($handle, LOCK_SH);
            $content = stream_get_contents($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
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
     * Write a record under LOCK_EX, truncating only INSIDE the critical
     * section. file_put_contents(LOCK_EX) is unusable here: it opens "wb",
     * which truncates before the lock is acquired (per the PHP docs the
     * lock happens between fopen() and fwrite()), so a LOCK_SH reader could
     * observe an empty record. Mode 'c' creates without truncating — the
     * same pattern RequestStateCodec uses for its secret file.
     *
     * @param array<string, mixed> $record
     */
    private function saveRecord(array $record): void
    {
        $file = $this->taskFile((string) $record['taskId']);
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            return;
        }
        try {
            flock($handle, LOCK_EX);
            ftruncate($handle, 0);
            fwrite($handle, (string) json_encode($record));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
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
