<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
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
 * Filename: Types/CompletionContext.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Additional, already-resolved context for a completion request.
 *
 * Per the MCP completion spec, a client may send the values of previously
 * resolved arguments so the server can tailor suggestions to earlier choices
 * (e.g. complete `framework` based on the chosen `language`):
 * {
 *   "arguments": { "<argName>": "<resolvedValue>", ... }
 * }
 */
class CompletionContext implements McpModel {
    use ExtraFieldsTrait;

    /**
     * @param array<string, string> $arguments Already-resolved argument values
     */
    public function __construct(
        public readonly array $arguments = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $rawArguments = $data['arguments'] ?? [];
        unset($data['arguments']);

        $arguments = [];
        if (is_array($rawArguments)) {
            foreach ($rawArguments as $k => $v) {
                $arguments[(string)$k] = (string)$v;
            }
        }

        $obj = new self($arguments);

        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        // `arguments` is an optional map of string => string; nothing mandatory.
    }

    public function jsonSerialize(): mixed {
        $data = $this->extraFields;
        if (!empty($this->arguments)) {
            $data['arguments'] = $this->arguments;
        }
        // Emit an object (not an array) when there is nothing to serialize.
        return empty($data) ? new \stdClass() : $data;
    }
}
