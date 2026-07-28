# Tasks Extension Guide (SEP-2663)

The MCP **Tasks extension** lets a tool call return a durable *task handle*
instead of a final result: the client polls the task's status, answers any
input the task requests along the way, and receives the tool's result inline
once the task completes. Tasks make long-running work practical — the
original `tools/call` round-trip stays short, and the work's outcome
survives beyond it.

This guide covers both sides: serving tasks with `McpServer` and consuming
them with `ClientSession`. A complete runnable pair lives at
[`examples/tasks_server.php`](../examples/tasks_server.php) and
[`examples/tasks_client.php`](../examples/tasks_client.php).

**Requirements.** Tasks are an extension of the `2026-07-28` protocol
revision, identified by the reverse-DNS id `io.modelcontextprotocol/tasks`
(`Mcp\Types\ExtensionIds::TASKS`). Both sides must declare it: the server
via `enableTasks()`, the client via `declareExtension()`. Any `tasks/*`
call from a client that has not declared the extension — and any
`tools/call` on a task-*required* tool — is rejected with `-32021`
(`MissingRequiredClientCapability`).

## The method surface

| Method | Purpose |
| --- | --- |
| `tools/call` | Returns a flat `CreateTaskResult` (`resultType: "task"`) when the server augments the call as a task; otherwise an ordinary `CallToolResult`. A tool in pre-task input mode answers intermediate rounds with an `input_required` result while its input is still being gathered (see [Pre-task input](#pre-task-input-the-multi-round-trip-composition)) |
| `tasks/get` | Poll a task: status, plus — by status — the inlined `result` (completed), `error` (failed), or pending `inputRequests` (input_required) |
| `tasks/update` | Answer pending input requests (`inputResponses`, keyed by input key); resumes the task body |
| `tasks/cancel` | Request cancellation (cooperative and idempotent; unknown ids are `-32602`) |

There is **no `tasks/list` and no `tasks/result`** — both were removed by
the stateless redesign and answer `-32601`. A completed task's result is
inlined in the `tasks/get` response.

A task moves through the SEP-2663 states: `working` ⇄ `input_required` →
`completed` / `failed` / `cancelled`. The `Task` object carries `taskId`,
`status`, optional `statusMessage`, `createdAt` / `lastUpdatedAt`
timestamps, `ttlMs` (how long the server retains the task; `null` =
unlimited), and `pollIntervalMs` (the server's polling-cadence hint).

## Server side

### Enabling tasks

```php
<?php

require 'vendor/autoload.php';

use Mcp\Server\McpServer;
use Mcp\Server\TaskSupport;

$server = new McpServer('tasks-demo');

// Declares the extension and registers tasks/get, tasks/update, tasks/cancel.
// Arguments: storage path (null = system temp directory), default task ttlMs,
// default pollIntervalMs hint for clients.
$server->enableTasks(null, 60000, 250);

$server
    ->tool(
        'generate-report',
        'Generates a report on a topic (task-capable)',
        function (string $topic): string {
            // A real server would do slow work here (API calls, queries, ...).
            return "Report on '{$topic}': all figures nominal.";
        },
        taskSupport: TaskSupport::OPTIONAL,
    )
    ->run();
```

The task store is **file-based**, so task state survives across PHP
processes — the model that fits typical shared hosting, where every HTTP
request is a fresh process.

### Opting tools in: `taskSupport`

Each tool chooses its relationship to tasks with the `taskSupport:`
argument (`Mcp\Server\TaskSupport` constants):

- **`FORBIDDEN`** (default) — always answers synchronously with a
  `CallToolResult`. Tasks never apply.
- **`OPTIONAL`** — task-augmented when the calling client declared the
  Tasks extension; synchronous for everyone else (legacy clients always
  get the synchronous form). The server decides per request — there is no
  wire flag a client sends to request a task.
- **`REQUIRED`** — task-only. A modern client that has not declared the
  extension is rejected with `-32021` naming the missing extension in
  `data.requiredCapabilities`.

### The execution model: synchronous capture

This SDK executes the tool body **synchronously during the creating
request** and records the outcome for `tasks/get` to surface. That is a
deliberate consequence of PHP's shared-hosting execution model (no
background workers): a simple task is already terminal on the client's
first poll, a tool that requests input parks in `input_required` until
the client answers (or, in pre-task input mode, resolves its input
*before* the task is created — the record is then born terminal), and a
tool that hands its work off defers (next section), leaving the task
`working`.

### Deferring to a background worker

Genuinely asynchronous tasks — work carried out by a cron job, a queue
worker, or another process — are application-driven, in two halves. The
tool side enters the deferred model by declaring a `TaskContext`
parameter and calling `defer()` once the work is handed off:

```php
use Mcp\Server\TaskSupport;
use Mcp\Server\Tasks\TaskContext;

$server->tool(
    'start-batch',
    'Queues a batch job',
    function (TaskContext $task, string $dataset): string {
        if (!$task->isTask()) {
            // TaskSupport::OPTIONAL + a client without the Tasks
            // extension: the call is synchronous — run inline instead.
            return runBatchInline($dataset);
        }
        // Hand the taskId to the worker — it is the only key to the record.
        enqueueJob(['taskId' => $task->taskId(), 'dataset' => $dataset]);
        $task->defer('queued for worker'); // never returns
    },
    taskSupport: TaskSupport::OPTIONAL,
);
```

`defer()` throws a control-flow signal, so it never returns and the
callback's return-value contract does not apply on that path. The task
stays `working` and the client receives the flat `CreateTaskResult`
handle (carrying the optional status message). Calling `defer()` on a
plain synchronous call is a programming error (JSON-RPC `-32603`) —
guard it with `isTask()`, or declare the tool `TaskSupport::REQUIRED`
so it only ever runs as a task.

A fast worker is safe: the worker holds the taskId from the moment the
job is enqueued, and anything it writes before `defer()` unwinds wins —
a progress message it already wrote is kept over `defer()`'s, and a task
it already settled is returned settled on the create response (the same
shape a synchronous-capture task produces).

The worker side — your cron job, queue consumer, or a later request —
updates the same file-backed store through `McpServer::getTaskManager()`:

```php
$tasks = $server->getTaskManager();

// From your worker process, as the work progresses:
$tasks->updateStatus($taskId, 'working', 'crunching batch 3/10');
$tasks->complete($taskId, [
    'content' => [['type' => 'text', 'text' => 'Batch finished.']],
]);
// or: $tasks->fail($taskId, ['code' => -32603, 'message' => 'Batch exploded']);
```

Clients polling `tasks/get` observe every update, whichever process wrote
it. Two operational notes for workers:

- **Cancellation races.** The client may `tasks/cancel` while the worker
  runs. Terminal tasks reject further transitions, so a late
  `complete()`/`fail()` throws
  `Mcp\Server\Tasks\TaskTransitionRejectedException` (carrying the
  observed status as `$fromStatus`) — check `getRecord($taskId)['status']`
  before settling (a `null` record means the task expired or was deleted),
  or catch that precise exception type. Catch it rather than its
  `\InvalidArgumentException` parent, so a genuine programming error (a
  malformed argument, say) is not silently misclassified as a benign lost
  race.
- **TTL.** `ttlMs` counts from creation, and the store deletes expired
  records on access regardless of status — set the `enableTasks()`
  default high enough to outlive the longest job, or `null` for
  unlimited.
- **Store maintenance.** Expired records that are never accessed again
  stay on disk until swept. `cleanup()` removes every expired record in
  one call; for a store too large to examine within one cron budget, the
  bounded `sweep(maxFiles: 500, cursor: $lastCursor)` examines at most
  `maxFiles` records per run and returns a `cursor` to persist and pass
  back next run (`null` means the pass completed — start over). The bound
  covers the per-record work (locked reads, expiry checks, deletions);
  the directory listing itself still enumerates every task filename each
  run, which is cheap by comparison but grows with store size. A sweep
  is space reclamation only, never a correctness requirement: expiry is
  also enforced on every record access.

### Pre-task input: the multi-round-trip composition

By default a task-capable tool that gathers input does so *in-task* (next
section): the handle is minted first and the task parks in
`input_required`. The spec recommends the opposite ordering for servers
that combine multi-round-trip input with task creation: *"Server
implementations that use multi round-trip requests in conjunction with
task creation … SHOULD resolve all MRTR exchanges synchronously before
responding with a CreateTaskResult."* Opt a tool into that composition
with `taskInputMode:` (`Mcp\Server\TaskInputMode` constants):

```php
use Mcp\Server\TaskInputMode;
use Mcp\Server\TaskSupport;

$server->tool(
    'plan-trip',
    'Plans a trip after asking where to go (task-only)',
    function (Mcp\Server\Elicitation\ElicitationContext $elicit): string {
        $answer = $elicit->form(
            'Where to?',
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
            inputKey: 'destination',
        );
        $content = $answer?->content;
        $city = is_array($content) ? ($content['city'] ?? '?') : ($content->city ?? '?');
        return "Trip to {$city} planned.";
    },
    taskSupport: TaskSupport::REQUIRED,
    taskInputMode: TaskInputMode::PRE_TASK,
);
```

On the wire, input rounds run exactly like SEP-2322 multi-round-trip
input on an ordinary call — each round answers the `tools/call` with an
`input_required` result carrying the pending `inputRequests` and a
signed `requestState`, and **no task record exists yet**. Only the final
round, with every input resolved and the body run to completion, mints
the task and returns the `CreateTaskResult` — already `completed`, so
the client's first `tasks/get` poll finds the inlined result. An
abandoned exchange therefore leaves nothing behind on the server, which
suits the shared-hosting model well.

Differences from the in-task default to be aware of:

- **Protocol errors surface as JSON-RPC errors, not a `failed` task.** A
  `McpError` thrown during a pre-task round reaches the wire directly —
  no task exists that anyone could poll for the failure. (A plain tool
  *execution* error still mints a `completed` task carrying an `isError`
  result, matching the in-task path.)
- **`TaskContext::defer()` is unavailable.** While pre-task rounds run,
  no task exists, so `isTask()` is `false` and `defer()` raises `-32603`
  exactly as on a non-task call. A tool that hands work to a background
  worker should use the in-task default (a future revision may add
  mint-on-defer).
- **`OPTIONAL` tools degrade gracefully.** For a client that did not
  declare the Tasks extension, the identical input rounds run and the
  final round simply answers with an ordinary `CallToolResult` — the
  input mechanism is the same whether or not a task caps the exchange.
  (On legacy revisions the tool behaves like any plain eliciting tool.)

### In-task input

A task tool that needs user input mid-flight uses the same
`ElicitationContext` API as any other tool. Under a task, the elicitation
parks the task in `input_required`: the pending request surfaces through
`tasks/get` as `inputRequests` (keyed by input key), and the body resumes
when the client answers via `tasks/update`. Pass `inputKey:` to give the
round a stable name, so a retried request resolves to the same input:

```php
$server->tool(
    'archive-project',
    'Archives a project after confirmation (task-only)',
    function (Mcp\Server\Elicitation\ElicitationContext $elicit, string $project): string {
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
    taskSupport: Mcp\Server\TaskSupport::REQUIRED,
);
```

This is distinct from SEP-2322 multi-round-trip input on an ordinary
(non-task) call, which happens *before* any task exists — the in-task
mechanism (`inputRequests` on `tasks/get`, `inputResponses` on
`tasks/update`) handles input *during* a task. The tool code is the same
either way; which mechanism serves a task-augmented call is the tool's
`taskInputMode:` choice (in-task by default, or the pre-task composition
above).

### Cancellation and expiry

`tasks/cancel` is cooperative and eventually consistent: the SDK marks the
task and answers with an empty ack, and cancellation is idempotent. Because
task bodies run synchronously inside a request, a cancel can only take
effect at points where the SDK regains control (an input round, or an
application-driven task's next store update) — it cannot preempt PHP code
mid-execution. A task may therefore settle to a terminal status other than
`cancelled`. `notifications/cancelled` is never used for tasks.

Expired tasks (past their `ttlMs`) are cleaned up lazily by the store;
`ttlMs: null` means the server retains the task indefinitely.

## Client side

The full lifecycle: declare the extension, call the tool, branch on the
result type, poll, answer input, read the inlined result.

```php
<?php

require 'vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Types\CreateTaskResult;
use Mcp\Types\ElicitationCreateRequest;
use Mcp\Types\ElicitationCreateResult;
use Mcp\Types\ExtensionIds;

$client = new Client();

// A server tool may only elicit from clients that advertise the elicitation
// capability — registering an onElicit handler (before connect) is what
// advertises it. In-task input still arrives via tasks/get below; this
// handler services any direct (non-task) elicitation.
$client->onElicit(fn (ElicitationCreateRequest $r): ElicitationCreateResult =>
    new ElicitationCreateResult(action: 'accept', content: ['confirm' => true]));

try {
    $session = $client->connect('php', ['examples/tasks_server.php']);

    // Tasks require the modern (2026-07-28) era and the declared extension.
    $session->declareExtension(ExtensionIds::TASKS);

    $result = $session->callTool('archive-project', ['project' => 'atlas']);

    if (!$result instanceof CreateTaskResult) {
        // Synchronous CallToolResult — server chose not to create a task.
        echo $result->content[0]->text . "\n";
        exit(0);
    }

    $taskId = $result->task->taskId;

    while (true) {
        $get = $session->getTask($taskId);

        switch ($get->task->status) {
            case 'working':
                usleep(($get->task->pollIntervalMs ?? 250) * 1000);
                break;

            case 'input_required':
                // Answer every pending request; a real client would render
                // each request's params (message + requestedSchema) to the
                // user. Keys must match the inputRequests keys.
                $responses = [];
                foreach (array_keys($get->inputRequests ?? []) as $key) {
                    $responses[$key] = ['action' => 'accept', 'content' => ['confirm' => true]];
                }
                $session->updateTask($taskId, $responses);
                break;

            case 'completed':
                echo ($get->result['content'][0]['text'] ?? '(no content)') . "\n";
                exit(0);

            case 'failed':
                echo 'failed: ' . json_encode($get->error) . "\n";
                exit(1);

            default: // cancelled
                echo "cancelled\n";
                exit(0);
        }
    }
} finally {
    $client->close();
}
```

Notes:

- **`callTool()` is declared `CallToolResult|CreateTaskResult`** — always
  branch with `instanceof` when talking to a task-capable server. A
  pre-task-mode tool's input rounds never surface here: the client
  services `input_required` rounds transparently through the registered
  `onElicit()` / `onSampling()` handlers and returns only the final
  result — for a pre-task tool, a `CreateTaskResult` that is already
  `completed` on the first poll.
- **Declare elicitation even for in-task input.** The gotcha shown above:
  the server checks the client's *elicitation capability* before eliciting
  at all, and registering `onElicit()` is what advertises it — even though
  under a task the input arrives via `tasks/get` rather than a direct
  request.
- **Cancel with `$session->cancelTask($taskId)`** — the ack is empty;
  observe the final status via `tasks/get`.
- **Partial input is fine.** If you answer only some of the pending
  `inputRequests` keys, the task stays `input_required` until all keys
  arrive.

## Wire-level notes

Handled by the SDK, listed for the curious:

- Tasks are declared through the SEP-2133 `extensions` capability map — on
  the server in `server/discover` (and legacy `initialize`), on the client
  in every request's `_meta` capability envelope.
- On HTTP, the SEP-2243 `Mcp-Name` header carries the task id on
  `tasks/get` / `tasks/update` / `tasks/cancel` requests.
- `CreateTaskResult` is discriminated by `resultType: "task"`; task fields
  are `ttlMs` / `pollIntervalMs` (the pre-release `ttl` / `pollInterval`
  spellings never appear on the wire).
- A pre-task tool's input rounds are ordinary SEP-2322 `input_required`
  results (with `requestState`); the eventual `CreateTaskResult` never
  carries `requestState` or `inputRequests` — the MRTR phase's state does
  not leak into the task envelope.
- The optional `notifications/tasks` status push defined by the extension
  is not implemented by this SDK — poll `tasks/get` at `pollIntervalMs`.

Migrating from the pre-release v1 experimental Tasks API? See the
[Migration Guide](migration-v2.md#4-the-experimental-tasks-api-was-redesigned-b4-b8).
