# Shared-Hosting Validation Report (v2 / 2026-07-28)

This is the WS8 validation record promised by
[v2-development-plan.md](v2-development-plan.md): v2 proven on a real
cPanel/Apache/PHP-FPM shared-hosting account, not just asserted. The
compatibility rules themselves live in [compatibility.md](compatibility.md);
this document records what was actually run, where, and with what result.

## Validation environment

| | |
| --- | --- |
| Date | 2026-07-05 |
| Hosting | Live cPanel account on a public domain (standard shared hosting) |
| Web server / handler | Apache + PHP-FPM |
| PHP | 8.3.31 |
| SDK | `logiscape/mcp-sdk-php` v2.0.0-beta3, installed via Composer on the host |
| I/O adapter | `NativePhpIo` (the default — no custom adapter) |
| Remote clients | MCP Inspector (from a separate PC), SDK example clients (`examples/client_negotiation.php`, `examples/client_http.php`) |
| On-host client | The bundled `webclient/` reference implementation |

## What was run

1. The example servers from `examples/` were uploaded to the account and
   each was exercised through the bundled webclient (on-host) and the MCP
   Inspector (remote).
2. `examples/client_negotiation.php` was run from a remote PC against the
   hosted `examples/stateless_server.php`; **both the modern
   (`2026-07-28`) and legacy negotiation options returned the correct tool
   call result**, confirming dual-era detection on a fresh-process-per-request
   host.
3. The MCP Apps example (`examples/apps_server/`) was hosted and verified
   through the MCP Inspector.
4. The OAuth example (`examples/server_auth/`) was set up per its README and
   verified three ways: the webclient, the example's `test-client.html`
   (every modern- and legacy-era button produced the expected result), and
   the MCP Inspector remotely.

## Results

