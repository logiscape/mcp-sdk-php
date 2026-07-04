<?php

/**
 * Tasks extension example server (SEP-2663, extension id
 * "io.modelcontextprotocol/tasks").
 *
 * Tasks let a tool call return a durable handle instead of a final result:
 * `tools/call` answers a CreateTaskResult, and the client follows up with
 * `tasks/get` (status + inlined result once complete), `tasks/update`
 * (answering in-task input requests), and `tasks/cancel`. There is no
 * `tasks/list` or `tasks/result` — both were removed by the stateless
 * redesign and answer -32601.
 *
 * enableTasks() declares the extension in the server's capabilities and
 * registers the three task methods with a file-backed store (shared-hosting
 * friendly: task state survives across PHP processes). A tool opts in with
 * the `taskSupport:` argument:
 *
 *   - TaskSupport::FORBIDDEN (default) — always a synchronous CallToolResult.
 *   - TaskSupport::OPTIONAL — task-augmented when the calling client has
 *     declared the Tasks extension, synchronous otherwise (legacy clients
 *     always get the synchronous form).
 *   - TaskSupport::REQUIRED — task-only; a modern client that has not
 *     declared the extension is rejected with -32021.
 *
 * This SDK executes task bodies synchronously during the creating request
 * (PHP's shared-hosting execution model) and records the outcome for
 * `tasks/get` to surface — so simple tasks are already terminal on the first
 * poll, while tools that request input park in `input_required` until the
 * client answers via `tasks/update`.
 *
 * Run:
 *   stdio: php examples/tasks_server.php   (spawned by examples/tasks_client.php)
 *   HTTP:  php -S localhost:8000 examples/tasks_server.php
 */

require 'vendor/autoload.php';

use Mcp\Server\Elicitation\ElicitationContext;
use Mcp\Server\McpServer;
use Mcp\Server\TaskSupport;

$server = new McpServer('tasks-example-server');

// Declare the Tasks extension and register tasks/get, tasks/update,
// tasks/cancel. Arguments: storage path (null = system temp directory),
// default task ttlMs, default pollIntervalMs hint for clients.
$server->enableTasks(null, 60000, 250);

$server
    // A plain synchronous tool for contrast — never task-augmented.
    ->tool('ping', 'Answers immediately', fn (): string => 'pong')

    // OPTIONAL: clients that declared the Tasks extension get a task handle;
    // everyone else gets the result synchronously.
    ->tool(
        'generate-report',
        'Generates a report on a topic (task-capable)',
        function (string $topic): string {
            // A real server would do slow work here (API calls, queries, ...).
            return "Report on '{$topic}': all figures nominal.";
        },
        taskSupport: TaskSupport::OPTIONAL,
    )

    // REQUIRED + in-task input: the task parks in `input_required` with the
    // elicitation surfaced through tasks/get inputRequests; the client
    // answers with tasks/update {inputResponses: {confirmation: ...}} and the
    // body resumes. The inputKey names the round so a retry resolves to the
    // same request.
    ->tool(
        'archive-project',
        'Archives a project after confirmation (task-only)',
        function (ElicitationContext $elicit, string $project): string {
            $answer = $elicit->form(
                "Really archive project '{$project}'?",
                [
                    'type' => 'object',
                    'properties' => ['confirm' => ['type' => 'boolean']],
                    'required' => ['confirm'],
                ],
                inputKey: 'confirmation',
            );

            $content = $answer?->content;
            $confirmed = is_array($content)
                ? ($content['confirm'] ?? false)
                : ($content->confirm ?? false);

            if ($answer?->action !== 'accept' || $confirmed !== true) {
                return "Archive of '{$project}' declined.";
            }
            return "Project '{$project}' archived.";
        },
        taskSupport: TaskSupport::REQUIRED,
    )

    // run() auto-selects stdio on the CLI and HTTP under a web server.
    ->run();
