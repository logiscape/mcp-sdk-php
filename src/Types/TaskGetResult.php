<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Result of tasks/get (experimental).
 *
 * Per spec, tasks/get returns the Task fields directly at the top level
 * (taskId, status, statusMessage, createdAt, lastUpdatedAt, ttl, pollInterval).
 */
class TaskGetResult extends Result {
    public function __construct(
        public readonly string $taskId,
        public string $status,
        public ?string $statusMessage = null,
        public ?string $createdAt = null,
        public ?string $lastUpdatedAt = null,
        public ?int $ttl = null,
        public ?int $pollInterval = null,
        ?Meta $_meta = null,
    ) {
        parent::__construct($_meta);
    }

    /**
     * Create from a Task object.
     */
    public static function fromTask(Task $task, ?Meta $meta = null): self {
        return new self(
            taskId: $task->taskId,
            status: $task->status,
            statusMessage: $task->statusMessage,
            createdAt: $task->createdAt,
            lastUpdatedAt: $task->lastUpdatedAt,
            ttl: $task->ttl,
            pollInterval: $task->pollInterval,
            _meta: $meta,
        );
    }

    public static function fromResponseData(array $data): self {
        $meta = null;
        if (isset($data['_meta'])) {
            $metaData = $data['_meta'];
            unset($data['_meta']);
            $meta = new Meta();
            foreach ($metaData as $k => $v) {
                $meta->$k = $v;
            }
        }

        $obj = new self(
            taskId: $data['taskId'] ?? '',
            status: $data['status'] ?? '',
            statusMessage: $data['statusMessage'] ?? null,
            createdAt: $data['createdAt'] ?? null,
            lastUpdatedAt: $data['lastUpdatedAt'] ?? null,
            ttl: isset($data['ttl']) ? (int) $data['ttl'] : null,
            pollInterval: isset($data['pollInterval']) ? (int) $data['pollInterval'] : null,
            _meta: $meta,
        );

        unset($data['taskId'], $data['status'], $data['statusMessage'],
              $data['createdAt'], $data['lastUpdatedAt'], $data['ttl'], $data['pollInterval']);

        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        parent::validate();
        if (empty($this->taskId)) {
            throw new \InvalidArgumentException('Task taskId cannot be empty');
        }
        if (!TaskStatus::isValid($this->status)) {
            throw new \InvalidArgumentException("Invalid task status: {$this->status}");
        }
    }

    public function jsonSerialize(): mixed {
        $data = parent::jsonSerialize();
        if ($data instanceof \stdClass) {
            $data = (array) $data;
        }
        $data['taskId'] = $this->taskId;
        $data['status'] = $this->status;
        if ($this->statusMessage !== null) {
            $data['statusMessage'] = $this->statusMessage;
        }
        if ($this->createdAt !== null) {
            $data['createdAt'] = $this->createdAt;
        }
        if ($this->lastUpdatedAt !== null) {
            $data['lastUpdatedAt'] = $this->lastUpdatedAt;
        }
        if ($this->ttl !== null) {
            $data['ttl'] = $this->ttl;
        }
        if ($this->pollInterval !== null) {
            $data['pollInterval'] = $this->pollInterval;
        }
        return $data;
    }
}
