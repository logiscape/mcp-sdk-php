<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * The task handle returned from `tools/call` when a server augments the call
 * as a task (SEP-2663, revision 2026-07-28).
 *
 * Wire shape is a FLAT intersection `Result & Task` discriminated by
 * `resultType: "task"` — the task fields sit directly on the result object,
 * NOT under a nested `task` key and NOT in `_meta`. `resultType: "task"` MUST
 * appear on no other result type. A CreateTaskResult MUST NOT carry `result`,
 * `error`, `inputRequests`, or `requestState`.
 */
class CreateTaskResult extends Result {
    public const RESULT_TYPE_TASK = 'task';

    public function __construct(
        public readonly Task $task,
        ?Meta $_meta = null,
    ) {
        parent::__construct($_meta);
        $this->resultType = self::RESULT_TYPE_TASK;
    }

    /**
     * @param array<string, mixed> $data
     */
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

        unset($data['resultType']);

        // Task fields are flat on the result object.
        $task = Task::fromArray($data);
        $obj = new self($task, $meta);
        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        parent::validate();
        $this->task->validate();
    }

    public function jsonSerialize(): mixed {
        $data = ['resultType' => self::RESULT_TYPE_TASK];
        if ($this->_meta !== null) {
            $data['_meta'] = $this->_meta;
        }
        return array_merge($data, $this->task->toWireFields());
    }
}
