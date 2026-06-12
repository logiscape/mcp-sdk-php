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
 * Filename: Server/InputRequired/InputRequiredSuspendException.php
 */

declare(strict_types=1);

namespace Mcp\Server\InputRequired;

use Mcp\Types\Request;

/**
 * Control-flow signal of the SEP-2322 multi-round-trip mechanism
 * (revision 2026-07-28).
 *
 * Thrown when a handler needs client-side input that is not available in
 * this round's `inputResponses`. McpServer catches it at dispatch and
 * answers the request with an {@see \Mcp\Types\InputRequiredResult}
 * carrying the queued input requests plus an integrity-protected
 * `requestState` that carries already-resolved results into the next
 * round (the handler re-executes from scratch on the retry — the
 * ephemeral re-execution model).
 */
class InputRequiredSuspendException extends \RuntimeException
{
    /**
     * @param array<string, Request> $inputRequests Pending requests keyed
     *        by the names the retry's inputResponses must use
     * @param array<string, mixed> $carryResults Raw results resolved in
     *        this or earlier rounds, to be carried in requestState
     */
    public function __construct(
        public readonly array $inputRequests,
        public readonly array $carryResults = [],
    ) {
        parent::__construct('Input required from client (SEP-2322 multi-round-trip)');
    }
}
