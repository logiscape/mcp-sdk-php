<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Tool result content in sampling messages.
 */
class ToolResultContent extends Content {
    /**
     * @param Content[]|null $content
     */
    public function __construct(
        public readonly string $toolUseId,
        public ?array $content = null,
        public ?bool $isError = null,
        ?Annotations $annotations = null,
    ) {
        parent::__construct('tool_result', $annotations);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $toolUseId = $data['toolUseId'] ?? '';
        $isError = isset($data['isError']) ? (bool)$data['isError'] : null;
        unset($data['type'], $data['toolUseId'], $data['isError']);

        $contentItems = null;
        if (isset($data['content']) && is_array($data['content'])) {
            $contentItems = [];
            foreach ($data['content'] as $item) {
                if (!is_array($item) || !isset($item['type'])) {
                    continue;
                }
                $contentItems[] = match($item['type']) {
                    'text' => TextContent::fromArray($item),
                    'image' => ImageContent::fromArray($item),
                    'audio' => AudioContent::fromArray($item),
                    'resource' => EmbeddedResource::fromArray($item),
                    'resource_link' => ResourceLinkContent::fromArray($item),
                    default => throw new \InvalidArgumentException("Unknown content type: {$item['type']}")
                };
            }
            unset($data['content']);
        }

        $annotations = null;
        if (isset($data['annotations']) && is_array($data['annotations'])) {
            $annotations = Annotations::fromArray($data['annotations']);
            unset($data['annotations']);
        }

        $obj = new self($toolUseId, $contentItems, $isError, $annotations);
        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        if (empty($this->toolUseId)) {
            throw new \InvalidArgumentException('ToolResultContent toolUseId cannot be empty');
        }
        if ($this->annotations !== null) {
            $this->annotations->validate();
        }
    }

    public function jsonSerialize(): mixed {
        $data = parent::jsonSerialize();
        $data['toolUseId'] = $this->toolUseId;
        if ($this->content !== null) {
            $data['content'] = $this->content;
        }
        if ($this->isError !== null) {
            $data['isError'] = $this->isError;
        }
        return $data;
    }
}
