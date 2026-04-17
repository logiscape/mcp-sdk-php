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
 * Filename: Client/Transport/SseEventParser.php
 */

declare(strict_types=1);

namespace Mcp\Client\Transport;

/**
 * Event-aware Server-Sent Events parser.
 *
 * Implements the parsing rules from the WHATWG HTML SSE spec:
 *   - Line endings may be LF, CRLF, or CR; all are normalized to LF.
 *   - Events are separated by a blank line.
 *   - Lines starting with ':' are comments and ignored.
 *   - A line with no colon is a field whose value is the empty string.
 *   - Otherwise the field name is before the first colon, and the value is
 *     everything after it with a single leading space stripped.
 *   - Multiple 'data' fields within one event are joined with '\n'.
 *   - 'retry' is honored only if the value is purely decimal digits.
 */
final class SseEventParser
{
    /**
     * Parse a complete SSE buffer into discrete events. Any trailing partial
     * event (not terminated by a blank line) is dropped.
     *
     * @return list<array{id: ?string, event: string, data: string, retry: ?int}>
     */
    public static function parse(string $raw): array
    {
        $buffer = self::normalize($raw);
        return self::extractEvents($buffer);
    }

    /**
     * Streaming parse: consume complete events from $buffer and leave any
     * trailing partial inside $buffer for the next invocation. The caller is
     * expected to append the next chunk to $buffer before calling again.
     *
     * @param string $buffer Byref buffer of accumulated bytes.
     * @return list<array{id: ?string, event: string, data: string, retry: ?int}>
     */
    public static function parseStreaming(string &$buffer): array
    {
        $buffer = self::normalize($buffer);
        return self::extractEvents($buffer);
    }

    /**
     * Extract every complete event from the (already normalized) buffer,
     * mutating $buffer to hold whatever partial event remains.
     *
     * @return list<array{id: ?string, event: string, data: string, retry: ?int}>
     */
    private static function extractEvents(string &$buffer): array
    {
        $events = [];
        while (($end = strpos($buffer, "\n\n")) !== false) {
            $rawEvent = substr($buffer, 0, $end);
            $buffer = substr($buffer, $end + 2);
            $event = self::parseSingleEvent($rawEvent);
            if ($event !== null) {
                $events[] = $event;
            }
        }
        return $events;
    }

    private static function normalize(string $raw): string
    {
        if ($raw === '') {
            return $raw;
        }
        $raw = str_replace("\r\n", "\n", $raw);
        return str_replace("\r", "\n", $raw);
    }

    /**
     * Parse a single event block (no trailing blank line). Returns null if the
     * block contains only comments / blank lines.
     *
     * @return array{id: ?string, event: string, data: string, retry: ?int}|null
     */
    private static function parseSingleEvent(string $rawEvent): ?array
    {
        $event = [
            'id' => null,
            'event' => 'message',
            'data' => '',
            'retry' => null,
        ];
        $hasField = false;
        $dataSeen = false;

        foreach (explode("\n", $rawEvent) as $line) {
            if ($line === '') {
                continue;
            }
            if ($line[0] === ':') {
                continue; // comment
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                $field = $line;
                $value = '';
            } else {
                $field = substr($line, 0, $colon);
                $value = substr($line, $colon + 1);
                if ($value !== '' && $value[0] === ' ') {
                    $value = substr($value, 1);
                }
            }

            $hasField = true;

            switch ($field) {
                case 'id':
                    // Per spec: NUL characters invalidate the id. Otherwise overwrite.
                    if (strpos($value, "\0") === false) {
                        $event['id'] = $value;
                    }
                    break;
                case 'event':
                    if ($value !== '') {
                        $event['event'] = $value;
                    }
                    break;
                case 'data':
                    $event['data'] = $dataSeen
                        ? $event['data'] . "\n" . $value
                        : $value;
                    $dataSeen = true;
                    break;
                case 'retry':
                    if ($value !== '' && ctype_digit($value)) {
                        $event['retry'] = (int) $value;
                    }
                    break;
                // Unknown field names are ignored per spec.
            }
        }

        return $hasField ? $event : null;
    }
}
