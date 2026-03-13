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
 * Filename: Types/SamplingMessage.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * SamplingMessage
 * {
 *   role: Role,
 *   content: SamplingMessageContentBlock | SamplingMessageContentBlock[]
 * }
 *
 * Per the MCP spec, content can be a single content block or an array of
 * content blocks. Array form is required for tool use in sampling, where
 * assistant messages contain multiple ToolUseContent blocks and user
 * messages contain multiple ToolResultContent blocks.
 */
class SamplingMessage implements McpModel {
    use ExtraFieldsTrait;

    /**
     * @param Role $role
     * @param TextContent|ImageContent|AudioContent|ToolUseContent|ToolResultContent|array<int, TextContent|ImageContent|AudioContent|ToolUseContent|ToolResultContent> $content
     *        Single content block or array of content blocks.
     */
    public function __construct(
        public readonly Role $role,
        public readonly TextContent|ImageContent|AudioContent|ToolUseContent|ToolResultContent|array $content,
    ) {}

    public function validate(): void {
        if (is_array($this->content)) {
            foreach ($this->content as $block) {
                $block->validate();
            }
        } else {
            $this->content->validate();
        }
    }

    public function jsonSerialize(): mixed {
        return array_merge([
            'role' => $this->role->value,
            'content' => $this->content,
        ], $this->extraFields);
    }
}