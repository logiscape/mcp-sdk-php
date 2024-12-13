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
 * Filename: Types/TextContent.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Text content for messages
 */
class TextContent extends Content {
    public function __construct(
        public readonly string $text,
        ?Annotations $annotations = null,
    ) {
        parent::__construct('text', $annotations);
    }

    public function validate(): void {
        if (empty($this->text)) {
            throw new \InvalidArgumentException('Text content cannot be empty');
        }
        if ($this->annotations !== null) {
            $this->annotations->validate();
        }
    }

    public function jsonSerialize(): mixed {
        $data = parent::jsonSerialize();
        $data['text'] = $this->text;
        return $data;
    }
}