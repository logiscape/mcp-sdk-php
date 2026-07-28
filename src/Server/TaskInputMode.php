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
 * Filename: Server/TaskInputMode.php
 */

declare(strict_types=1);

namespace Mcp\Server;

/**
 * Per-tool input-composition mode for task-augmented tools (SEP-2663 Tasks
 * extension combined with SEP-2322 multi-round-trip input).
 *
 * A task-supporting tool that gathers client input can do so through either
 * of two spec-permitted sequences; this knob picks one per tool. Like
 * {@see TaskSupport}, the value has no wire representation — it only shapes
 * which result the server answers with.
 *
 * - IN_TASK (default): the task handle is minted first and input is
 *   gathered in-task — the `CreateTaskResult` is returned immediately, the
 *   task parks in `input_required`, `tasks/get` surfaces the pending
 *   `inputRequests`, and the client answers through `tasks/update`.
 * - PRE_TASK: input is resolved *before* the task exists, via the plain
 *   multi-round-trip loop — each round answers the `tools/call` with an
 *   `input_required` result carrying a signed `requestState` and no task
 *   record is created; only the final round (all input resolved, body ran
 *   to completion) mints the task and returns a `CreateTaskResult`. This
 *   is the composition the spec recommends: "Server implementations that
 *   use multi round-trip requests in conjunction with task creation ...
 *   SHOULD resolve all MRTR exchanges synchronously before responding
 *   with a CreateTaskResult" (ext-tasks specification, Task Creation).
 *
 * PRE_TASK differences to be aware of (documented in docs/tasks.md):
 * a protocol error thrown during a pre-task round surfaces as a JSON-RPC
 * error rather than a `failed` task (no task exists to fail), and
 * `TaskContext::defer()` is unavailable during pre-task rounds — a tool
 * that defers to a background worker should use IN_TASK.
 */
final class TaskInputMode {
    public const IN_TASK = 'in-task';
    public const PRE_TASK = 'pre-task';

    public const ALL = [
        self::IN_TASK,
        self::PRE_TASK,
    ];

    public static function isValid(string $value): bool {
        return in_array($value, self::ALL, true);
    }

    private function __construct() {
    }
}
