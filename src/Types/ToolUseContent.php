<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Tool use content in sampling messages.
 */
class ToolUseContent extends Content {
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input,
        ?Annotations $annotations = null,
    ) {
        parent::__construct('tool_use', $annotations);
    }

    public static function fromArray(array $data): self {
        $id = $data['id'] ?? '';
        $name = $data['name'] ?? '';
        $input = $data['input'] ?? [];
        unset($data['type'], $data['id'], $data['name'], $data['input']);

        $annotations = null;
        if (isset($data['annotations']) && is_array($data['annotations'])) {
            $annotations = Annotations::fromArray($data['annotations']);
            unset($data['annotations']);
        }

        $obj = new self($id, $name, $input, $annotations);
        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        if (empty($this->id)) {
            throw new \InvalidArgumentException('ToolUseContent id cannot be empty');
        }
        if (empty($this->name)) {
            throw new \InvalidArgumentException('ToolUseContent name cannot be empty');
        }
        if ($this->annotations !== null) {
            $this->annotations->validate();
        }
    }

    public function jsonSerialize(): mixed {
        $data = parent::jsonSerialize();
        $data['id'] = $this->id;
        $data['name'] = $this->name;
        $data['input'] = $this->input;
        return $data;
    }
}
