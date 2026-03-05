<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Result containing a task object (experimental).
 */
class CreateTaskResult extends Result {
    public function __construct(
        public readonly Task $task,
        ?Meta $_meta = null,
    ) {
        parent::__construct($_meta);
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

        $taskData = $data['task'] ?? [];
        unset($data['task']);

        $task = Task::fromArray($taskData);
        $obj = new self($task, $meta);

        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        parent::validate();
        $this->task->validate();
    }
}
