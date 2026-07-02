# v1 → v2 PHP API Audit

This is the WS6 audit of `logiscape/mcp-sdk-php` API-surface changes between
the stable `1.x` line and v2 — the changes visible at the **Composer/PHP API
level**, as distinct from wire-level protocol changes (which version
negotiation handles automatically; see
[docs/v2-development-plan.md](v2-development-plan.md)). It is the source
material for WS10's user-facing migration guide (`docs/migration-v2.md`),
which will restate the *Breaking* and *Behavioral* sections with runnable
before/after snippets.

Every entry names the workstream that introduced it; the authoritative
narrative for each lives in the corresponding **Status** section of the
development plan.

## 1. Breaking changes (v1 code may need edits)

| # | Change | v1 behavior | v2 behavior | Migration | WS |
|---|--------|-------------|-------------|-----------|----|
| B1 | HTTP client errors are typed | JSON-RPC error responses on the HTTP transport threw `RuntimeException("Critical MCP error: …")` | They throw `Mcp\Shared\McpError` with the JSON-RPC `code`/`data` intact | Catch `McpError` (it does **not** extend `RuntimeException`); inspect `->getCode()`/error data instead of parsing message strings | WS2 |
| B2 | `McpError` from tool handlers is a protocol error | An `Mcp\Shared\McpError` thrown inside an `McpServer` tool handler was converted into an `isError: true` tool **result** | It propagates as a JSON-RPC **protocol error** (matching the long-standing `McpServerException` behavior) | Handlers that want a tool-execution error (`isError` result) should throw any other exception type | WS2 |
| B3 | `callTool()` return type widened | `ClientSession::callTool(): CallToolResult` | `CallToolResult\|CreateTaskResult` — a server augmenting the call as a SEP-2663 task returns the task handle | Code with strict return-type expectations must branch on `instanceof CreateTaskResult` (then poll via `getTask()`) | WS4 |
| B4 | Experimental v1 Tasks surface removed | `tasks/list` / `tasks/result` RPCs, `TaskCapability`, `TaskStatusNotification`, and their client conveniences | Removed without shims (pre-release surface; SEP-2663 redesign). `tasks/list` and `tasks/result` answer `-32601`; the v1 `tasks` capability slot is gone (Tasks is declared via the SEP-2133 `extensions` map) | Use `tools/call` → `CreateTaskResult` + `getTask()`/`updateTask()`/`cancelTask()`; results are inlined in the completed `tasks/get` response | WS4 |
| B5 | Pre-registered OAuth credentials require issuer binding | `ClientCredentials` carried no issuer; credentials were presented to whatever authorization server discovery produced | The `2026-07-28` Authorization Server Binding rule is the default: unbound pre-registered credentials are rejected before any token request (`REASON_UNBOUND_CLIENT_CREDENTIALS`) | Set `ClientCredentials::$issuer` to the AS that issued the credentials, or opt into the published-spec legacy behavior with `OAuthConfiguration::$allowUnboundClientCredentials = true` | WS3 |
| B6 | Modern HTTP `tools/list` filters invalid `x-mcp-header` tools | Results were returned as-served | On modern (`2026-07-28`) HTTP sessions, tools whose `x-mcp-header` annotations violate SEP-2243 are **excluded** from `listTools()`, and `callTool()` on such a tool throws `InvalidArgumentException` before any wire traffic (spec MUST). Legacy and stdio results are unfiltered | Fix the tool's annotations server-side; clients can inspect the exclusion reason in the thrown message | WS3 |
| B7 | `ElicitationContext` in legacy HTTP `prompts/get` fails loudly | The context parameter was injected but silently non-functional | A legacy-era HTTP `prompts/get` whose callback declares an `ElicitationContext` fails with `BadMethodCallException` (`-32603`) — prompt-side input gathering is modern-only by design | Gather prompt input via the modern MRTR path, or drop the context parameter from legacy-serving prompt callbacks | WS3 |
| B8 | Stubbed `task` parameter removed from `ElicitationContext` | `form()`/`url()` accepted a stubbed pre-release `task` parameter | Removed (SEP-2663 settled on `inputRequests`/`inputResponses` via `tasks/get`/`tasks/update` instead) | Delete the argument; in-task input needs no per-call opt-in | WS4 |

**Dispositions decided during this audit:**

- `HttpServerTransport::start()` **idempotency** (WS2 post-commit): v1 threw
  `RuntimeException('Transport already started')` on a second call; v2
  silently returns (required by the per-request ephemeral sessions of the
  stateless lifecycle). Disposition: the old throw is **not** treated as
  supported v1 surface — no v1 code plausibly relied on catching it — so
  this is recorded as a behavioral change (M5), not a break.
- The v1 experimental Tasks surface and the stubbed elicitation `task`
  parameter are removed **without deprecation shims** per the project's
  pre-release policy (recorded in the roadmap); they appear as B4/B8
  because v1.x did ship the experimental surface.

## 2. Behavioral changes (same API, different observable behavior)

