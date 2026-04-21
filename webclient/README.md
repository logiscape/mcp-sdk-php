# MCP Web Client

A browser-based example MCP client. Thin UI layer over the `Mcp\Client\Client`
exposed by this SDK — all protocol, transport, OAuth, and session-resumption
logic is delegated to the SDK. Designed to drop into shared PHP hosting
(cPanel, Apache, PHP-FPM) with no build step.

Important: While this web client is designed to run in a cPanel/Apache web
environment, it is never intended to be used on a public facing web site. It's
for developers to use internally for experimenting and testing the SDK. The
method that developers use to protect the web client from unauthorized access
is beyond the scope of this SDK.

## Requirements

- PHP 8.1+ with `ext-curl` and `ext-json`
- Composer
- A writable `webclient/logs/` and `webclient/tokens/` directory

The bundled `composer.json` pins the SDK to `dev-main` so the webclient
always exercises the latest committed SDK code — appropriate for a tool
whose purpose is testing SDK behavior. Monolog is required for richer
logging; remove it with `composer remove monolog/monolog` if you prefer
the built-in fallback logger.

## Deployment

1. Upload the `webclient/` directory to your host (e.g. under `public_html/`).
2. From inside the uploaded `webclient/` directory, run:

   ```
   composer install
   ```

   That pulls `logiscape/mcp-sdk-php` (dev-main) plus Monolog into
   `webclient/vendor/`. Re-run it periodically to pick up SDK changes.
3. Ensure `webclient/tokens/` and `webclient/logs/` are writable by the PHP user.
4. Point your browser at `webclient/index.php`.

### Working from a repo checkout

When developing inside a checkout of the SDK repo, you can skip step 2
above — Bootstrap also looks for `vendor/autoload.php` one level up
(`dirname(webclient)/vendor/autoload.php`), so running `composer install`
at the project root is sufficient.

## Feature scope

While the core client included with the SDK aims for full MCP conformance, this
webclient is intentionally limited to MCP operations that round-trip cleanly
inside a single PHP request. That matches the request/response model of shared
hosting and keeps the code small enough to audit.

If you are a MCP server developer and your main goal is to test your server
with a fully functional client, the [MCP Inspector](https://github.com/modelcontextprotocol/inspector) is better suited for that purpose.

**Supported**

- stdio and HTTP/HTTPS transports (auto-detected by URL scheme)
- OAuth 2.1 via the SDK's `OAuthConfiguration` + `FileTokenStorage`, with
  browser-redirect consent, PKCE, and Dynamic Client Registration
- prompts: list, get
- tools: list, call
- resources: list, read
- completions: debounced auto-complete inside prompt-argument inputs
- ping + server-info panel (name, version, negotiated protocol version,
  advertised capabilities)
- elicitation *preview*: server-initiated `elicitation/create` requests are
  captured, declined, and rendered as a card next to the tool result so users
  can see what was asked

## Files

```
index.php           HTML shell — Bootstrap layout + mount points
css/app.css         Layout + component styling
js/
  main.js           DOM bootstrap + module wiring
  api.js            Fetch wrapper for the JSON API
  connection.js     Connect/disconnect/ping + form parsing
  capabilities.js   Prompts/tools/resources list + invoke flows
  forms.js          JSON-Schema → HTML form generator
  results.js        Content-block renderers + elicitation cards
  completions.js    Debounced auto-complete
  logs.js           Internal debug log panel
  oauth.js          Post-redirect OAuth resume driver
api/
  connect.php       POST connect, DELETE disconnect, POST resume_oauth
  execute.php       list_*, get_prompt, call_tool, read_resource, ping
  complete.php      Wraps ClientSession::complete()
  oauth_callback.php OAuth redirect landing page
  logs.php          Tail of the internal log file (optional)
lib/
  Bootstrap.php     Autoload + session + logger + JSON helpers
  SessionStore.php  $_SESSION persistence + OAuth config rebuilder
  SessionTokenStorage.php  Per-PHP-session wrapper around FileTokenStorage
  WebCallbackHandler.php   AuthorizationCallbackInterface → redirect exception
  ElicitationCapture.php   Synchronous onElicit handler (auto-decline)
  WebClientInlineLogger.php  PSR-3 fallback when Monolog isn't installed
test_server.php     Example stdio MCP server for quick experiments
```

## Development

- Start a dev server: `php -S 127.0.0.1:8080 -t webclient`
- Or separately for an MCP HTTP server: `php -S 127.0.0.1:8081 examples/simple_server_http.php`
- Then connect the webclient to `http://127.0.0.1:8081/`

## License

MIT — same as the SDK.
