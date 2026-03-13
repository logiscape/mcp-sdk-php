<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Result of tasks/list (experimental). Supports pagination.
 */
class TaskListResult extends PaginatedResult {
    /**
     * @param Task[] $tasks
     */
    public function __construct(
        public readonly array $tasks,
        ?string $nextCursor = null,
        ?Meta $_meta = null,
    ) {
        parent::__construct($nextCursor, $_meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromResponseData(array $data): self {
        [$meta, $nextCursor, $data] = self::extractPaginatedBase($data);

        $tasksData = $data['tasks'] ?? [];
        unset($data['tasks']);

        $tasks = [];
        foreach ($tasksData as $taskData) {
            $tasks[] = Task::fromArray($taskData);
        }

        $obj = new self($tasks, $nextCursor, $meta);

        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        parent::validate();
        foreach ($this->tasks as $task) {
            $task->validate();
        }
    }

    public function jsonSerialize(): mixed {
        $data = parent::jsonSerialize();
        if ($data instanceof \stdClass) {
            $data = (array) $data;
        }
        $data['tasks'] = array_map(fn(Task $t) => $t->jsonSerialize(), $this->tasks);
        return $data;
    }
}
