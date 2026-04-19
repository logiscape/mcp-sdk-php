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
 * Filename: Server/Transport/Http/Sse/StreamId.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport\Http\Sse;

/**
 * Helpers for minting per-stream identifiers and composing/parsing SSE event ids.
 *
 * Event ids are formatted as "<streamId>:<seq>". This satisfies the MCP
 * Streamable HTTP spec requirements that event ids be globally unique within
 * a session and encode enough information to identify the originating stream,
 * so a Last-Event-ID can be correlated to the correct stream on resumption.
 */
final class StreamId
{
    /**
     * Separator between stream id and sequence number inside an event id.
     */
    public const SEPARATOR = ':';

    /**
     * Mint a fresh stream id.
     *
     * Produces an 8-byte random token encoded as base64url (no padding),
     * giving a short (~11 char) ASCII identifier safe for HTTP headers and
     * SSE event ids.
     */
    public static function mint(): string
    {
        $raw = \random_bytes(8);
        $b64 = \rtrim(\strtr(\base64_encode($raw), '+/', '-_'), '=');
        return $b64;
    }

    /**
     * Compose an event id from a stream id and sequence number.
     */
    public static function formatEventId(string $streamId, int $seq): string
    {
        return $streamId . self::SEPARATOR . $seq;
    }

    /**
     * Parse a Last-Event-ID value of the form "<streamId>:<seq>".
     *
     * Returns null when the input is malformed: missing separator, empty
     * parts, or a non-integer sequence.
     *
     * @return array{streamId: string, seq: int}|null
     */
    public static function parse(string $lastEventId): ?array
    {
        $pos = \strrpos($lastEventId, self::SEPARATOR);
        if ($pos === false || $pos === 0 || $pos === \strlen($lastEventId) - 1) {
            return null;
        }

        $streamId = \substr($lastEventId, 0, $pos);
        $seqPart = \substr($lastEventId, $pos + 1);

        if ($streamId === '' || $seqPart === '') {
            return null;
        }

        if (!\ctype_digit($seqPart)) {
            return null;
        }

        return [
            'streamId' => $streamId,
            'seq' => (int) $seqPart,
        ];
    }
}
