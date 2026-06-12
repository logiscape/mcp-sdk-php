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
 * Filename: Shared/McpError.php
 */

declare(strict_types=1);

namespace Mcp\Shared;

use Exception;

/**
 * Exception type raised when an error arrives over an MCP connection.
 *
 * Wraps the ErrorData object in an exception for easy error handling.
 */
class McpError extends Exception {
    public const URL_ELICITATION_REQUIRED = -32042;

    /**
     * 2026-07-28 (SEP-2243): a request-metadata header does not match the
     * request body (also covers MCP-Protocol-Version vs _meta mismatches).
     */
    public const HEADER_MISMATCH = -32001;

    /**
     * 2026-07-28 (SEP-2575): the client lacks a capability the request
     * requires. error.data carries `requiredCapabilities`. HTTP 400.
     */
    public const MISSING_REQUIRED_CLIENT_CAPABILITY = -32003;

    /**
     * 2026-07-28 (SEP-2575): the protocol version requested in _meta is not
     * supported. error.data carries `supported` (string[]) and `requested`
     * (string). HTTP 400.
     */
    public const UNSUPPORTED_PROTOCOL_VERSION = -32004;

    public function __construct(
        public readonly ErrorData $error,
        ?\Throwable $previous = null
    ) {
        parent::__construct($error->message, $error->code, $previous);
    }
}