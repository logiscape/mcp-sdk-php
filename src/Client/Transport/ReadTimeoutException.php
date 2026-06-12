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
 * Filename: Client/Transport/ReadTimeoutException.php
 */

declare(strict_types=1);

namespace Mcp\Client\Transport;

use RuntimeException;

/**
 * Thrown by the client session's read paths when the configured read
 * timeout elapses without a message from the peer.
 *
 * Extends RuntimeException so existing catch sites keep working; the
 * dedicated type lets callers — notably the dual-era negotiation's
 * silent-legacy-server detection — classify a timeout without sniffing
 * exception message strings. It is the stdio/in-process counterpart of
 * {@see HttpRequestTimeoutException}, which covers cURL-level timeouts on
 * the HTTP transport.
 */
class ReadTimeoutException extends RuntimeException
{
}
