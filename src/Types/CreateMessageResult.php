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
 * Filename: Types/CreateMessageResult.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Result of a create message request.
 *
 * Per the MCP spec, CreateMessageResult extends SamplingMessage, so content
 * can be a single content block or an array of content blocks (e.g. multiple
 * ToolUseContent blocks when stopReason is "toolUse").
 */
class CreateMessageResult extends Result {
    /**
     * @param TextContent|ImageContent|AudioContent|ToolUseContent|array $content
     *        Single content block or array of content blocks.
     */
    public function __construct(
        public readonly TextContent|ImageContent|AudioContent|ToolUseContent|array $content,
        public readonly string $model,
        public readonly Role $role,
        public ?string $stopReason = null,
        ?Meta $_meta = null,
    ) {
        parent::__construct($_meta);
    }

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

        $model = $data['model'] ?? '';
        $roleStr = $data['role'] ?? '';
        $stopReason = $data['stopReason'] ?? null;
        $contentData = $data['content'] ?? [];
        unset($data['model'], $data['role'], $data['stopReason'], $data['content']);

        $role = Role::tryFrom($roleStr);
        if ($role === null) {
            throw new \InvalidArgumentException("Invalid role: $roleStr in CreateMessageResult");
        }

        $content = self::parseContent($contentData);

        $obj = new self($content, $model, $role, $stopReason, $meta);

        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    /**
     * Parse content data which may be a single block or an array of blocks.
     */
    private static function parseContent(array $contentData): TextContent|ImageContent|AudioContent|ToolUseContent|array {
        // Array of content blocks (no 'type' key at the top level)
        if (!isset($contentData['type']) && array_is_list($contentData)) {
            $blocks = [];
            foreach ($contentData as $item) {
                if (!is_array($item) || !isset($item['type'])) {
                    throw new \InvalidArgumentException('Each content block must have a type');
                }
                $blocks[] = self::parseSingleContent($item);
            }
            return $blocks;
        }

        // Single content block
        if (!isset($contentData['type'])) {
            throw new \InvalidArgumentException('Invalid content data in CreateMessageResult');
        }
        return self::parseSingleContent($contentData);
    }

    private static function parseSingleContent(array $contentData): TextContent|ImageContent|AudioContent|ToolUseContent {
        return match($contentData['type']) {
            'text' => TextContent::fromArray($contentData),
            'image' => ImageContent::fromArray($contentData),
            'audio' => AudioContent::fromArray($contentData),
            'tool_use' => ToolUseContent::fromArray($contentData),
            default => throw new \InvalidArgumentException("Unknown content type: {$contentData['type']} in CreateMessageResult")
        };
    }

    public function validate(): void {
        parent::validate();
        if (is_array($this->content)) {
            foreach ($this->content as $block) {
                $block->validate();
            }
        } else {
            $this->content->validate();
        }
        if (empty($this->model)) {
            throw new \InvalidArgumentException('Model name cannot be empty');
        }
    }
}