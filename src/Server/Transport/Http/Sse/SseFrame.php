<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2025 Logiscape LLC <https://logiscape.com>
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
 * Filename: Server/Transport/Http/Sse/SseFrame.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport\Http\Sse;

/**
 * Immutable value object representing a single SSE event.
 *
 * Fields mirror the WHATWG SSE spec: id, event name, retry, data.
 */
final class SseFrame
{
    /**
     * @param string|null $id       SSE event id, or null to omit.
     * @param string|null $event    Event name, or null for the default ("message").
     * @param int|null    $retryMs  Reconnect hint in milliseconds, or null to omit.
     * @param string      $data     UTF-8 data payload. May be empty (priming).
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $event,
        public readonly ?int $retryMs,
        public readonly string $data,
    ) {
    }

    /**
     * @return array{id: ?string, event: ?string, retryMs: ?int, data: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'retryMs' => $this->retryMs,
            'data' => $this->data,
        ];
    }

    /**
     * @param array{id?: ?string, event?: ?string, retryMs?: ?int, data?: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: isset($data['id']) ? (string) $data['id'] : null,
            event: isset($data['event']) ? (string) $data['event'] : null,
            retryMs: isset($data['retryMs']) ? (int) $data['retryMs'] : null,
            data: (string) ($data['data'] ?? ''),
        );
    }
}
