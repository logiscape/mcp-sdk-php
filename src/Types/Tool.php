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
 * Filename: Types/Tool.php
 */

declare(strict_types=1);

namespace Mcp\Types;

class Tool implements McpModel {
    use ExtraFieldsTrait;

    /**
     * @param Icon[]|null $icons
     * @param array<string, mixed>|null $outputSchema JSON Schema for structured output
     * @param array<string, mixed>|null $execution Execution hints (e.g. taskSupport)
     */
    public function __construct(
        public readonly string $name,
        public readonly ToolInputSchema $inputSchema,
        public ?string $description = null,
        public ?ToolAnnotations $annotations = null,
        public ?string $title = null,
        public ?array $icons = null,
        public ?array $outputSchema = null,
        public ?array $execution = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $name = $data['name'] ?? '';
        $description = $data['description'] ?? null;
        $title = $data['title'] ?? null;
        $annotationsData = $data['annotations'] ?? null;
        $inputSchemaData = $data['inputSchema'] ?? [];
        $outputSchema = $data['outputSchema'] ?? null;
        $execution = $data['execution'] ?? null;

        $icons = Icon::parseArray($data['icons'] ?? null);

        unset($data['name'], $data['description'], $data['inputSchema'], $data['annotations'],
              $data['title'], $data['icons'], $data['outputSchema'], $data['execution']);

        $inputSchema = ToolInputSchema::fromArray($inputSchemaData);

        $annotations = null;
        if ($annotationsData !== null && is_array($annotationsData)) {
            $annotations = ToolAnnotations::fromArray($annotationsData);
        } elseif ($annotationsData instanceof ToolAnnotations) {
            $annotations = $annotationsData;
        }

        $obj = new self($name, $inputSchema, $description, $annotations, $title, $icons, $outputSchema, $execution);

        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        if (empty($this->name)) {
            throw new \InvalidArgumentException('Tool name cannot be empty');
        }
        $this->inputSchema->validate();
    }

    public function jsonSerialize(): mixed {
        $data = [
            'name' => $this->name,
            'inputSchema' => $this->inputSchema,
        ];
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        if ($this->title !== null) {
            $data['title'] = $this->title;
        }
        if ($this->icons !== null) {
            $data['icons'] = $this->icons;
        }
        if ($this->outputSchema !== null) {
            $data['outputSchema'] = $this->outputSchema;
        }
        if ($this->execution !== null) {
            $data['execution'] = $this->execution;
        }
        if ($this->annotations !== null) {
            $data['annotations'] = $this->annotations;
        }
        return array_merge($data, $this->extraFields);
    }
}