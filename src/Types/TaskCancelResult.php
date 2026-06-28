<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Result of `tasks/cancel` (SEP-2663, revision 2026-07-28).
 *
 * An empty acknowledgement: exactly `{ "resultType": "complete" }`. Cancellation
 * is cooperative and eventually-consistent — the server only acknowledges the
 * request; the task may settle to a terminal status other than `cancelled`.
 * MUST NOT carry taskId, status, result, error, or inputRequests.
 */
class TaskCancelResult extends Result {
    public function __construct(?Meta $_meta = null) {
        parent::__construct($_meta);
        $this->resultType = Result::RESULT_TYPE_COMPLETE;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromResponseData(array $data): self {
        $meta = null;
        if (isset($data['_meta'])) {
            $meta = new Meta();
            foreach ($data['_meta'] as $k => $v) {
                $meta->$k = $v;
            }
        }
        return new self($meta);
    }

    public function jsonSerialize(): mixed {
        $data = ['resultType' => Result::RESULT_TYPE_COMPLETE];
        if ($this->_meta !== null) {
            $data['_meta'] = $this->_meta;
        }
        return $data;
    }
}
