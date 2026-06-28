<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Result of `tasks/get` (SEP-2663, revision 2026-07-28).
 *
 * A FLAT intersection `Result & DetailedTask` with `resultType: "complete"`.
 * The task handle fields sit at the top level; the status discriminates which
 * extra field is present:
 *
 *   - working / cancelled → task fields only
 *   - input_required      → `inputRequests` (keyed map of full
 *                           ElicitRequest / CreateMessageRequest /
 *                           ListRootsRequest objects {method, params})
 *   - completed           → `result` (the inlined original tool result, e.g.
 *                           a CallToolResult with non-empty content[])
 *   - failed              → `error` ({code, message, data?}), no `result`
 *
 * MUST NOT carry `requestState`; the inlined `result._meta` MUST NOT carry the
 * removed `io.modelcontextprotocol/related-task` key.
 */
class TaskGetResult extends Result {
    /**
     * @param array<string, mixed>|null $result Inlined result when completed
     * @param array<string, mixed>|null $error Inlined error when failed
     * @param array<string, array{method: string, params: mixed}>|null $inputRequests
     *        Pending input requests when input_required
     */
    public function __construct(
        public readonly Task $task,
        public ?array $result = null,
        public ?array $error = null,
        public ?array $inputRequests = null,
        ?Meta $_meta = null,
    ) {
        parent::__construct($_meta);
        $this->resultType = Result::RESULT_TYPE_COMPLETE;
    }

    /**
     * Build from a Task handle plus the inlined detail appropriate to its
     * status.
     *
     * @param array<string, mixed>|null $result
     * @param array<string, mixed>|null $error
     * @param array<string, array{method: string, params: mixed}>|null $inputRequests
     */
    public static function fromTask(
        Task $task,
        ?array $result = null,
        ?array $error = null,
        ?array $inputRequests = null,
        ?Meta $meta = null,
    ): self {
        return new self($task, $result, $error, $inputRequests, $meta);
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

        $result = isset($data['result']) && is_array($data['result']) ? $data['result'] : null;
        $error = isset($data['error']) && is_array($data['error']) ? $data['error'] : null;
        $inputRequests = isset($data['inputRequests']) && is_array($data['inputRequests']) ? $data['inputRequests'] : null;
        unset($data['result'], $data['error'], $data['inputRequests']);

        $task = Task::fromArray($data);
        $obj = new self($task, $result, $error, $inputRequests, $meta);
        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        parent::validate();
        $this->task->validate();
    }

    public function jsonSerialize(): mixed {
        $data = ['resultType' => Result::RESULT_TYPE_COMPLETE];
        if ($this->_meta !== null) {
            $data['_meta'] = $this->_meta;
        }
        $data = array_merge($data, $this->task->toWireFields());

        if ($this->inputRequests !== null) {
            $data['inputRequests'] = empty($this->inputRequests) ? new \stdClass() : $this->inputRequests;
        }
        if ($this->result !== null) {
            $data['result'] = $this->result;
        }
        if ($this->error !== null) {
            $data['error'] = $this->error;
        }
        return $data;
    }
}
