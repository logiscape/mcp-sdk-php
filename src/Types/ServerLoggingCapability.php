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
 * Filename: Types/ServerLoggingCapability.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Represents the `logging` object in ServerCapabilities.
 * According to the schema: logging?: object (with arbitrary fields)
 *
 * @deprecated Deprecated as of protocol version 2026-07-28 (SEP-2577). The
 *             Logging feature remains in the specification (and this SDK) for
 *             at least twelve months; migrate to stderr logging for stdio
 *             transports and OpenTelemetry for observability. See the
 *             deprecated features registry.
 */
class ServerLoggingCapability implements McpModel {
    use ExtraFieldsTrait;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $obj = new self();
        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        // No mandatory fields, arbitrary fields allowed.
    }

    public function jsonSerialize(): mixed {
        return empty($this->extraFields) ? new \stdClass() : $this->extraFields; // No defined properties
    }
}