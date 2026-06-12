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
 * Filename: Shared/UnknownMethodException.php
 */

declare(strict_types=1);

namespace Mcp\Shared;

/**
 * Thrown when an incoming JSON-RPC request names a method this peer does
 * not implement (e.g. {@see \Mcp\Types\ClientRequest::fromMethodAndParams()}
 * falls through its method dispatch).
 *
 * Extends InvalidArgumentException so existing catch sites keep working;
 * the dedicated type lets callers distinguish "unknown method" (answer
 * -32601 Method not found) from "known method, malformed params" (answer
 * -32602 Invalid params) without sniffing exception message strings.
 */
class UnknownMethodException extends \InvalidArgumentException
{
}
