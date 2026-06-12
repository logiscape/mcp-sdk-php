<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * Developed by:
 * - Josh Abbott
 * - Claude (Anthropic AI model)
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
 * Filename: Client/RawResult.php
 */

declare(strict_types=1);

namespace Mcp\Client;

use Mcp\Types\Result;

/**
 * Internal result capture for the SEP-2322 multi-round-trip loop.
 *
 * The client must inspect `resultType` on the RAW response data BEFORE any
 * typed parsing: an `input_required` result has none of the fields the
 * typed result class expects, and the verbatim `requestState` / keyed
 * `inputRequests` must be read off the wire shape untouched. Passing this
 * class as the result type to BaseSession::sendRequest() captures the
 * decoded result array as-is; the session then either runs the MRTR round
 * or hands the data to the real result type's fromResponseData().
 *
 * @internal Not part of the public SDK API.
 */
final class RawResult extends Result
{
    /** @var array<string, mixed> The decoded JSON-RPC result, verbatim. */
    public array $data = [];

    /**
     * @param array<string, mixed> $data
     */
    public static function fromResponseData(array $data): self
    {
        $result = new self();
        $result->data = $data;
        return $result;
    }
}