Verdicts: **works** (stock account, no configuration), **works-with-config**
(works once the documented configuration is applied), **degrades-gracefully**
(feature is unavailable but fails per the
[compatibility contract](compatibility.md#what-graceful-degradation-looks-like)).

| Feature | Verdict | Evidence |
| --- | --- | --- |
| Tools, prompts, resources (core) | **works** | Every example server driven via webclient + Inspector; no configuration beyond uploading the files |
| `server/discover` / stateless modern path | **works** | `client_negotiation.php` modern option end-to-end; Inspector against `stateless_server.php` |
| Legacy `initialize` + file-backed sessions | **works** | `client_negotiation.php` legacy option end-to-end (fresh FPM process per request; `FileSessionStore`) |
| Dual-era detection in one script | **works** | Same hosted `stateless_server.php` served both eras concurrently |
| Request/response over HTTP (JSON responses) | **works** | Inspector and webclient tool-call flows against the hosted servers returned valid responses. Note this does not exercise the request-scoped SSE upgrade: with `enable_sse` off (the default) and no handler-emitted notifications, responses are plain JSON — which is compliant. The SSE upgrade path is covered by automated tests (`HttpModernStreamingTest::testHandlerNotificationsUpgradeSuccessResponseToSse`); the live SSE proof on this host is the `subscriptions/listen` row below |
| OAuth (server side) | **works-with-config** | Full `examples/server_auth/README.md` walk-through; **both documented `.htaccess` rules were required** (Authorization forwarding + `.well-known` rewrite) — matching [compatibility.md](compatibility.md#oauth-and-htaccess) exactly |
| MCP Apps resource emission | **works** | `apps_server` verified via Inspector on the live host |
| Tasks (file-based store) | **works** | Full lifecycle driven remotely via `tasks_client.php`: a synchronous task (create → `completed`) and an in-task-input task (create → `input_required` → answered via `tasks/update` → `completed`), every poll served by a fresh FPM process against the file-based store |
| `subscriptions/listen` | **works-with-config** | Requires `httpOptions(['enable_sse' => true])` (default `false`) plus a subscription bus (`FileSubscriptionBus` across FPM processes). Verified end-to-end: acknowledged frame → cross-process `notifications/resources/updated` fan-out → graceful `SubscriptionsListenResult` close at the `listen_max_ms` budget. With SSE off, the live host answered the spec's refusal, `-32601` — the degradation claim observed in production |
| Request-metadata header survival (`Mcp-Method`, `Mcp-Name`, `Mcp-Param-*`, `MCP-Protocol-Version`) | **works** | Probe echo confirmed all four header families reach PHP intact through `.htaccess` (`HTTP_MCP_METHOD`, `HTTP_MCP_NAME`, `HTTP_MCP_PARAM_A`, `HTTP_MCP_PROTOCOL_VERSION`); `Authorization` also arrived at the probe location, where the OAuth forwarding rule was installed |
| SEP-2243 header-mismatch enforcement (`-32020`) | **works** | Observed live (incidental): modern-body requests missing the `MCP-Protocol-Version` or `Mcp-Method` header were rejected with `-32020 "Header mismatch: …"` — the renumbered error path enforcing header/body consistency in production |
| Raw modern `tools/call` (curl, MRTR result shape) | **works** | A hand-built envelope + SEP-2243 headers returned `{"resultType":"complete", …}` from the live host |

No core feature required anything beyond a stock cPanel account: the only
configuration applied anywhere was the two `.htaccess` rules for OAuth,
which are already the documented requirement for that non-core feature.

## Graceful-degradation claims and their tests

Per the WS8 criteria, every degradation claim is backed by an automated test
that simulates the failure mode (all use `BufferedIo` or the in-memory
harness):

| Claim | Test |
| --- | --- |
| A `subscriptions/listen` stream is closed by the server with a graceful `SubscriptionsListenResult` when its lifetime budget (`listen_max_ms`) expires — the shared-hosting answer to FPM timeouts | `tests/Server/HttpModernStreamingTest.php` (lifetime-budget tests) |
| A client disconnect ends the listen loop promptly instead of burning the request budget | `HttpModernStreamingTest::testListenStopsPromptlyWhenConnectionAborts` |
| A host without SSE support answers `subscriptions/listen` with `-32601` (HTTP 404) rather than acknowledging undeliverable subscriptions | `HttpModernStreamingTest::testListenWithoutSseAnswers404MethodNotFound` |
| A server without a configured subscription bus does the same — no false acknowledgement | `HttpModernStreamingTest::testListenWithoutBusAnswers404MethodNotFound` |
| SSE-incapable clients get plain JSON responses (notifications dropped, never a broken stream) | `HttpModernStreamingTest::testJsonOnlyClientGetsPlainJsonAndNotificationsDropped`, `::testSseDisabledServerKeepsPlainJson` |
| `connection_aborted` is honored through the I/O seam | `tests/Server/Transport/Http/BufferedIoTest.php` |

`ext-pcntl` absence needs no degradation test on this profile: the HTTP
server path never uses pcntl. The only use is the *client-side* SSE
background process (`src/Client/Transport/SseConnection.php`), which is
feature-detected via `function_exists('pcntl_fork')` and falls back to the
foreground path.

## Probe checks and results

These checks close the rows the example sweep could not cover. They were
run on 2026-07-05 from a remote PC against the live host; the procedures
are kept so they can be re-run.

The first two checks used a throwaway single-file probe script (written
for the occasion, not kept in the repository — recreate it as follows,
upload it next to the example servers so `vendor/autoload.php` is
reachable, and delete it from the host afterwards). The probe has two
modes:

- **Environment/header echo** (`?probe=env` query string): before any SDK
  code runs, respond with a plain JSON dump of `PHP_VERSION`, `PHP_SAPI`,
  the loaded-state of `json`/`curl`/`pcntl`/`mbstring`, the
  `output_buffering` / `zlib.output_compression` / `max_execution_time`
  ini values, and every request header that reached PHP (the
  `$_SERVER` keys starting `HTTP_` or `REDIRECT_HTTP_`). This proves
  header survival independently of MCP processing.
- **MCP server** (default): a minimal `McpServer` with
  `httpOptions(['enable_sse' => true])` (required for listen — the
  default is `false`), a `FileSubscriptionBus` pointed at a directory the
  account owns (not the shared temp dir), one resource `probe://status`,
  and one tool `trigger-update` whose callback calls
  `$server->publishResourceUpdated('probe://status')` — so a second
  request, served by a different FPM process, publishes an event the
  listen stream must deliver.

The commands below refer to the uploaded copy as `probe.php`.

Every modern-era request body below uses this `_meta` envelope (abbreviated
as `ENVELOPE` in the commands):

```json
{
  "io.modelcontextprotocol/protocolVersion": "2026-07-28",
  "io.modelcontextprotocol/clientInfo": { "name": "ws8-probe-curl", "version": "1.0" },
  "io.modelcontextprotocol/clientCapabilities": {}
}
```

### 1. Header survival through `.htaccess`

```bash
curl -s "https://HOST/PATH/probe.php?probe=env" \
  -H "MCP-Protocol-Version: 2026-07-28" \
  -H "Mcp-Method: tools/call" \
  -H "Mcp-Name: trigger-update" \
  -H "Mcp-Param-A: 1" \
  -H "Authorization: Bearer probe-token"
```

Expected: the JSON `receivedHeaders` map contains
`HTTP_MCP_PROTOCOL_VERSION`, `HTTP_MCP_METHOD`, `HTTP_MCP_NAME`, and
`HTTP_MCP_PARAM_A`. `HTTP_AUTHORIZATION` (or
`REDIRECT_HTTP_AUTHORIZATION`) appears only where the Authorization
`.htaccess` rule is active. The same response reports the host's
`output_buffering` / `zlib.output_compression` / `max_execution_time`
values for the environment profile.

**Result (2026-07-05): pass.** All four header families arrived intact and
unmodified, `Authorization` included (the forwarding rule was installed at
the probe location). Environment reported by the probe: SAPI `fpm-fcgi`
under Apache, PHP 8.3.31, `output_buffering` and `zlib.output_compression`
both off, `max_execution_time` 300, and `ext-json`/`ext-curl`/`ext-pcntl`/
`ext-mbstring` all loaded on this host.

### 2. `subscriptions/listen` end-to-end (cross-process fan-out)

Terminal A — open the stream (`-N` disables curl's buffering):

```bash
curl -N https://HOST/PATH/probe.php \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -H "MCP-Protocol-Version: 2026-07-28" \
  -H "Mcp-Method: subscriptions/listen" \
  -d '{"jsonrpc":"2.0","id":1,"method":"subscriptions/listen",
       "params":{"notifications":{"resourceSubscriptions":["probe://status"]},
                 "_meta":ENVELOPE}}'
```

Terminal B — within 30 seconds (the default `listen_max_ms` stream
lifetime), publish an event from a different FPM process:

```bash
curl -s https://HOST/PATH/probe.php \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -H "MCP-Protocol-Version: 2026-07-28" \
  -H "Mcp-Method: tools/call" \
  -H "Mcp-Name: trigger-update" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call",
       "params":{"name":"trigger-update","arguments":{},"_meta":ENVELOPE}}'
```

Expected in terminal A, in order:

1. `notifications/subscriptions/acknowledged` as the first frame, echoing
   the honored filter with a subscription id;
2. `notifications/resources/updated` for `probe://status` (tagged with the
   subscription id) shortly after terminal B's call — this is the
   cross-process `FileSubscriptionBus` fan-out working under FPM;
3. at ~30 s, a graceful `SubscriptionsListenResult` response and a clean
   stream close (not a mid-stream cut).

**Result (2026-07-05): pass.** The stream delivered the exact expected
sequence on the live host:

1. `notifications/subscriptions/acknowledged` as the first frame, echoing
   `{"resourceSubscriptions":["probe://status"]}` with subscription id 1;
2. SSE keepalive comments flowing continuously (no proxy or FPM buffering —
   frames arrived in real time);
3. `notifications/resources/updated` for `probe://status`, tagged with the
   subscription id, delivered promptly after terminal B's `tools/call` ran
   in a **different FPM process** — the `FileSubscriptionBus` cross-process
   fan-out working under shared hosting;
4. at the ~30 s `listen_max_ms` budget, the graceful close:
   `{"id":1,"result":{"resultType":"complete",…}}` — a clean
   `SubscriptionsListenResult`, not a mid-stream cut.

Two incidental findings from earlier iterations of this check, both correct
behavior observed live:

- With the `MCP-Protocol-Version` or `Mcp-Method` header omitted from a
  modern-body request, the host answered
  `-32020 "Header mismatch: missing required … header"` — SEP-2243
  header/body consistency enforced in production.
- With SSE not enabled (`enable_sse` defaults to `false`), the listen
  request was refused with `-32601 "Server does not support
  subscriptions/listen on this transport"` while the concurrent
  `tools/call` succeeded — the exact degradation contract the automated
  tests pin down.

### 3. Tasks full lifecycle

From the remote PC, against the already-hosted `tasks_server.php`:

```bash
php examples/tasks_client.php https://HOST/PATH/tasks_server.php
```

Expected: the client walks create → `working` → `input_required` (answers
the elicitation via `tasks/update`) → `completed`, with the result inlined
in the final `tasks/get` — each poll served by a fresh FPM process against
the file-based task store.

**Result (2026-07-05): pass.** Both tool flows completed against the live
host: `generate-report` went create → `completed` with the result inlined
in `tasks/get`, and `archive-project` went create → `input_required`
(surfacing the `elicitation/create` input request) → answered via
`tasks/update` → `completed`. (Client-side note: on a Windows PHP without a
configured CA bundle, pass one explicitly, e.g.
`php -d curl.cainfo=path\to\ca-bundle.crt examples/tasks_client.php …`.)

## See also

- [compatibility.md](compatibility.md) — the canonical compatibility rules
  this report validates
- [v2-development-plan.md](v2-development-plan.md) — WS8 scope and
  completion criteria
- [`examples/server_auth/README.md`](../examples/server_auth/README.md) —
  the OAuth walk-through used in this validation
