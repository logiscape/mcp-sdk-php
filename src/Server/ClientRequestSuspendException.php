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
 * Filename: Server/ClientRequestSuspendException.php
 */

declare(strict_types=1);

namespace Mcp\Server;

use Mcp\Types\Request;

/**
 * Abstract base for exceptions that suspend a tool handler in HTTP mode so a
 * server-to-client request (elicitation/create, sampling/createMessage, ...)
 * can be emitted and answered in a subsequent HTTP round-trip.
 *
 * `HttpServerSession::handleRequest()` catches this single type and routes to
 * the feature-specific suspend handler based on the concrete subclass. Adding
 * a new bidirectional feature only requires a new subclass plus its own
 * match/handle pair — the dispatch seam doesn't need to change.
 *
 * Not used in stdio mode, where `BaseSession::sendRequest()` blocks on a
 * matching response synchronously.
 */
abstract class ClientRequestSuspendException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $toolArguments
     * @param array<int, array<string, mixed>> $previousResults
     * @param int|string $originalRequestId JSON-RPC request id of the originating tools/call
     *                   (`string | number` per the spec).
     */
    public function __construct(
        public readonly string $toolName,
        public readonly array $toolArguments,
        public readonly int|string $originalRequestId,
        public readonly int $sequence,
        public readonly array $previousResults = [],
        string $message = 'Tool handler suspended pending client response',
    ) {
        parent::__construct($message);
    }

    /**
     * The outgoing JSON-RPC request the HTTP session should write to the client.
     */
    abstract public function getRequest(): Request;
}
