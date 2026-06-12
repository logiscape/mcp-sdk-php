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
 * Filename: Types/Meta.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Represents the `_meta` object found in various structures (e.g. Result, Request params).
 * This is an open object, so we just allow arbitrary fields.
 *
 * @property int|string|ProgressToken|null $progressToken Progress tracking token
 * @property string|null $traceparent W3C Trace Context traceparent (SEP-414, reserved unprefixed key)
 * @property string|null $tracestate W3C Trace Context tracestate (SEP-414, reserved unprefixed key)
 * @property string|null $baggage W3C Baggage (SEP-414, reserved unprefixed key)
 */
class Meta implements McpModel {
    use ExtraFieldsTrait;

    /**
     * Set an arbitrary `_meta` field by key.
     *
     * Equivalent to dynamic property assignment, but explicit — useful for
     * non-identifier keys such as the io.modelcontextprotocol/-prefixed
     * envelope keys (see {@see MetaKeys}).
     */
    public function setField(string $key, mixed $value): void {
        $this->extraFields[$key] = $value;
    }

    /**
     * Get an arbitrary `_meta` field by key (null when absent).
     */
    public function getField(string $key): mixed {
        return $this->extraFields[$key] ?? null;
    }

    public function validate(): void {
        // No required fields, just arbitrary data allowed
    }

    public function jsonSerialize(): mixed {
        // Return only extra fields, since there are no defined properties
        return empty($this->extraFields) ? new \stdClass() : $this->extraFields;
    }
}