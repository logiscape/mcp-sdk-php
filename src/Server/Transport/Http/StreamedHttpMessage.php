<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2025 Logiscape LLC <https://logiscape.com>
 *
 * Developed by:
 * - Josh Abbott
 * - Claude 3.7 Sonnet (Anthropic AI model)
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
 * Filename: Server/Transport/Http/StreamedHttpMessage.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport\Http;

/**
 * Marker subclass of HttpMessage signalling that the streaming SSE body
 * was already written to the configured HttpIoInterface during handler
 * execution. The runner's sendResponse() path uses instanceof checks to
 * skip body emission — integrators embedding the runner in a framework
 * can do the same without sniffing the transitional `X-Mcp-Already-
 * Emitted: 1` header (kept on the message for one release for backward
 * compatibility with anything that may already check for it).
 *
 * This class adds no new fields — the semantic is carried by the type
 * alone, keeping serialization and header behavior identical to the
 * underlying HttpMessage.
 */
final class StreamedHttpMessage extends HttpMessage
{
}
