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
 * - ChatGPT o1 pro mode
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
 * Filename: Server/Transport/TransportClosedException.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport;

use RuntimeException;

/**
 * Thrown when the peer has closed the transport's incoming channel and no
 * further messages can ever arrive — for the stdio transport, EOF on STDIN,
 * which is how an MCP client initiates shutdown per the spec's lifecycle
 * rules (close stdin first, then wait for the server process to exit).
 *
 * ServerSession treats this as a clean shutdown signal: the message loop
 * stops and the session is closed, allowing the server process to terminate
 * instead of busy-waiting on a dead stream. Code that catches \Throwable
 * around handler execution must rethrow this exception so it can reach the
 * message loop.
 */
class TransportClosedException extends RuntimeException
{
}
