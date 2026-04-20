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
 * Filename: Server/Sampling/SamplingSuspendException.php
 */

declare(strict_types=1);

namespace Mcp\Server\Sampling;

use Mcp\Server\ClientRequestSuspendException;
use Mcp\Types\CreateMessageRequest;
use Mcp\Types\Request;

/**
 * Exception thrown to suspend a tool handler's execution when a
 * `sampling/createMessage` call is needed in an HTTP (stateless) transport
 * context.
 *
 * Caught by `HttpServerSession::handleRequest()` to trigger the suspend/resume
 * pattern: the outgoing sampling request is written to the client, pending
 * state is persisted, and the tool handler is re-invoked once the client's
 * response arrives in a subsequent HTTP cycle.
 */
class SamplingSuspendException extends ClientRequestSuspendException
{
    /**
     * @param array<string, mixed> $toolArguments
     * @param array<int, array<string, mixed>> $previousResults
     * @param int|string $originalRequestId JSON-RPC id of the originating tools/call (`string | number`).
     */
    public function __construct(
        public readonly CreateMessageRequest $request,
        string $toolName,
        array $toolArguments,
        int|string $originalRequestId,
        public readonly int $samplingSequence,
        array $previousResults = [],
    ) {
        parent::__construct(
            toolName: $toolName,
            toolArguments: $toolArguments,
            originalRequestId: $originalRequestId,
            sequence: $samplingSequence,
            previousResults: $previousResults,
            message: 'Sampling required: tool handler suspended pending client response',
        );
    }

    public function getRequest(): Request
    {
        return $this->request;
    }
}
