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
 * Filename: Types/ProgressNotificationParams.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Params for progress notification:
 * {
 *   progressToken: string|number,
 *   progress: number,
 *   total?: number
 * }
 */
class ProgressNotificationParams extends NotificationParams {
    use ExtraFieldsTrait;

    public function __construct(
        public readonly ProgressToken $progressToken,
        public readonly float $progress,
        public ?float $total = null,
        public ?string $message = null,
        ?Meta $_meta = null,
    ) {
        parent::__construct($_meta);
    }

    public function validate(): void {
        parent::validate();

        $this->progressToken->validate();
        if ($this->total !== null && $this->total < $this->progress) {
            throw new \InvalidArgumentException('Total progress cannot be less than current progress');
        }
    }

    public function jsonSerialize(): mixed {
        $data = [
            'progressToken' => $this->progressToken,
            'progress' => $this->progress,
        ];
        if ($this->total !== null) {
            $data['total'] = $this->total;
        }
        if ($this->message !== null) {
            $data['message'] = $this->message;
        }
        $parentData = parent::jsonSerialize();
        if ($parentData instanceof \stdClass) {
            $parentData = (array) $parentData;
        }

        $merged = array_merge($parentData, $data, $this->extraFields);

        return $merged;
    }
}
