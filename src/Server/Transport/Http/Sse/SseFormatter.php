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
 * Filename: Server/Transport/Http/Sse/SseFormatter.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport\Http\Sse;

/**
 * Stateless WHATWG-compliant SSE frame formatter.
 *
 * Produces a single text block for a frame: optional retry, optional id,
 * optional event name, one "data:" line per data line, terminated by a
 * blank line. The SDK's own client-side parser
 * (src/Client/Transport/SseEventParser.php) accepts this exact shape.
 */
final class SseFormatter
{
    /**
     * Format a frame into its over-the-wire SSE representation.
     *
     * Notes on normalization:
     * - Any \r\n or lone \r inside data is normalized to \n so that each
     *   output line is prefixed with "data: ".
     * - id and event are stripped of embedded newlines; the SSE grammar
     *   does not allow them.
     * - retry is only emitted when non-null and >= 0.
     * - An empty data string emits a single "data: " line, which WHATWG
     *   interprets as an empty message payload (useful for priming).
     */
    public function format(SseFrame $frame): string
    {
        $buf = '';

        if ($frame->retryMs !== null && $frame->retryMs >= 0) {
            $buf .= 'retry: ' . $frame->retryMs . "\n";
        }

        if ($frame->id !== null) {
            $id = self::sanitizeSingleLine($frame->id);
            $buf .= 'id: ' . $id . "\n";
        }

        if ($frame->event !== null && $frame->event !== '') {
            $event = self::sanitizeSingleLine($frame->event);
            $buf .= 'event: ' . $event . "\n";
        }

        $data = \str_replace(["\r\n", "\r"], "\n", $frame->data);
        if ($data === '') {
            $buf .= "data: \n";
        } else {
            foreach (\explode("\n", $data) as $line) {
                $buf .= 'data: ' . $line . "\n";
            }
        }

        $buf .= "\n";
        return $buf;
    }

    /**
     * Strip CR/LF/NUL from a field that must be a single line (id, event).
     */
    private static function sanitizeSingleLine(string $s): string
    {
        return \str_replace(["\r", "\n", "\0"], '', $s);
    }
}
