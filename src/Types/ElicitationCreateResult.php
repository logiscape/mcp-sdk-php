<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Client response to an elicitation/create request.
 */
class ElicitationCreateResult extends Result {
    /**
     * @param array<string, mixed>|null $content
     */
    public function __construct(
        public readonly string $action,
        public ?array $content = null,
        ?Meta $_meta = null,
    ) {
        parent::__construct($_meta);
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

        $action = $data['action'] ?? '';
        $content = $data['content'] ?? null;
        unset($data['action'], $data['content']);

        $obj = new self($action, $content, $meta);

        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        parent::validate();
        if (!in_array($this->action, ['accept', 'decline', 'cancel'], true)) {
            throw new \InvalidArgumentException("Invalid elicitation action: {$this->action}");
        }
    }
}
