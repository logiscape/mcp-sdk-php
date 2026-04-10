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
 * Filename: Server/Elicitation/ElicitationDeclinedException.php
 */

declare(strict_types=1);

namespace Mcp\Server\Elicitation;

/**
 * Exception thrown when the client declines or cancels an elicitation request.
 *
 * This is a convenience exception for use with ElicitationContext::requiresForm()
 * and ElicitationContext::requiresUrl() methods.
 */
class ElicitationDeclinedException extends \RuntimeException
{
    public function __construct(
        public readonly string $action,
        string $message = '',
    ) {
        parent::__construct($message ?: "Elicitation was {$action} by the client");
    }
}
