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
 * Filename: Server/Tasks/TaskDeferredException.php
 */

declare(strict_types=1);

namespace Mcp\Server\Tasks;

/**
 * Control-flow signal of the SEP-2663 application-driven task model
 * (revision 2026-07-28).
 *
 * Thrown by {@see TaskContext::defer()} when a task-augmented tool has
 * handed its work to an out-of-band worker (a queue job, cron run, or
 * another process). McpServer catches it in the task round: the task is
 * left in the `working` state and the CreateTaskResult handle is returned
 * to the client, which polls `tasks/get` while the application settles the
 * task through {@see \Mcp\Server\McpServer::getTaskManager()}.
 */
class TaskDeferredException extends \RuntimeException
{
    /**
     * @param string|null $statusMessage Optional human-readable status for
     *        the still-working task handle (e.g. "queued for worker")
     */
    public function __construct(
        public readonly ?string $statusMessage = null,
    ) {
        parent::__construct('Task deferred to an application-driven worker (SEP-2663)');
    }
}
