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
     */
    public static function unknownResource(string $uri): self
    {
        return new self(new ErrorData(
            code: -32002,
            message: "Unknown resource: {$uri}"
        ));
    }

    /**
     * Create a new exception for a task that was not found.
     */
    public static function taskNotFound(string $taskId): self
    {
        return new self(new ErrorData(
            code: -32602,
            message: "Task not found: {$taskId}"
        ));
    }

    /**
     * Create a new exception for a task that cannot be cancelled.
     */
    public static function taskNotCancellable(string $taskId, string $status): self
    {
        return new self(new ErrorData(
            code: -32602,
            message: "Task '{$taskId}' cannot be cancelled in state '{$status}'"
        ));
    }

    /**
     * Create a new exception for a task result that is not yet available.
     */
    public static function taskResultNotAvailable(string $taskId): self
    {
        return new self(new ErrorData(
            code: -32602,
            message: "Result not available for task: {$taskId}"
        ));
    }
}
