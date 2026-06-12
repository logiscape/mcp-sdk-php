<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
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
 * Filename: Client/Transport/HttpRequestTimeoutException.php
 */

declare(strict_types=1);

namespace Mcp\Client\Transport;

use RuntimeException;

/**
 * Exception thrown when an HTTP request times out at the transport level
 * (cURL CURLE_OPERATION_TIMEOUTED) without producing a response.
 *
 * Distinct from the generic transport RuntimeException so callers can
 * react to "the server never answered" specifically: the dual-era
 * negotiation (SEP-2575, WS2) treats a timed-out `server/discover` probe
 * as a silent legacy server and falls back to the initialize handshake,
 * while other transport failures (connection refused, TLS errors)
 * propagate.
 */
class HttpRequestTimeoutException extends RuntimeException
{
}
