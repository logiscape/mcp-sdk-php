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
 * Filename: Types/RequestParams.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Represents the `params` object in a Request.
 * According to the schema, `params` can have `_meta?: { progressToken?: ProgressToken }` and arbitrary fields.
 */
class RequestParams implements McpModel {
    use ExtraFieldsTrait;

    public function __construct(
        public ?Meta $_meta = null,
    ) {}

    public function validate(): void {
        // No mandatory fields, just arbitrary data.
        // _meta, if present, should be validated.
        if ($this->_meta !== null) {
            $this->_meta->validate();
        }
    }

    public function jsonSerialize(): mixed {
        $data = get_object_vars($this);
        // Merge extra fields
        return array_merge($data, $this->extraFields);
    }
}