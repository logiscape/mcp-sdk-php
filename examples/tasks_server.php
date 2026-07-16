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
 * client answers via `tasks/update`. A tool can instead hand its work to an
 * out-of-band worker with TaskContext::defer(), leaving the task `working`
 * until the worker settles it through getTaskManager() — the `queue-batch`
 * tool below demonstrates this.
 *
 * Run:
 *   stdio:  php examples/tasks_server.php   (spawned by examples/tasks_client.php)
 *   HTTP:   php -S localhost:8000 examples/tasks_server.php
 *   worker: php examples/tasks_server.php --worker
 *           (settles tasks deferred by `queue-batch`; in production this
 *           would be a cron job or queue consumer)
 */

require 'vendor/autoload.php';

use Mcp\Server\Elicitation\ElicitationContext;
use Mcp\Server\McpServer;
use Mcp\Server\Tasks\TaskContext;
use Mcp\Server\TaskSupport;

// The "queue" the deferring tool and the worker share. Any real job backend
// (database table, Redis list, ...) works the same way — the only thing the
// worker needs is the taskId.
$jobDir = sys_get_temp_dir() . '/mcp_example_jobs';

$server = new McpServer('tasks-example-server');

// Declare the Tasks extension and register tasks/get, tasks/update,
// tasks/cancel. Arguments: storage path (null = system temp directory),
// default task ttlMs, default pollIntervalMs hint for clients. The ttl must
// outlive the longest deferred job — expired records are deleted regardless
// of status.
$server->enableTasks(null, 60000, 250);

// Worker mode: settle every queued job through the same file-backed store
// the serving processes use (getTaskManager()), then exit.
if (PHP_SAPI === 'cli' && in_array('--worker', $argv ?? [], true)) {
    $tasks = $server->getTaskManager();
    foreach (glob($jobDir . '/*.json') ?: [] as $jobFile) {
        $job = json_decode((string) file_get_contents($jobFile), true);
        $taskId = is_array($job) ? (string) ($job['taskId'] ?? '') : '';
        $record = $tasks->getRecord($taskId);
        // Gone (expired) or no longer working (e.g. cancelled): drop the job.
        if ($record === null || $record['status'] !== 'working') {
            unlink($jobFile);
            continue;
        }
        $dataset = (string) ($job['dataset'] ?? '?');
        $tasks->updateStatus($taskId, 'working', 'processing batch');
        // ... the actual slow work would run here ...
        $tasks->complete($taskId, [
            'content' => [['type' => 'text', 'text' => "Batch '{$dataset}' finished."]],
        ]);
        unlink($jobFile);
        echo "Completed task {$taskId}\n";
    }
    exit(0);
}

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

    // OPTIONAL + deferral: running as a task, the tool queues the job and
    // defers — the client gets a `working` CreateTaskResult and polls
    // tasks/get while the worker (`php examples/tasks_server.php --worker`)
    // settles the record out-of-band. On a synchronous call (client without
    // the Tasks extension) it runs inline instead.
    ->tool(
        'queue-batch',
        'Queues a batch job for the background worker (task-capable)',
        function (TaskContext $task, string $dataset) use ($jobDir): string {
            if (!$task->isTask()) {
                return "Batch '{$dataset}' finished."; // inline fallback
            }
            if (!is_dir($jobDir)) {
                mkdir($jobDir, 0755, true);
            }
            file_put_contents(
                $jobDir . '/' . $task->taskId() . '.json',
                json_encode(['taskId' => $task->taskId(), 'dataset' => $dataset])
            );
            $task->defer('queued for worker'); // never returns
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
