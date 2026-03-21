<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Server-to-client request: elicitation/create
 *
 * Asks the client to collect information from the user via a form or URL.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/client/elicitation
 */
class ElicitationCreateRequest extends Request {
    /**
     * @param string $message Human-readable message explaining why the interaction is needed
     * @param string|null $mode "form" or "url" (defaults to "form" if omitted)
     * @param array<string, mixed>|null $requestedSchema JSON Schema for form mode
     * @param string|null $url URL for URL mode
     * @param string|null $elicitationId Unique identifier for URL mode elicitation
     */
    public function __construct(
        public readonly string $message,
        public ?string $mode = null,
        public ?array $requestedSchema = null,
        public ?string $url = null,
        public ?string $elicitationId = null,
    ) {
        $params = new RequestParams();
        $params->message = $message;
        if ($mode !== null) {
            $params->mode = $mode;
        }
        if ($requestedSchema !== null) {
            $params->requestedSchema = $requestedSchema;
        }
        if ($url !== null) {
            $params->url = $url;
        }
        if ($elicitationId !== null) {
            $params->elicitationId = $elicitationId;
        }
        parent::__construct('elicitation/create', $params);
    }

    public function validate(): void {
        parent::validate();
        if (empty($this->message)) {
            throw new \InvalidArgumentException('Elicitation message cannot be empty');
        }
        if ($this->mode !== null && !in_array($this->mode, ['form', 'url'], true)) {
            throw new \InvalidArgumentException("Invalid elicitation mode: '{$this->mode}'");
        }
        if ($this->mode === 'url') {
            if (empty($this->url)) {
                throw new \InvalidArgumentException('URL mode elicitation requires a url');
            }
            if (empty($this->elicitationId)) {
                throw new \InvalidArgumentException('URL mode elicitation requires an elicitationId');
            }
            if ($this->requestedSchema !== null) {
                throw new \InvalidArgumentException('URL mode must not include requestedSchema');
            }
        } else {
            // form mode (explicit or null default)
            if ($this->requestedSchema === null) {
                throw new \InvalidArgumentException('Form mode elicitation requires requestedSchema');
            }
            if ($this->url !== null) {
                throw new \InvalidArgumentException('Form mode must not include url');
            }
        }
    }
}
