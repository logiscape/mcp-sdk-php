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
 * Filename: Types/Implementation.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Describes the name and version of an MCP implementation
 */
class Implementation implements McpModel {
    use ExtraFieldsTrait;

    /**
     * @param Icon[]|null $icons
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public ?string $title = null,
        public ?string $description = null,
        public ?array $icons = null,
        public ?string $websiteUrl = null,
    ) {}

    public static function fromArray(array $data): self {
        $name = $data['name'] ?? '';
        $version = $data['version'] ?? '';
        $title = $data['title'] ?? null;
        $description = $data['description'] ?? null;
        $websiteUrl = $data['websiteUrl'] ?? null;

        $icons = Icon::parseArray($data['icons'] ?? null);

        unset($data['name'], $data['version'], $data['title'], $data['description'],
              $data['icons'], $data['websiteUrl']);

        $obj = new self($name, $version, $title, $description, $icons, $websiteUrl);

        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        if (empty($this->name)) {
            throw new \InvalidArgumentException('Implementation name cannot be empty');
        }
        if (empty($this->version)) {
            throw new \InvalidArgumentException('Implementation version cannot be empty');
        }
    }

    public function jsonSerialize(): mixed {
        $data = [
            'name' => $this->name,
            'version' => $this->version,
        ];
        if ($this->title !== null) {
            $data['title'] = $this->title;
        }
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        if ($this->icons !== null) {
            $data['icons'] = $this->icons;
        }
        if ($this->websiteUrl !== null) {
            $data['websiteUrl'] = $this->websiteUrl;
        }
        return array_merge($data, $this->extraFields);
    }
}