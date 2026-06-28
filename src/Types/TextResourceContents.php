<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2024 Logiscape LLC <https://logiscape.com>
 *
 * Based on the Python SDK for the Model Context Protocol
 * https://github.com/modelcontextprotocol/python-sdk
 *
 * PHP conversion developed by:
 * - Josh Abbott
 * - Claude 3.5 Sonnet (Anthropic AI model)
 * - ChatGPT o1 pro mode
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 *
 * Filename: Types/TextResourceContents.php
 */

declare(strict_types=1);

namespace Mcp\Types;

class TextResourceContents extends ResourceContents {
    public function __construct(
        public readonly string $text,
        string $uri,
        ?string $mimeType = null,
    ) {
        parent::__construct($uri, $mimeType);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $uri = $data['uri'] ?? '';
        $mimeType = $data['mimeType'] ?? null;
        $text = $data['text'] ?? '';

        unset($data['uri'], $data['mimeType'], $data['text']);

        $obj = new self($text, $uri, $mimeType);

        // ResourceContents uses ExtraFieldsTrait: preserve any remaining keys
        // as extra fields so forward-compatible metadata survives a
        // round-trip — notably the SEP-1865 `_meta.ui` (CSP, permissions,
        // domain, border hints) a host needs to sandbox an MCP App, which is
        // carried on the resources/read content.
        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        parent::validate();
        if (empty($this->text)) {
            throw new \InvalidArgumentException('Resource text cannot be empty');
        }
    }

    public function jsonSerialize(): mixed {
        $data = parent::jsonSerialize();
        $data['text'] = $this->text;
        return $data;
    }
}