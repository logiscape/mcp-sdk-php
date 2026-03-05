<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Request to get task status (experimental).
 */
class TaskGetRequest extends Request {
    public function __construct(
        public readonly string $taskId,
    ) {
        parent::__construct('tasks/get', new TaskIdParams($taskId));
    }

    public function validate(): void {
        parent::validate();
        if (empty($this->taskId)) {
            throw new \InvalidArgumentException('TaskGetRequest taskId cannot be empty');
        }
    }
}
