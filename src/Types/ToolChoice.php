<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Tool choice mode for sampling requests.
 *
 * @deprecated Deprecated as of protocol version 2026-07-28 (SEP-2577). The
 *             Sampling feature remains in the specification (and this SDK)
 *             for at least twelve months; migrate to direct LLM provider API
 *             integration. See the deprecated features registry.
 */
class ToolChoice implements McpModel {
    use ExtraFieldsTrait;

    public function __construct(
        public readonly string $mode,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $mode = $data['mode'] ?? 'auto';
        unset($data['mode']);

        $obj = new self($mode);
        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }
        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        if (!in_array($this->mode, ['auto', 'required', 'none'], true)) {
            throw new \InvalidArgumentException("Invalid tool choice mode: {$this->mode}");
        }
    }

    public function jsonSerialize(): mixed {
        return array_merge(['mode' => $this->mode], $this->extraFields);
    }
}
