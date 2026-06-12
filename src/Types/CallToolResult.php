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
     * Tracks an EXPLICIT JSON null structuredContent (SEP-2106 allows any
     * JSON value, including null, when the outputSchema accepts it). The
     * base Result serializer omits null properties, so without this marker
     * "structuredContent: null" could not be put on the wire at all.
     */
    private bool $structuredContentIsNull = false;

    /**
     * @param mixed[] $content Validated by validate() to contain TextContent|ImageContent|AudioContent|EmbeddedResource|ResourceLinkContent
     * @param mixed $structuredContent Structured output matching the tool's outputSchema.
     *        Under the 2026-07-28 revision (SEP-2106) this may be ANY JSON value;
     *        legacy revisions restrict it to an object (the server session strips
     *        non-object values when adapting for legacy clients).
     */
    public function __construct(
        public readonly array $content,
        public ?bool $isError = false,
        ?Meta $_meta = null,
        public mixed $structuredContent = null,
    ) {
        parent::__construct($_meta);
    }

    /**
     * Mark this result as carrying an explicit `structuredContent: null`
     * (2026-07-28 / SEP-2106). Distinct from leaving the property unset,
     * which omits the field from the wire entirely.
     */
    public function setStructuredContentNull(): void {
        $this->structuredContent = null;
        $this->structuredContentIsNull = true;
    }

    public function hasExplicitNullStructuredContent(): bool {
        return $this->structuredContentIsNull;
    }

    public function jsonSerialize(): mixed {
        $data = parent::jsonSerialize();
        if ($data instanceof \stdClass) {
            $data = (array) $data;
        }
        if ($this->structuredContentIsNull) {
            $data['structuredContent'] = null;
        }
        return $data;
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
        $structuredContent = $data['structuredContent'] ?? null;
        $hasExplicitNullStructuredContent = array_key_exists('structuredContent', $data)
            && $data['structuredContent'] === null;
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
        if ($hasExplicitNullStructuredContent) {
            $obj->setStructuredContentNull();
        }

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