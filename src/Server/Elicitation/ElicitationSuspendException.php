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
 * Filename: Server/Elicitation/ElicitationSuspendException.php
 */

declare(strict_types=1);

namespace Mcp\Server\Elicitation;

use Mcp\Types\ElicitationCreateRequest;

/**
 * Exception thrown to suspend a tool handler's execution when elicitation is needed
 * in an HTTP (stateless) transport context.
 *
 * This is NOT a JSON-RPC error — it is caught by the server handler layer to trigger
 * the suspend/resume pattern for multi-round HTTP exchanges.
 */
class ElicitationSuspendException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $toolArguments
     * @param array<int, array<string, mixed>> $previousResults
     */
    public function __construct(
        public readonly ElicitationCreateRequest $request,
        public readonly string $toolName,
        public readonly array $toolArguments,
        public readonly int $originalRequestId,
        public readonly int $elicitationSequence,
        public readonly array $previousResults = [],
    ) {
        parent::__construct('Elicitation required: tool handler suspended pending client response');
    }
}
