<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Request to get a completed task's result (experimental).
 */
class TaskResultRequest extends Request {
    public function __construct(
        public readonly string $taskId,
    ) {
        parent::__construct('tasks/result', new TaskIdParams($taskId));
    }

    public function validate(): void {
        parent::validate();
        if (empty($this->taskId)) {
            throw new \InvalidArgumentException('TaskResultRequest taskId cannot be empty');
        }
    }
}