| # | Change | Details | WS |
|---|--------|---------|----|
| M1 | `Client::connect()` probes modern first | Default `protocolMode: 'auto'` sends a `server/discover` probe before falling back to the legacy `initialize`. Operators of fragile legacy servers that mishandle unknown pre-initialize requests can pin `protocolMode: 'legacy'` | WS2 |
| M2 | `readTimeout` fires against silent peers | A configured client `readTimeout` now also fires when the peer sends nothing at all (previously it only fired between messages). Very slow legacy servers may need a larger explicit `readTimeout` | WS2 |
| M3 | SEP-2106 string returns with an `outputSchema` | An `McpServer` tool with a declared `outputSchema` returning a string now produces JSON-encoded `TextContent` (`"hello"` with quotes) plus `structuredContent`; v1 emitted the raw string and no `structuredContent`. Wire-visible to legacy clients of such tools | WS1 |
| M4 | `HttpServerSession::toArray()` deep-normalizes `clientParams` | Fixes in-memory session stores silently dropping declared client capabilities (e.g. `elicitation: {}`) between requests | WS3 |
| M5 | `HttpServerTransport::start()` is idempotent | Second and later calls return silently instead of throwing (see disposition above) | WS2 |
| M6 | Typed exceptions replace message sniffing | `Types/ClientRequest` throws `Mcp\Shared\UnknownMethodException` (subclass of `InvalidArgumentException`); client read timeouts throw `Mcp\Client\Transport\ReadTimeoutException` (subclass of `RuntimeException`). Messages are unchanged, so string-matching v1 code keeps working — but catch the typed forms going forward | WS3 |
| M7 | Response adaptation clones before mutating | `ServerSession::adaptResponseForClient()` adapts a shallow copy — a handler-cached `Result` reused across requests (and eras) keeps its own `resultType`/`ttlMs`/`cacheScope` | WS6 |
| M8 | SEP-2596/2577 runtime deprecation warnings | Exercising Roots, Sampling, Logging (on a `2026-07-28` session), the deprecated `includeContext` values (from `2025-11-25`), or Dynamic Client Registration emits one PSR-3 `warning` per feature per session. Wire behavior is unchanged | WS6 |
| M9 | OAuth hardening side effects | SEP-2468 `iss` validation, SEP-2352 credential/PRM re-checks on 401/403, SEP-837 `application_type` on registration, SEP-2207 `offline_access` gating — standards-driven changes inside existing flows; conformant servers are unaffected | WS3 |

## 3. Additive surface (new in v2, no migration required)

- **Negotiation:** `ClientSession::negotiate(mode, probeTimeout, preferredVersion)`,
  `Client::connect()` options `protocolMode` (`'auto' | 'modern' | 'legacy'`),
  `probeTimeout`, and the `protocolVersion` HTTP option;
  `ClientSession::discover()`.
- **Client handlers:** `onSampling()` (also services SEP-2322 MRTR sampling
  entries), `onListRoots()` servicing MRTR roots entries.
- **Tasks (SEP-2663):** `McpServer::enableTasks()`, `tool(..., taskSupport:)`,
  client `getTask()`/`updateTask()`/`cancelTask()`, `CreateTaskResult`.
- **Apps (SEP-1865):** `McpServer::ui(...)`, `ExtensionIds::UI`,
  `Server::declareExtension()`.
- **Subscriptions (SEP-2575):** server-side `subscriptions/listen` (HTTP
  streaming + stdio in-session), `SubscriptionBusInterface` /
  `FileSubscriptionBus`, `McpServer::subscriptionBus()` and publish helpers,
  `SubscriptionsListenResult` (graceful end-of-subscription).
- **MRTR (SEP-2322):** `InputContext` (batch input gathering),
  exchange-backed `ElicitationContext`/`SamplingContext` modern paths,
  `RequestStateCodec`.
- **Transport/headers:** `Mcp\Shared\McpHeaders` (SEP-2243),
  `JsonRpcMessage::$httpHeaderHints`, `x-mcp-header` mirroring.
- **Lifecycle:** `Mcp\Shared\FeatureLifecycle`, session
  `warnDeprecatedFeature()`.
- **Auth:** `client_credentials` grant (private_key_jwt ES256/RS256, basic),
  SEP-990 cross-app access, `ClientCredentials::$issuer`,
  `OAuthConfiguration::$allowUnboundClientCredentials`,
  `AuthorizationCallbackResult` (string-returning handlers keep working).

## 4. Wire-level changes handled automatically

Version negotiation and per-request era detection make the `2026-07-28`
protocol changes transparent to PHP code: the `_meta` envelope, removed
handshake, session-header absence, SEP-2549 stamping/stripping, SEP-2164
error-code selection, and the SEP-2243 request-metadata headers are applied
per negotiated revision. A v1-style server or client built on the v2 API
keeps interoperating with peers on every revision back to `2024-11-05`.
These need no migration action and are listed in the development plan's
workstream statuses rather than here.
