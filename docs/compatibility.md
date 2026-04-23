# Hosting and Compatibility Guide

One of this SDK's design goals is to run cleanly in typical PHP hosting
environments — cPanel, shared Apache, PHP-FPM — in addition to CLI and
long-running process managers such as FrankenPHP or RoadRunner. This document
describes the principle, enumerates what "works" and "fails gracefully" mean
in practice, and lists the environment-specific considerations that have come
up during development.

## The principle

1. **Core MCP features must work under cPanel/Apache/PHP-FPM.** Tools,
   prompts, resources, initialization, capability negotiation, stdio transport
   (where the host permits), and the baseline HTTP transport are all "core."
2. **Features that are genuinely incompatible with shared hosting still ship.**
   Leaving them out would put the SDK out of spec alignment, which we don't
   accept. Examples include long-lived SSE streams on hosts with aggressive
   idle timeouts.
3. **Incompatible features must fail gracefully.** "Graceful" means: throw a
   typed exception the caller can catch, log a clear message, and leave the
   rest of the SDK functional. "Not graceful" means: fatal error, hanging
   request, or corrupted session state.

If you find a core feature that doesn't work on standard cPanel or a
non-core feature that crashes rather than failing gracefully, that's a bug
worth reporting.

## Environment notes

### PHP version

- **Required**: PHP 8.1+.
- **Recommended**: PHP 8.2+ for performance and improvement to fibers /
  readonly properties used in internal code paths.
- **Tested CI matrix**: currently `8.1` (the floor). Contributions adding
  newer versions to CI are welcome — see [CONTRIBUTING.md](../CONTRIBUTING.md).

### PHP extensions

| Extension   | Status                                                      |
| ----------- | ----------------------------------------------------------- |
| `ext-json`  | Required. MCP is JSON-RPC; this is non-negotiable.          |
| `ext-curl`  | Required. Used by the HTTP client transport.                |
| `ext-pcntl` | **Optional.** Improves process control for the stdio server runner. Shared hosts often disable it; the SDK degrades cleanly when it's missing. |
| `ext-mbstring` | Recommended. Some content types (e.g. multi-byte text) round-trip more safely with it enabled. |

If your shared host disables `ext-pcntl`, stdio servers still run but lose
certain signal-handling niceties. HTTP servers are unaffected.

### Stdio transport

- Works wherever PHP can be launched as a child process. That includes most
  cPanel environments when a cron job or external launcher invokes it, and
  the same for `webclient/` connecting to a local stdio server.
- Does **not** work from within a typical Apache request handler spawning a
  child PHP process mid-request, because shared hosts commonly forbid
  `proc_open` in that context. If that's your situation, use the HTTP
  transport instead.

### HTTP transport

- **Streaming HTTP (non-SSE)** works under every PHP SAPI we've seen.
- **SSE (Server-Sent Events)** works under most SAPIs but is sensitive to:
  - Apache `mod_deflate` compressing the stream — disable compression for the
    MCP endpoint if clients see truncated events.
  - `output_buffering` being on — the SDK flushes explicitly, but some hosts
    re-wrap output. Check `php.ini` or `.htaccess` overrides.
  - FPM's `request_terminate_timeout` being too low — long-lived streams can
    be killed. The SDK recovers (the client reconnects with `Last-Event-Id`),
    but throughput suffers on hosts with aggressive timeouts.
  - Aggressive CDNs and proxies that buffer responses — add
    `X-Accel-Buffering: no` or configure the proxy to stream.
- When SSE is not viable on a particular host, the SDK's fallback path is to
  reply with a regular HTTP response and let the client reconnect — the
  server code paths are the same; only the transport decorator changes.

### OAuth and `.htaccess`

OAuth under cPanel/Apache generally works, but two `.htaccess` rules are
usually required:

```apache
RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [e=HTTP_AUTHORIZATION:%1]

RewriteRule ^\.well-known/oauth-protected-resource(/.*)?$ /server_auth.php [L]
```

The first forwards the `Authorization` header to PHP (which shared hosts
strip by default). The second exposes the protected-resource metadata
endpoint. The full walk-through is in
[`examples/server_auth/README.md`](../examples/server_auth/README.md).

### Session persistence

- The web client and the HTTP server transport persist session state on disk
  via `Mcp\Server\Transport\Http\FileSessionStore`. Writes go to a directory
  you supply; this works anywhere PHP can write files.
- On some shared hosts the default temp directory is session-scoped or wiped
  often. If you see sessions "disappearing" between requests, configure the
  store to use a directory *you* control and make sure it's excluded from
  aggressive cleanup.
- `Mcp\Server\Transport\Http\InMemorySessionStore` exists for testing and
  for environments where long-lived processes hold state in memory
  (FrankenPHP, RoadRunner, Swoole). Don't use it under classic FPM — memory
  does not survive between requests.

### Embedding in frameworks

The HTTP runner uses the `HttpIoInterface` seam so it can be driven by
something other than native PHP `header()` / `echo`. See `AGENTS.md` for the
quick description, and the source under `src/Server/Transport/Http/` for the
details. If you're embedding in Symfony, Slim, Laravel, or a long-running
runtime, pass a custom adapter via
`McpServer::httpOptions(['io' => $adapter])` or construct
`HttpServerRunner` with it directly.

## What "graceful degradation" looks like

When a feature can't be supported in the current environment, the SDK aims
to:

- **Throw a clear, typed exception** at the right layer. For transports:
  `RuntimeException` with a message that says what's missing. For OAuth
  validation: `HttpAuthenticationException` so the caller can distinguish
  from a real transport fault. For protocol errors: `Mcp\Shared\McpError`.
- **Not crash the session.** A feature failing should surface a JSON-RPC
  error to the client (where protocol-appropriate) or propagate as an
  exception to the caller, never as a PHP fatal that tears down the request.
- **Log, not leak.** Where a feature is genuinely disabled (e.g., SSE not
  supported under the host), the SDK logs through the PSR-3 logger and
  continues with whatever fallback path is available.

If you see a failure that doesn't fit this model, it's a bug — please file
an issue with the transport, SAPI, PHP version, and `var_dump` of
`phpversion()` / `extension_loaded(...)` for the relevant extensions.

## Checklist for contributors touching transport or environment code

- [ ] Does this change assume a feature (`proc_open`, `pcntl_fork`, SSE,
      long-lived connections) that is commonly disabled on shared hosts?
- [ ] If yes, is there a documented fallback or a clear exception path?
- [ ] Is the failure mode covered by a unit test?
- [ ] Does the change preserve behaviour under the SAPI most users are on
      (Apache + FPM)?

## See also

- [README](../README.md)
- [CONTRIBUTING.md](../CONTRIBUTING.md)
- [`docs/testing.md`](testing.md)
- [Server Development Guide](server-dev.md)
- [OAuth Authentication Example](../examples/server_auth/README.md)
