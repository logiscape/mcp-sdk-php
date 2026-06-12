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
 * Filename: Types/JsonRpcMessage.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * JSON-RPC message is a union of:
 * - JSONRPCRequest
 * - JSONRPCNotification
 * - JSONRPCResponse
 * - JSONRPCError
 *
 * This class acts as a RootModel for that union.
 */
class JsonRpcMessage implements McpModel {
    use ExtraFieldsTrait;

    /**
     * Transport-layer HTTP status hint (SEP-2575).
     *
     * On the 2026-07-28 stateless path certain JSON-RPC errors must be
     * delivered with a specific HTTP status (400 for -32602/-32003/-32004/
     * -32001, 404 for -32601). The server session stamps this hint when it
     * writes such an error, and the HTTP transport applies it when it
     * builds the response — a structured signal between the two layers
     * that replaces re-decoding the serialized body. The hint is never
     * serialized onto the wire and is ignored by non-HTTP transports.
     */
    public ?int $httpStatusHint = null;

    /**
     * We store one of the four possible variants.
     */
    public function __construct(
        public readonly JSONRPCRequest|JSONRPCNotification|JSONRPCResponse|JSONRPCError $message
    ) {}

    public function validate(): void {
        $this->message->validate();
    }

    public function jsonSerialize(): mixed {
        // Just serialize the underlying message variant.
        $data = $this->message->jsonSerialize();

        // Merge any extra fields set directly on JsonRpcMessage (rare)
        return array_merge((array)$data, $this->extraFields);
    }

}