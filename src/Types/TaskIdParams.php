<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Request params containing a taskId field.
 * Used by tasks/get, tasks/result, and tasks/cancel.
 */
class TaskIdParams extends RequestParams {
    public function __construct(
        public readonly string $taskId,
        ?Meta $_meta = null,
    ) {
        parent::__construct($_meta);
    }

    public function validate(): void {
        parent::validate();
        if (empty($this->taskId)) {
            throw new \InvalidArgumentException('taskId cannot be empty');
        }
    }

    public function jsonSerialize(): mixed {
        $data = parent::jsonSerialize();
        if ($data instanceof \stdClass) {
            $data = (array) $data;
        }
        $data['taskId'] = $this->taskId;
        return $data;
    }
}
