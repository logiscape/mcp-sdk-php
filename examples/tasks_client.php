<?php

/**
 * Tasks extension example client (SEP-2663).
 *
 * Demonstrates the full client-side task lifecycle against
 * examples/tasks_server.php:
 *
 *   1. Declare the Tasks extension (required — without it the server treats
 *      every tool call as synchronous, and task-only tools reject -32021).
 *   2. callTool() — returns a CreateTaskResult (the task handle) instead of
 *      a CallToolResult when the server augments the call as a task.
 *   3. Poll getTask() at the server's pollIntervalMs until the task reaches
 *      a terminal status; the completed result is inlined in the tasks/get
 *      response (there is no tasks/result method).
 *   4. When status is `input_required`, answer the surfaced inputRequests
 *      with updateTask() and keep polling.
 *
 * Tasks are a 2026-07-28 extension: the session must negotiate the modern
 * era (any v2 server, including examples/tasks_server.php, will).
 *
 * Usage:
 *   php examples/tasks_client.php                       (spawns tasks_server.php over stdio)
 *   php examples/tasks_client.php http://localhost:8000 (existing HTTP server)
 */

require 'vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\ClientSession;
use Mcp\Types\CallToolResult;
use Mcp\Types\CreateTaskResult;
use Mcp\Types\ElicitationCreateRequest;
use Mcp\Types\ElicitationCreateResult;
use Mcp\Types\ExtensionIds;

/**
 * Poll a task to its terminal status, answering input requests along the way.
 *
 * @param callable(array<string, array<string, mixed>>): array<string, array<string, mixed>> $answerInputs
 *        Maps pending inputRequests (keyed by inputKey) to inputResponses.
 */
function awaitTask(ClientSession $session, string $taskId, callable $answerInputs): void
{
    while (true) {
        $get = $session->getTask($taskId);
        $task = $get->task;
        echo "  status: {$task->status}\n";

        switch ($task->status) {
            case 'input_required':
                // tasks/get surfaces the pending requests keyed by inputKey;
                // tasks/update carries the answers and resumes the tool body.
                foreach ($get->inputRequests ?? [] as $key => $request) {
                    echo "  input requested ({$key}): {$request['method']}\n";
                }
                $session->updateTask($taskId, $answerInputs($get->inputRequests ?? []));
                break;

            case 'working':
                usleep(($task->pollIntervalMs ?? 250) * 1000);
                break;

            case 'completed':
                if ($get->result !== null) {
                    $text = $get->result['content'][0]['text'] ?? json_encode($get->result);
                    echo "  result: {$text}\n";
                }
                return;

            case 'failed':
                echo '  error: ' . json_encode($get->error) . "\n";
                return;

            default: // cancelled
                return;
        }
    }
}

$client = new Client();

// A server tool may only elicit from clients that advertise the elicitation
// capability — registering a handler (before connect) is what advertises it.
// For task-augmented calls the input still arrives through tasks/get
// inputRequests and is answered via tasks/update below; this handler services
// any direct (non-task) elicitation.
$client->onElicit(fn (ElicitationCreateRequest $request): ElicitationCreateResult =>
    new ElicitationCreateResult(action: 'accept', content: ['confirm' => true]));

try {
    $target = $argv[1] ?? null;

    if ($target !== null && (str_starts_with($target, 'http://') || str_starts_with($target, 'https://'))) {
        $session = $client->connect($target);
    } else {
        $session = $client->connect('php', [__DIR__ . '/tasks_server.php']);
    }

    if (!$session->isModernMode()) {
        fwrite(STDERR, "Server negotiated the legacy era — the Tasks extension requires 2026-07-28.\n");
        exit(1);
    }

    // Advertise the Tasks extension in every request's _meta envelope. This
    // is what allows the server to answer tools/call with a task handle.
    $session->declareExtension(ExtensionIds::TASKS);

    // --- A task-optional tool -----------------------------------------------
    echo "callTool('generate-report', {topic: 'quarterly sales'})\n";
    $result = $session->callTool('generate-report', ['topic' => 'quarterly sales']);

    if ($result instanceof CreateTaskResult) {
        echo "  -> task {$result->task->taskId}\n";
        awaitTask($session, $result->task->taskId, fn (array $requests): array => []);
    } elseif ($result instanceof CallToolResult) {
        // A server without task support (or a legacy session) answers directly.
        echo "  -> synchronous result: {$result->content[0]->text}\n";
    }

    // --- A task-only tool with in-task input --------------------------------
    echo "\ncallTool('archive-project', {project: 'atlas'})\n";
    $result = $session->callTool('archive-project', ['project' => 'atlas']);

    if ($result instanceof CreateTaskResult) {
        echo "  -> task {$result->task->taskId}\n";
        awaitTask($session, $result->task->taskId, function (array $requests): array {
            // Answer every pending elicitation with an acceptance. A real
            // client would render each request's params (message +
            // requestedSchema) to the user and collect real input.
            $responses = [];
            foreach (array_keys($requests) as $key) {
                $responses[$key] = ['action' => 'accept', 'content' => ['confirm' => true]];
            }
            return $responses;
        });
    }
} catch (\Exception $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
} finally {
    $client->close();
}
