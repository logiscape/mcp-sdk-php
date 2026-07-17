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
 * Filename: Server/Tasks/TaskTransitionRejectedException.php
 */

declare(strict_types=1);

namespace Mcp\Server\Tasks;

/**
 * An illegal SEP-2663 task state transition was rejected by the store —
 * typically because the task went terminal first (a `tasks/cancel` or a
 * concurrent settlement won the race), which the flock-guarded
 * terminal-state check serializes.
 *
 * Thrown by {@see \Mcp\Server\TaskManager} from `complete()`, `fail()`,
 * `updateStatus()`, and `setInputRequired()`. Out-of-band workers should
 * catch this precise type when settling a task that may have been
 * cancelled concurrently (see the Tasks guide, "Cancellation races") —
 * unlike a bare `\InvalidArgumentException` catch, it cannot swallow a
 * genuine programming error such as a malformed argument.
 *
 * Extends `\InvalidArgumentException`, which the rejection previously
 * threw directly, so existing catch blocks keep working unchanged.
 */
class TaskTransitionRejectedException extends \InvalidArgumentException
{
    /**
     * @param string $fromStatus The status observed in the store when the
     *        transition was rejected (a {@see \Mcp\Types\TaskStatus}
     *        constant — the terminal status, in the lost-race case)
     * @param string $toStatus The status the rejected transition targeted
     */
    public function __construct(
        public readonly string $fromStatus,
        public readonly string $toStatus,
    ) {
        parent::__construct(
            "Invalid task state transition from '{$fromStatus}' to '{$toStatus}'"
        );
    }
}
