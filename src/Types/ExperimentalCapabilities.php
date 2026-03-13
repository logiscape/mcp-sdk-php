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
 * Filename: Types/ExperimentalCapabilities.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Represents the `experimental` object in capabilities.
 * This is an open object: { [key: string]: object }, so we just allow arbitrary fields.
 *
 * @implements \IteratorAggregate<string, mixed>
 * @implements \ArrayAccess<string, mixed>
 */
class ExperimentalCapabilities implements McpModel, \IteratorAggregate, \ArrayAccess {
    use ExtraFieldsTrait;

    /**
     * @return \Traversable<string, mixed>
     */
    public function getIterator(): \Traversable {
        return new \ArrayIterator($this->extraFields);
    }

    public function offsetExists(mixed $offset): bool {
        return isset($this->extraFields[$offset]);
    }

    public function offsetGet(mixed $offset): mixed {
        return $this->extraFields[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        if (!is_string($offset)) {
            throw new \InvalidArgumentException('ExperimentalCapabilities keys must be strings');
        }
        $this->extraFields[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void {
        unset($this->extraFields[$offset]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $obj = new self();
        // All fields go to extraFields
        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        // No required fields.
    }

    public function jsonSerialize(): mixed {
        // Just return extra fields
        return empty($this->extraFields) ? new \stdClass() : $this->extraFields;
    }
}