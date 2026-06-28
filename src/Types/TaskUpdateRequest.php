<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Request to fulfill outstanding input requests for an `input_required` task
 * (`tasks/update`, SEP-2663, revision 2026-07-28).
 */
class TaskUpdateRequest extends Request {
    /**
     * @param array<string, mixed> $inputResponses
     */
    public function __construct(
        public readonly string $taskId,
        array $inputResponses = [],
    ) {
        parent::__construct('tasks/update', new TaskUpdateParams($taskId, $inputResponses));
    }

    public function validate(): void {
        parent::validate();
        if (empty($this->taskId)) {
            throw new \InvalidArgumentException('TaskUpdateRequest taskId cannot be empty');
        }
    }
}
