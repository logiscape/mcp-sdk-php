<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Capability declaration for tasks (experimental).
 *
 * Server capabilities: tasks: { list?: {}, cancel?: {}, requests?: { tools?: { call?: {} } } }
 * Client capabilities: tasks: { list?: {}, cancel?: {}, requests?: { sampling?: { createMessage?: {} }, elicitation?: { create?: {} } } }
 */
class TaskCapability implements McpModel {
    use ExtraFieldsTrait;

    /**
     * @param array<string, mixed>|null $requests
     */
    public function __construct(
        public ?bool $list = null,
        public ?bool $cancel = null,
        public ?array $requests = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $list = isset($data['list']) ? true : null;
        $cancel = isset($data['cancel']) ? true : null;
        $requests = $data['requests'] ?? null;
        unset($data['list'], $data['cancel'], $data['requests']);

        $obj = new self($list, $cancel, $requests);
        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }
        return $obj;
    }

    public function validate(): void {}

    public function jsonSerialize(): mixed {
        $data = [];
        if ($this->list !== null) {
            $data['list'] = new \stdClass();
        }
        if ($this->cancel !== null) {
            $data['cancel'] = new \stdClass();
        }
        if ($this->requests !== null) {
            $data['requests'] = self::serializeRequests($this->requests);
        }
        return empty($data) ? new \stdClass() : array_merge($data, $this->extraFields);
    }

    /**
     * Recursively serialize the requests structure, converting empty arrays to stdClass.
     *
     * @param array<string, mixed> $requests
     * @return array<string, mixed>
     */
    private static function serializeRequests(array $requests): array {
        $result = [];
        foreach ($requests as $key => $value) {
            if (is_array($value)) {
                $result[$key] = empty($value) ? new \stdClass() : self::serializeRequests($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
