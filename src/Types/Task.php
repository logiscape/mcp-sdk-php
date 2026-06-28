<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * A task handle (SEP-2663 Tasks extension, revision 2026-07-28).
 *
 * The fields a server returns when a `tools/call` is augmented as a task and
 * the core of the `tasks/get` response. Wire shape (ext-tasks `schema/draft`):
 *
 *   {
 *     taskId: string,
 *     status: "working" | "input_required" | "completed" | "failed" | "cancelled",
 *     statusMessage?: string,
 *     createdAt: string,        // ISO 8601
 *     lastUpdatedAt: string,    // ISO 8601
 *     ttlMs: number | null,     // always present; null = unlimited
 *     pollIntervalMs?: number
 *   }
 *
 * Note the SEP-2663 field renames from the pre-release surface: `ttl` →
 * `ttlMs`, `pollInterval` → `pollIntervalMs`. `ttlMs` is ALWAYS emitted (as
 * JSON null when unlimited); the legacy `ttl` / `pollInterval` keys never
 * appear on the wire.
 */
class Task implements McpModel {
    use ExtraFieldsTrait;

    public function __construct(
        public readonly string $taskId,
        public string $status,
        public ?string $statusMessage = null,
        public ?string $createdAt = null,
        public ?string $lastUpdatedAt = null,
        public ?int $ttlMs = null,
        public ?int $pollIntervalMs = null,
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
        $ttlMs = array_key_exists('ttlMs', $data) && $data['ttlMs'] !== null ? (int) $data['ttlMs'] : null;
        $pollIntervalMs = isset($data['pollIntervalMs']) ? (int) $data['pollIntervalMs'] : null;

        unset($data['taskId'], $data['status'], $data['statusMessage'],
              $data['createdAt'], $data['lastUpdatedAt'], $data['ttlMs'], $data['pollIntervalMs']);

        $obj = new self($taskId, $status, $statusMessage, $createdAt, $lastUpdatedAt, $ttlMs, $pollIntervalMs);

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

    /**
     * Serialize the task's wire fields into an associative array, in the
     * canonical order. Shared by every result that embeds a task handle
     * (CreateTaskResult, the tasks/get DetailedTask), so the field set and
     * ordering never diverge.
     *
     * @return array<string, mixed>
     */
    public function toWireFields(): array {
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
        // ttlMs is required on the wire (null = unlimited), so it is emitted
        // unconditionally; pollIntervalMs is optional.
        $data['ttlMs'] = $this->ttlMs;
        if ($this->pollIntervalMs !== null) {
            $data['pollIntervalMs'] = $this->pollIntervalMs;
        }
        return $data;
    }

    public function jsonSerialize(): mixed {
        return array_merge($this->toWireFields(), $this->extraFields);
    }
}
