<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Icon for display purposes in UI.
 */
class Icon implements McpModel {
    use ExtraFieldsTrait;

    /**
     * @param string $src Icon source URI
     * @param string|null $mimeType MIME type of the icon
     * @param string[]|null $sizes Available sizes (e.g., "48x48", "96x96")
     * @param string|null $theme Theme hint ("light" or "dark")
     */
    public function __construct(
        public readonly string $src,
        public ?string $mimeType = null,
        public ?array $sizes = null,
        public ?string $theme = null,
    ) {}

    /**
     * Parse an array of icon data into Icon objects.
     *
     * @param array<int, array<string, mixed>|Icon>|null $icons Raw icon data (arrays or Icon instances)
     * @return Icon[]|null
     */
    public static function parseArray(?array $icons): ?array {
        if ($icons === null) {
            return null;
        }
        $result = [];
        foreach ($icons as $icon) {
            $result[] = is_array($icon) ? self::fromArray($icon) : $icon;
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $src = $data['src'] ?? '';
        $mimeType = $data['mimeType'] ?? null;
        $sizes = $data['sizes'] ?? null;
        $theme = $data['theme'] ?? null;
        unset($data['src'], $data['mimeType'], $data['sizes'], $data['theme']);

        $obj = new self($src, $mimeType, $sizes, $theme);

        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        if (empty($this->src)) {
            throw new \InvalidArgumentException('Icon src cannot be empty');
        }
    }

    public function jsonSerialize(): mixed {
        $data = ['src' => $this->src];
        if ($this->mimeType !== null) {
            $data['mimeType'] = $this->mimeType;
        }
        if ($this->sizes !== null) {
            $data['sizes'] = $this->sizes;
        }
        if ($this->theme !== null) {
            $data['theme'] = $this->theme;
        }
        return array_merge($data, $this->extraFields);
    }
}
