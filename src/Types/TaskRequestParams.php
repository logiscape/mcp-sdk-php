<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Task parameters that can be included in tool call or sampling requests.
 *
 * When present, indicates the request should be executed as a long-running task.
 */
class TaskRequestParams implements McpModel {
    use ExtraFieldsTrait;

    public function __construct(
        public ?int $ttl = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $ttl = isset($data['ttl']) ? (int)$data['ttl'] : null;
        unset($data['ttl']);

        $obj = new self($ttl);
        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }
        return $obj;
    }

    public function validate(): void {}

    public function jsonSerialize(): mixed {
        $data = [];
        if ($this->ttl !== null) {
            $data['ttl'] = $this->ttl;
        }
        return empty($data) ? array_merge($data, $this->extraFields) : array_merge($data, $this->extraFields);
    }
}
