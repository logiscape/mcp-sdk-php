<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Client capability for elicitation support.
 */
class ElicitationCapability implements McpModel {
    use ExtraFieldsTrait;

    public function __construct(
        public ?bool $form = null,
        public ?bool $url = null,
        public ?bool $applyDefaults = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $form = isset($data['form']) ? true : null;
        $url = isset($data['url']) ? true : null;
        $applyDefaults = array_key_exists('applyDefaults', $data) && is_bool($data['applyDefaults'])
            ? $data['applyDefaults']
            : null;
        unset($data['form'], $data['url'], $data['applyDefaults']);

        $obj = new self($form, $url, $applyDefaults);
        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }
        return $obj;
    }

    public function validate(): void {}

    public function jsonSerialize(): mixed {
        $data = [];
        if ($this->form !== null) {
            $data['form'] = new \stdClass();
        }
        if ($this->url !== null) {
            $data['url'] = new \stdClass();
        }
        if ($this->applyDefaults !== null) {
            $data['applyDefaults'] = $this->applyDefaults;
        }
        return array_merge($data, $this->extraFields);
    }
}
