<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Represents a task (experimental).
 */
class Task implements McpModel {
    use ExtraFieldsTrait;

    public function __construct(
        public readonly string $taskId,
        public string $status,
        public ?string $statusMessage = null,
        public ?string $createdAt = null,
        public ?string $lastUpdatedAt = null,
        public ?int $ttl = null,
        public ?int $pollInterval = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $taskId = $data['taskId'] ?? '';
        $status = $data['status'] ?? '';
        $statusMessage = $data['statusMessage'] ?? null;
        $createdAt = $data['createdAt'] ?? null;
        $lastUpdatedAt = $data['lastUpdatedAt'] ?? null;
        $ttl = isset($data['ttl']) ? (int)$data['ttl'] : null;
        $pollInterval = isset($data['pollInterval']) ? (int)$data['pollInterval'] : null;

        unset($data['taskId'], $data['status'], $data['statusMessage'],
              $data['createdAt'], $data['lastUpdatedAt'], $data['ttl'], $data['pollInterval']);

        $obj = new self($taskId, $status, $statusMessage, $createdAt, $lastUpdatedAt, $ttl, $pollInterval);

        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        if (empty($this->taskId)) {
            throw new \InvalidArgumentException('Task taskId cannot be empty');
        }
        if (!TaskStatus::isValid($this->status)) {
            throw new \InvalidArgumentException("Invalid task status: {$this->status}");
        }
    }

    public function jsonSerialize(): mixed {
        $data = [
            'taskId' => $this->taskId,
            'status' => $this->status,
        ];
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
        return array_merge($data, $this->extraFields);
    }
}
