<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Params for `tasks/update` (SEP-2663, revision 2026-07-28):
 * `{ taskId, inputResponses }`.
 *
 * `inputResponses` is a keyed map whose keys match the `inputRequests` the
 * task surfaced via `tasks/get`; each value is the corresponding
 * `ElicitResult` / `CreateMessageResult` / `ListRootsResult`. Partial maps are
 * allowed — the task stays `input_required` until all keys are answered.
 */
class TaskUpdateParams extends RequestParams {
    /**
     * @param array<string, mixed> $inputResponses
     */
    public function __construct(
        public readonly string $taskId,
        public array $inputResponses = [],
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
        $data['inputResponses'] = empty($this->inputResponses)
            ? new \stdClass()
            : $this->inputResponses;
        return $data;
    }
}
