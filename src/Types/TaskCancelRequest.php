<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Request to cancel a task (experimental).
 */
class TaskCancelRequest extends Request {
    public function __construct(
        public readonly string $taskId,
    ) {
        parent::__construct('tasks/cancel', new TaskIdParams($taskId));
    }

    public function validate(): void {
        parent::validate();
        if (empty($this->taskId)) {
            throw new \InvalidArgumentException('TaskCancelRequest taskId cannot be empty');
        }
    }
}
