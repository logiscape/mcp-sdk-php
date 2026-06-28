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
 * Filename: Server/McpServerException.php
 */

declare(strict_types=1);

namespace Mcp\Server;

use Mcp\Shared\ErrorData;
use Mcp\Shared\McpError;

/**
 * Exception thrown by the McpServer class.
 *
 * Extends McpError so that exceptions thrown from McpServer handlers are
 * caught by Server::handleMessage()'s `catch (McpError $e)` branch and
 * returned as proper JSON-RPC error responses with specific error codes.
 *
 * JSON-RPC error codes used:
 * - -32602: Invalid params (unknown tool/prompt/resource)
 * - -32603: Internal error (invalid handler return type)
 *
 * Class adapted from pronskiy/mcp (https://github.com/pronskiy/mcp)
 * Copyright (c) pronskiy <roman@pronskiy.com>
 * Licensed under the MIT License
 */
class McpServerException extends McpError
{
    /**
     * Create a new exception for an invalid tool handler result.
     */
    public static function invalidToolResult(mixed $result): self
    {
        $type = is_object($result) ? get_class($result) : gettype($result);
        return new self(new ErrorData(
            code: -32603,
            message: "Invalid tool handler result: expected string or CallToolResult, got {$type}"
        ));
    }

    /**
     * Create a new exception for an invalid prompt handler result.
     */
    public static function invalidPromptResult(mixed $result): self
    {
        $type = is_object($result) ? get_class($result) : gettype($result);
        return new self(new ErrorData(
            code: -32603,
            message: "Invalid prompt handler result: expected string, array, or GetPromptResult, got {$type}"
        ));
    }

    /**
     * Create a new exception for an invalid resource handler result.
     */
    public static function invalidResourceResult(mixed $result): self
    {
        $type = is_object($result) ? get_class($result) : gettype($result);
        return new self(new ErrorData(
            code: -32603,
            message: "Invalid resource handler result: expected string, SplFileObject, resource, or ReadResourceResult, got {$type}"
        ));
    }

    /**
     * Create a new exception for an unknown tool.
     */
    public static function unknownTool(string $name): self
    {
        return new self(new ErrorData(
            code: -32602,
            message: "Unknown tool: {$name}"
        ));
    }

    /**
     * Create a new exception for an unknown prompt.
     */
    public static function unknownPrompt(string $name): self
    {
        return new self(new ErrorData(
            code: -32602,
            message: "Unknown prompt: {$name}"
        ));
    }

    /**
     * Create a new exception for an unknown resource.
     *
     * SEP-2164: under the 2026-07-28 revision a missing resource is an
     * Invalid Params error (-32602); earlier revisions use -32002. Both
     * shapes carry the requested URI in error.data per the spec's examples.
     *
     * @param bool $modernErrorCode True when the negotiated protocol revision
     *        is 2026-07-28 or newer (see Version feature
     *        'resource_not_found_invalid_params')
     */
    public static function unknownResource(string $uri, bool $modernErrorCode = false): self
    {
        if ($modernErrorCode) {
            return new self(new ErrorData(
                code: -32602,
                message: 'Resource not found',
                data: ['uri' => $uri]
            ));
        }

        return new self(new ErrorData(
            code: -32002,
            message: "Unknown resource: {$uri}",
            data: ['uri' => $uri]
        ));
    }

    /**
     * Create a new exception for an unknown resource template.
     *
     * Used by the completion handler when a `ref/resource` completion names a
     * URI that matches no registered template. The completion spec classifies
     * an invalid reference as Invalid params (-32602), not "resource not found".
     */
    public static function unknownResourceTemplate(string $uriTemplate): self
    {
        return new self(new ErrorData(
            code: -32602,
            message: "Unknown resource template: {$uriTemplate}"
        ));
    }

    /**
     * Create a new exception for a malformed/invalid completion reference.
     */
    public static function invalidCompletionRef(): self
    {
        return new self(new ErrorData(
            code: -32602,
            message: "Invalid completion reference"
        ));
    }

    /**
     * Create a new exception for an invalid completion provider result.
     */
    public static function invalidCompletionResult(mixed $result): self
    {
        $type = is_object($result) ? get_class($result) : gettype($result);
        return new self(new ErrorData(
            code: -32603,
            message: "Invalid completion provider result: expected array of strings, CompletionObject, or CompleteResult, got {$type}"
        ));
    }

    /**
     * Create a new exception for a task that was not found (SEP-2663: an
     * unknown taskId on tasks/get, tasks/update, or tasks/cancel is -32602).
     */
    public static function taskNotFound(string $taskId): self
    {
        return new self(new ErrorData(
            code: -32602,
            message: "Task not found: {$taskId}"
        ));
    }

    /**
     * Create a URL elicitation required error (-32042).
     *
     * Per MCP spec: returned when a request cannot be processed until
     * URL-mode elicitation is completed. The error data includes a list
     * of elicitations the client must complete.
     *
     * @param array<int, array<string, mixed>> $elicitations List of elicitation requests
     */
    public static function urlElicitationRequired(array $elicitations, string $message = ''): self
    {
        return new self(new ErrorData(
            code: McpError::URL_ELICITATION_REQUIRED,
            message: $message ?: 'This request requires URL elicitation to proceed.',
            data: ['elicitations' => $elicitations],
        ));
    }

    /**
     * Create an error for when the client does not support the required elicitation mode.
     */
    public static function elicitationNotSupported(string $mode = 'elicitation'): self
    {
        return new self(new ErrorData(
            code: -32602,
            message: "Client does not support {$mode}"
        ));
    }
}
