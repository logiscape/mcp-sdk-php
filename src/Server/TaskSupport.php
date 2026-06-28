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
 * Filename: Server/TaskSupport.php
 */

declare(strict_types=1);

namespace Mcp\Server;

/**
 * Per-tool task-augmentation policy for the SEP-2663 Tasks extension.
 *
 * Task creation is server-directed: the wire carries no per-tool flag and no
 * `task` request parameter (a legacy `task` param is tolerated and ignored).
 * This enum is the SDK's server-side knob for how `McpServer` decides, per
 * `tools/call`, whether to return a `CreateTaskResult` instead of a normal
 * `CallToolResult`.
 *
 * - FORBIDDEN (default): the tool always runs synchronously; a task is never
 *   created.
 * - OPTIONAL: the tool runs as a task when the calling client declared the
 *   Tasks extension; otherwise it falls back to a synchronous result (per the
 *   conformance "server rejects undeclared client" expectation — fall through,
 *   do not error).
 * - REQUIRED: the tool can only be served as a task; a client that did not
 *   declare the extension is rejected with -32021
 *   (MissingRequiredClientCapability) carrying
 *   `data.requiredCapabilities.extensions["io.modelcontextprotocol/tasks"]`.
 */
final class TaskSupport {
    public const FORBIDDEN = 'forbidden';
    public const OPTIONAL = 'optional';
    public const REQUIRED = 'required';

    public const ALL = [
        self::FORBIDDEN,
        self::OPTIONAL,
        self::REQUIRED,
    ];

    public static function isValid(string $value): bool {
        return in_array($value, self::ALL, true);
    }

    private function __construct() {
    }
}
