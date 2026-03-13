<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * A resource link returned in tool results or messages.
 */
class ResourceLinkContent extends Content {
    public function __construct(
        public readonly string $uri,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $mimeType = null,
        ?Annotations $annotations = null,
    ) {
        parent::__construct('resource_link', $annotations);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $uri = $data['uri'] ?? '';
        $name = $data['name'] ?? null;
        $description = $data['description'] ?? null;
        $mimeType = $data['mimeType'] ?? null;
        unset($data['type'], $data['uri'], $data['name'], $data['description'], $data['mimeType']);

        $annotations = null;
        if (isset($data['annotations']) && is_array($data['annotations'])) {
            $annotations = Annotations::fromArray($data['annotations']);
            unset($data['annotations']);
        }

        $obj = new self($uri, $name, $description, $mimeType, $annotations);
        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        if (empty($this->uri)) {
            throw new \InvalidArgumentException('ResourceLinkContent uri cannot be empty');
        }
        if ($this->annotations !== null) {
            $this->annotations->validate();
        }
    }

    public function jsonSerialize(): mixed {
        $data = parent::jsonSerialize();
        $data['uri'] = $this->uri;
        if ($this->name !== null) {
            $data['name'] = $this->name;
        }
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        if ($this->mimeType !== null) {
            $data['mimeType'] = $this->mimeType;
        }
        return $data;
    }
}
