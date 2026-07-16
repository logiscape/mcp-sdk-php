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
 * Filename: Server/Tasks/TaskContext.php
 */

declare(strict_types=1);

namespace Mcp\Server\Tasks;

use Mcp\Server\McpServerException;

/**
 * Task-awareness context for tool callbacks (SEP-2663 Tasks extension,
 * revision 2026-07-28), injected by McpServer when the callback declares a
 * `TaskContext` parameter.
 *
 * When the call is running as a task, {@see taskId()} identifies the task
 * record, and {@see defer()} lets the callback hand the work to an
 * out-of-band worker instead of finishing synchronously: the task stays
 * `working`, the client receives the CreateTaskResult handle and polls
 * `tasks/get`, and the application later settles the record through
 * {@see \Mcp\Server\McpServer::getTaskManager()} (updateStatus / complete /
 * fail).
 *
 * ```php
 * $server->tool('start-batch', 'Queues a batch job',
 *     function (TaskContext $task, string $dataset): string {
 *         if (!$task->isTask()) {
 *             return runBatchInline($dataset); // synchronous fallback
 *         }
 *         enqueueJob(['taskId' => $task->taskId(), 'dataset' => $dataset]);
 *         $task->defer('queued for worker');
 *     },
 *     taskSupport: TaskSupport::OPTIONAL,
 * );
 * ```
 *
 * The context is always injected non-null; on a synchronous (non-task)
 * invocation it is inert — `isTask()` is false and `defer()` raises a
 * protocol error.
 */
final class TaskContext
{
    public function __construct(
        private readonly ?string $taskId = null,
        private readonly string $toolName = '',
    ) {}

    /**
     * The taskId of the task this call is executing under, or null when the
     * call is a plain synchronous tools/call.
     */
    public function taskId(): ?string
    {
        return $this->taskId;
    }

    /**
     * Whether this call is running as a task (tasks enabled, the tool
     * declared taskSupport, and the client opted into the Tasks extension).
     */
    public function isTask(): bool
    {
        return $this->taskId !== null;
    }

    /**
     * Defer the task to an out-of-band worker: the task remains `working`
     * and this round returns the task handle to the client. Never returns.
     *
     * Hand {@see taskId()} to the worker before calling this — it is the
     * only key to the record. On a non-task invocation this is a
     * programming error and raises -32603; guard with {@see isTask()}.
     *
     * @param string|null $statusMessage Optional status shown on the handle
     * @throws TaskDeferredException Always, when running as a task
     * @throws McpServerException When the call is not running as a task
     */
    public function defer(?string $statusMessage = null): never
    {
        if ($this->taskId === null) {
            throw McpServerException::taskDeferralUnavailable($this->toolName);
        }
        throw new TaskDeferredException($statusMessage);
    }
}
