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
 * Filename: Types/Annotations.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Represents the `annotations` object from Annotated interfaces.
 */
class Annotations implements McpModel {
    use ExtraFieldsTrait;

    /**
     * @param Role[]|null $audience
     */
    public function __construct(
        public ?array $audience = null,
        public ?float $priority = null,
    ) {}

    public function validate(): void {
        // audience?: Role[]
        if ($this->audience !== null) {
            foreach ($this->audience as $role) {
                if (!($role instanceof Role)) {
                    throw new \InvalidArgumentException('Invalid role in annotations audience');
                }
            }
        }
        // priority?: number between 0 and 1
        if ($this->priority !== null && ($this->priority < 0 || $this->priority > 1)) {
            throw new \InvalidArgumentException('Annotations priority must be between 0 and 1');
        }
    }

    public function jsonSerialize(): mixed {
        $data = [];
        if ($this->audience !== null) {
            $data['audience'] = array_map(fn($r) => $r->value, $this->audience);
        }
        if ($this->priority !== null) {
            $data['priority'] = $this->priority;
        }
        return array_merge($data, $this->extraFields);
    }
}