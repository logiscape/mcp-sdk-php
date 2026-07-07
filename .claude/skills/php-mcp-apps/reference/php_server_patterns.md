# PHP Server Patterns for MCP Apps (logiscape/mcp-sdk-php)

Server-side reference for building Apps with `Mcp\Server\McpServer`. The view-side
counterpart is `view_patterns.md`; exact wire shapes are in `apps_protocol.md`.

## Table of contents

- [The ui() API](#the-ui-api)
- [What ui() emits](#what-ui-emits)
- [Recipe: dashboard / card](#recipe-dashboard--card)
- [Recipe: app-only refresh & pagination tools](#recipe-app-only-refresh--pagination-tools)
- [Recipe: form / picker that writes back](#recipe-form--picker-that-writes-back)
- [Recipe: carousel from a database](#recipe-carousel-from-a-database)
- [Recipe: several tools sharing one view](#recipe-several-tools-sharing-one-view)
- [Recipe: external assets and APIs (CSP)](#recipe-external-assets-and-apis-csp)
- [Recipe: browser permissions](#recipe-browser-permissions)
- [structuredContent and outputSchema discipline](#structuredcontent-and-outputschema-discipline)
- [The template is static — keep dynamic data out of the HTML](#the-template-is-static--keep-dynamic-data-out-of-the-html)
- [Auth interplay](#auth-interplay)
- [Deployment notes (shared hosting)](#deployment-notes-shared-hosting)

## The ui() API

```php
public function ui(
    string          $tool,                  // name of an ALREADY-registered tool
    string          $uri,                   // must begin with "ui://"
    string          $name,                  // human-readable resource name
    string|callable $html,                  // document, or fn(): string read lazily
    string          $description = '',
    ?array          $visibility = null,     // subset of ['model','app']; null = both
    ?array          $csp = null,            // ['connectDomains'=>[], 'resourceDomains'=>[],
                                            //  'frameDomains'=>[], 'baseUriDomains'=>[]]
    ?array          $permissions = null,    // subset of ['camera','microphone',
                                            //            'geolocation','clipboardWrite']
    ?string         $domain = null,         // dedicated sandbox origin
    ?bool           $prefersBorder = null,
): self
```

Validation happens at registration (fail fast): non-`ui://` URI, unknown tool, empty
or invalid `visibility`, unknown permission, unknown/malformed `csp` key all throw
`InvalidArgumentException`. `ui()` must come **after** the `tool()` it links.

`html` as a callable is invoked once per `resources/read` — use it so the file is
read only when a host actually fetches the template:

```php
html: fn() => file_get_contents(__DIR__ . '/views/board.html'),
```

## What ui() emits

One call produces all three wire artifacts (never hand-write these):

1. Resource in `resources/list` + `resources/read` with MIME
   `text/html;profile=mcp-app` (`McpServer::UI_MIME_TYPE`).
2. Tool `_meta.ui.resourceUri` (+ `_meta.ui.visibility` if given), plus the
   deprecated flat `_meta["ui/resourceUri"]` dual-written for host back-compat.
3. Capability `extensions["io.modelcontextprotocol/ui"] = {mimeTypes: [...]}` —
   idempotent across multiple `ui()` calls, advertised on both `initialize`
   (legacy hosts) and `server/discover` (2026-07-28 hosts).

Hints (`csp`/`permissions`/`domain`/`prefersBorder`) ride as `_meta.ui` on the read
content and are mirrored on the listing.

## Recipe: dashboard / card

The canonical shape — one read-only tool, one view:

```php
$server
    ->tool(
        'get_metrics',
        'Get the current sales metrics dashboard',
        function (string $period = '7d'): CallToolResult {
            $m = Metrics::for($period);
            return new CallToolResult(
                content: [new TextContent(text:
                    "Sales last {$period}: {$m['orders']} orders, \${$m['revenue']} revenue "
                    . "({$m['deltaPct']}% vs previous period)."
                )],
                structuredContent: [
                    'period'   => $period,
                    'revenue'  => $m['revenue'],
                    'orders'   => $m['orders'],
                    'deltaPct' => $m['deltaPct'],
                    'series'   => $m['dailySeries'],   // small array for the chart
                ],
            );
        },
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'period'   => ['type' => 'string'],
                'revenue'  => ['type' => 'number'],
                'orders'   => ['type' => 'integer'],
                'deltaPct' => ['type' => 'number'],
                'series'   => ['type' => 'array', 'items' => ['type' => 'number']],
            ],
            'required' => ['period', 'revenue', 'orders'],
        ],
    )
    ->ui(
        tool: 'get_metrics',
        uri: 'ui://sales/dashboard-v1',
        name: 'Sales Dashboard',
        html: fn() => file_get_contents(__DIR__ . '/views/dashboard.html'),
        description: 'Interactive sales metrics with trend chart',
        prefersBorder: true,
    );
```

The `content` text carries the *conclusion* (numbers + comparison), so a text-only
host or the model alone still gets full value. That is graceful degradation and it is
non-negotiable: never return a "see the dashboard above" string.

## Recipe: app-only refresh & pagination tools

UI plumbing the model should never call — mark it `visibility: ['app']`. The view
calls it over the bridge (`tools/call`); the model never sees it in its tool list:

```php
$server
    ->tool('board_page', 'Fetch one page of board cards (UI internal)',
        function (string $board, int $page, int $pageSize = 20): CallToolResult {
            $rows = Board::page($board, $page, $pageSize);
            return new CallToolResult(
                content: [new TextContent(text: 'Page ' . $page . ' of board ' . $board)],
                structuredContent: ['page' => $page, 'cards' => $rows],
            );
        })
    ->ui(
        tool: 'board_page',
        uri: 'ui://kanban/board-v1',      // same template as the main tool — see below
        name: 'Kanban Board',
        html: fn() => file_get_contents(__DIR__ . '/views/board.html'),
        visibility: ['app'],
    );
```

Two caveats. Visibility is host-enforced advisory metadata — your server still lists
and executes the tool, so validate inputs and authorize as if anyone could call it.
And keep app-only tools cheap and idempotent: the view may call them repeatedly.

## Recipe: form / picker that writes back

Split reads from writes. The view collects input and calls the write tool; the write
tool is a *model-visible* tool with accurate annotations so directory reviews and
host consent flows work:

```php
$server
    ->tool('list_slots', 'List available booking slots for a date',
        fn(string $date): CallToolResult => new CallToolResult(
            content: [new TextContent(text: Slots::summary($date))],
            structuredContent: ['date' => $date, 'slots' => Slots::for($date)],
        ),
        annotations: ['readOnlyHint' => true, 'openWorldHint' => false])
    ->tool('book_slot', 'Book a specific slot by id',
        function (string $slotId, string $name, string $email): CallToolResult {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException("Invalid email: {$email}");
            }
            $ref = Bookings::create($slotId, $name, $email);
            return new CallToolResult(
                content: [new TextContent(text: "Booked slot {$slotId} — confirmation {$ref}.")],
                structuredContent: ['confirmed' => true, 'reference' => $ref, 'slotId' => $slotId],
            );
        },
        // The annotations directory reviews check — set them on every tool
        // via tool()'s annotations: parameter.
        annotations: ['readOnlyHint' => false, 'destructiveHint' => false,
                      'idempotentHint' => true, 'openWorldHint' => false])
    ->ui(
        tool: 'list_slots',
        uri: 'ui://booking/picker-v1',
        name: 'Slot Picker',
        html: fn() => file_get_contents(__DIR__ . '/views/picker.html'),
    );
```

After the view books a slot it must tell the model (via `ui/message` or
`ui/update-model-context` — see `view_patterns.md`), otherwise the conversation
continues as if nothing was booked. Keep money out of the iframe: Claude does not
support purchases through interactive connectors (policy), and ChatGPT routes
payments through its own checkout flow (`requestCheckout` payment sheet — private
beta, select marketplaces; external checkout on your own site is the
recommended default).

## Recipe: carousel from a database

Listings with imagery (3–8 cards per host design guidance). Images are the CSP
trap: either serve them from a domain you declare, or inline small ones as data: URIs.

```php
$server
    ->tool('search_listings', 'Search property listings',
        function (string $city, int $maxPrice = 0) use ($pdo): CallToolResult {
            $stmt = $pdo->prepare(
                'SELECT id, title, price, img_url FROM listings
                 WHERE city = :city AND (:max = 0 OR price <= :max)
                 ORDER BY price LIMIT 8');
            $stmt->execute(['city' => $city, 'max' => $maxPrice]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return new CallToolResult(
                content: [new TextContent(text:
                    count($rows) . " listings in {$city}" .
                    ($rows ? ', from $' . min(array_column($rows, 'price')) : ''))],
                structuredContent: ['city' => $city, 'listings' => $rows],
            );
        })
    ->ui(
        tool: 'search_listings',
        uri: 'ui://realty/carousel-v1',
        name: 'Listings Carousel',
        html: fn() => file_get_contents(__DIR__ . '/views/carousel.html'),
        csp: ['resourceDomains' => ['https://images.example.com']],  // the img_url host
    );
```

Cap the result set server-side (`LIMIT 8`) — the view never needs more than a
screenful, and on hosts that surface `structuredContent` to the model (ChatGPT
does) an unbounded dump costs tokens and drowns the answer.

## Recipe: several tools sharing one view

Design views around *views*, not one-per-tool: one HTML document that branches on the
data shape it receives (or on a `kind` field you put in `structuredContent`). With
this SDK, give each tool its **own URI** backed by the **same HTML file**:

```php
$view = fn() => file_get_contents(__DIR__ . '/views/weather-panel.html');

$server
    ->ui(tool: 'get_weather',  uri: 'ui://weather/panel-current-v1',  name: 'Weather', html: $view)
    ->ui(tool: 'get_forecast', uri: 'ui://weather/panel-forecast-v1', name: 'Weather', html: $view);
```

Do not reuse the exact same URI across `ui()` calls: each call appends a
`resources/list` entry, so a repeated URI produces a duplicate listing, and the
read handler is keyed by URI — the **last** registration silently replaces the
earlier one's HTML and hints.
Distinct URIs sharing one file cost only a second host cache entry and keep the
listing clean. The maintenance win is identical: one view file to evolve.

## Recipe: external assets and APIs (CSP)

Default sandbox CSP blocks all external traffic. Declare exactly what the view needs
and nothing more — every domain is attack surface a directory review will question:

```php
->ui(
    tool: 'track_flight',
    uri: 'ui://flights/map-v1',
    name: 'Flight Map',
    html: fn() => file_get_contents(__DIR__ . '/views/map.html'),
    csp: [
        'connectDomains'  => ['https://api.flightdata.example'],  // fetch/XHR/WS
        'resourceDomains' => ['https://tiles.example.com'],       // img/script/style/font
    ],
)
```

Prefer no CSP at all: proxy external API calls through your own tools (the view calls
`tools/call`, your PHP calls the API). That keeps API keys server-side, works on
every host, and needs zero declared domains. Note `frameDomains` is restricted on
Claude pending security review — don't build on nested iframes.

## Recipe: browser permissions

```php
->ui(
    tool: 'scan_qr',
    uri: 'ui://scanner/camera-v1',
    name: 'QR Scanner',
    html: fn() => file_get_contents(__DIR__ . '/views/scanner.html'),
    permissions: ['camera'],
)
```

Valid values: `camera`, `microphone`, `geolocation`, `clipboardWrite`. Claude does
not grant camera/microphone/geolocation on mobile — the view must degrade (offer file
upload instead of camera, manual entry instead of geolocation).

## structuredContent and outputSchema discipline

- Shape it like a versioned API response; the view is a consumer you can't hot-patch
  (hosts cache templates). Additive changes only, or bump the `ui://` URI version.
- Declare `outputSchema` on UI-linked tools. Hosts and reviews use it; the SDK
  validates nothing here, but the model reads the schema to understand the result.
- Model visibility is host-dependent: the Apps spec keeps `structuredContent` out of
  model context, ChatGPT feeds it to the model. So (a) anything the model must know
  goes in `content` — never rely on it reading `structuredContent`; (b) budget as if
  the model sees it: screen-sized payloads (≤ a few KB). Overflow goes in result
  `_meta` (view-only) — but some hosts strip custom result `_meta`, so the view must
  stay functional without it; the reliable pattern for bulk data is an
  `['app']`-visibility pagination tool.

## The template is static — keep dynamic data out of the HTML

PHP developers reflexively render data into HTML server-side. **Do not do that
here.** Hosts prefetch and cache the template by URI — possibly before the tool ever
runs, possibly once for thousands of calls. A template with baked-in query results
shows stale data and defeats caching. The `html:` callback exists for lazy *loading*
(read the file when fetched), not per-request templating. All dynamic data flows
through `structuredContent` on the tool result; the HTML is an app shell.

Legitimate server-side generation: assembling the shell from partials, inlining a
CSS file, embedding a build artifact — anything that is still request-independent.

## Auth interplay

An OAuth-protected server (the SDK ships OAuth 2.1 support in `Server/Auth/`; see
`examples/server_auth/` and the SDK docs for setup) protects
`resources/read` too — the host fetches the template through the same authorized
session, so nothing special is needed. View-initiated `tools/call` runs through the
host's session and consent flow; your handler sees it exactly like a model call, so
enforce authorization in the handler, never via `visibility`.

## Deployment notes (shared hosting)

Standard SDK deployment applies unchanged: upload the project including `vendor/`
inside `public_html/`, add an `.htaccess` denying direct access to `vendor/` and any
session directory (`RewriteRule ^vendor/ - [F,L]` etc.), select PHP 8.1+ in cPanel.
Apps-specific points:

- The template is served by plain `resources/read` over standard HTTP POST — no SSE,
  sessions, or long-running processes required; works on both protocol eras.
- Keep view files inside your project but they need no direct web exposure — they are
  read by PHP, not fetched by URL. `views/` can sit next to `server.php`; add
  `RewriteRule ^views/ - [F,L]` to `.htaccess` so raw files aren't browsable.
- Views are fetched rarely (cached by URI) and tool calls are ordinary requests —
  an App adds essentially zero hosting load over a plain MCP server.
