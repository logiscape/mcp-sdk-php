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
 * Filename: Types/CallToolResult.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Result of a tool call
 * content: (TextContent|ImageContent|AudioContent|EmbeddedResource)[]
 * isError?: boolean
 */
class CallToolResult extends Result {
    /**
     * @param mixed[] $content Validated by validate() to contain TextContent|ImageContent|AudioContent|EmbeddedResource|ResourceLinkContent
     * @param array<mixed>|null $structuredContent Structured output matching the tool's outputSchema
     */
    public function __construct(
        public readonly array $content,
        public ?bool $isError = false,
        ?Meta $_meta = null,
        public ?array $structuredContent = null,
    ) {
        parent::__construct($_meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromResponseData(array $data): self {
        // _meta
        $meta = null;
        if (isset($data['_meta'])) {
            /** @var array<string, mixed> $metaData */
            $metaData = $data['_meta'];
            unset($data['_meta']);
            $meta = new Meta();
            foreach ($metaData as $k => $v) {
                $meta->$k = $v;
            }
        }

        /** @var list<mixed> $contentData */
        $contentData = $data['content'] ?? [];
        $isError = $data['isError'] ?? false;
        /** @var array<mixed>|null $structuredContent */
        $structuredContent = $data['structuredContent'] ?? null;
        unset($data['content'], $data['isError'], $data['structuredContent']);

        $content = [];
        foreach ($contentData as $item) {
            if (!is_array($item) || !isset($item['type'])) {
                throw new \InvalidArgumentException('Invalid item in CallToolResult content');
            }

            $type = $item['type'];
            $content[] = match($type) {
                'text' => TextContent::fromArray($item),
                'image' => ImageContent::fromArray($item),
                'audio' => AudioContent::fromArray($item),
                'resource' => EmbeddedResource::fromArray($item),
                'resource_link' => ResourceLinkContent::fromArray($item),
                default => throw new \InvalidArgumentException("Unknown content type: $type in CallToolResult")
            };
        }

        /** @var (TextContent|ImageContent|AudioContent|EmbeddedResource|ResourceLinkContent)[] $content */
        $obj = new self($content, (bool)$isError, $meta, $structuredContent);

        // Extra fields
        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        parent::validate();
        foreach ($this->content as $item) {
            if (!($item instanceof TextContent || $item instanceof ImageContent || $item instanceof AudioContent || $item instanceof EmbeddedResource || $item instanceof ResourceLinkContent)) {
                throw new \InvalidArgumentException('Tool call content must be TextContent, ImageContent, AudioContent, EmbeddedResource, or ResourceLinkContent instances');
            }
            $item->validate();
        }
    }
}