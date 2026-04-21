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
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/Bootstrap.php';
Bootstrap::init();

// Expose any already-established connection to the UI so a page reload shows
// the capability panels immediately without forcing a reconnect. Only
// non-secret fields are exposed: OAuth secrets, env values, header values,
// and stored client credentials stay server-side.
$initialStore = new SessionStore(Bootstrap::logger());
$initialState = null;
if ($initialStore->isActive()) {
    $initialState = [
        'transportType' => $initialStore->transportType(),
        'serverInfo' => $initialStore->serverInfo(),
        'elicitingTools' => $initialStore->elicitingTools(),
        'prefill' => buildPrefill($initialStore->serverConfig()),
    ];
}

/**
 * Return a redacted view of the stored server config, safe to expose to the
 * browser for post-reload form prefill. Values likely to be secret (env vars,
 * header values, OAuth client id / client secret, stored client credentials)
 * are replaced with counts / flags.
 *
 * @param array<string, mixed> $cfg
 * @return array<string, mixed>
 */
function buildPrefill(array $cfg): array
{
    $type = $cfg['type'] ?? 'stdio';
    $prefill = ['type' => $type];
    if ($type === 'stdio') {
        $prefill['command'] = (string)($cfg['command'] ?? '');
        $prefill['argCount'] = count($cfg['args'] ?? []);
        $prefill['envCount'] = count($cfg['env'] ?? []);
        return $prefill;
    }
    $prefill['url'] = (string)($cfg['url'] ?? '');
    $prefill['connectionTimeout'] = (float)($cfg['connectionTimeout'] ?? 30.0);
    $prefill['readTimeout'] = (float)($cfg['readTimeout'] ?? 60.0);
    $prefill['verifyTls'] = (bool)($cfg['verifyTls'] ?? true);
    $prefill['headerCount'] = count($cfg['headers'] ?? []);
    $oauth = $cfg['oauth'] ?? null;
    if (is_array($oauth) && !empty($oauth['enabled'])) {
        $prefill['oauth'] = [
            'enabled' => true,
            'hasClientId' => !empty($oauth['clientId'])
                || !empty($oauth['credentials']['clientId']),
            'hasStoredCredentials' => !empty($oauth['credentials']),
        ];
    }
    return $prefill;
}

// OAuth redirect state (set by api/oauth_callback.php when it redirects back here).
$oauthStatus = isset($_GET['oauth']) ? (string)$_GET['oauth'] : null;
$oauthError = isset($_GET['oauth_error']) ? (string)$_GET['oauth_error'] : null;
$oauthServerId = isset($_GET['oauth_server']) ? (string)$_GET['oauth_server'] : null;

// Flags for JSON embedded directly in <script> blocks. The hex flags prevent
// server-controlled strings (server instructions, capability metadata, OAuth
// query params) from breaking out of the script via </script>, HTML-escape
// entities, or stray quote characters. Never emit json_encode() output into
// inline HTML without these.
const INLINE_JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

$base = dirname((string)$_SERVER['SCRIPT_NAME']);
$base = $base === '/' || $base === '\\' ? '' : rtrim(str_replace('\\', '/', $base), '/');

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MCP Web Client</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($base, ENT_QUOTES) ?>/css/app.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">MCP Web Client</span>
        <span id="connection-badge" class="badge bg-secondary connection-badge" hidden></span>
    </div>
</nav>

<div id="notification-area" class="position-fixed top-0 end-0 p-3" style="z-index: 1100;"></div>

<main class="container mt-4">

    <!-- Connection -->
    <section class="wc-panel">
        <h4>Server Connection</h4>

        <div class="mb-3">
            <label class="form-label d-block">Transport</label>
            <div class="btn-group" role="group" aria-label="Transport">
                <input type="radio" class="btn-check" name="serverType" id="serverTypeStdio" value="stdio" checked>
                <label class="btn btn-outline-primary" for="serverTypeStdio">Local (stdio)</label>
                <input type="radio" class="btn-check" name="serverType" id="serverTypeHttp" value="http">
                <label class="btn btn-outline-primary" for="serverTypeHttp">Remote (HTTP/HTTPS)</label>
            </div>
        </div>

        <form id="connection-form" class="row g-3" novalidate>
            <div id="stdio-fields" class="server-type-fields is-active col-12">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="field-command" class="form-label">Command</label>
                        <input type="text" class="form-control" id="field-command" placeholder="php, node, python...">
                    </div>
                    <div class="col-md-4">
                        <label for="field-args" class="form-label">Arguments (one per line)</label>
                        <textarea class="form-control" id="field-args" rows="3" placeholder="server.php&#10;--option&#10;value"></textarea>
                    </div>
                    <div class="col-md-4">
                        <label for="field-env" class="form-label">Env (KEY=VALUE, one per line)</label>
                        <textarea class="form-control" id="field-env" rows="3" placeholder="DEBUG=true"></textarea>
                    </div>
                </div>
            </div>

            <div id="http-fields" class="server-type-fields col-12">
                <div class="row g-3">
                    <div class="col-12">
                        <label for="field-url" class="form-label">Server URL</label>
                        <input type="url" class="form-control" id="field-url" placeholder="https://mcp.example.com/mcp">
                    </div>
                </div>

                <div class="accordion mt-3" id="httpAdvancedAccordion">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#httpAdvancedCollapse">
                                Advanced Settings
                            </button>
                        </h2>
                        <div id="httpAdvancedCollapse" class="accordion-collapse collapse">
                            <div class="accordion-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label for="field-connection-timeout" class="form-label">Connection Timeout (s)</label>
                                        <input type="number" class="form-control" id="field-connection-timeout" value="30" min="1" max="300">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="field-read-timeout" class="form-label">Read Timeout (s)</label>
                                        <input type="number" class="form-control" id="field-read-timeout" value="60" min="1" max="600">
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="field-verify-tls" checked>
                                            <label class="form-check-label" for="field-verify-tls">Verify TLS certificate</label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label for="field-custom-headers" class="form-label">Custom Headers</label>
                                        <textarea class="form-control" id="field-custom-headers" rows="2" placeholder="X-Custom-Header: value&#10;Authorization: Bearer token"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#oauthCollapse">
                                OAuth Settings
                            </button>
                        </h2>
                        <div id="oauthCollapse" class="accordion-collapse collapse">
                            <div class="accordion-body">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="field-oauth-enabled">
                                    <label class="form-check-label" for="field-oauth-enabled">Enable OAuth authorization</label>
                                    <div class="form-text">For servers that require OAuth 2.0/2.1.</div>
                                </div>
                                <div id="oauth-fields" hidden>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="field-oauth-client-id" class="form-label">Client ID (optional)</label>
                                            <input type="text" class="form-control" id="field-oauth-client-id" placeholder="Leave blank for dynamic registration">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="field-oauth-client-secret" class="form-label">Client Secret (optional)</label>
                                            <input type="password" class="form-control" id="field-oauth-client-secret">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="oauth-status" class="oauth-status col-12">
                <div class="alert alert-info mb-0">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                    <span id="oauth-status-text">Waiting for OAuth authorization...</span>
                </div>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary" id="connect-btn">
                    <span class="spinner-border spinner-border-sm loading-spinner" role="status" aria-hidden="true"></span>
                    Connect
                </button>
                <button type="button" class="btn btn-outline-danger" id="disconnect-btn" disabled>Disconnect</button>
                <button type="button" class="btn btn-outline-secondary" id="ping-btn" disabled>Ping</button>
            </div>
        </form>
    </section>

    <!-- Server info (post-connect) -->
    <section class="wc-panel server-info capability-panel" id="server-info-panel">
        <h4>Server Info</h4>
        <dl id="server-info-fields"></dl>
    </section>

    <!-- Prompts -->
    <section class="wc-panel capability-panel" id="prompts-panel">
        <h4>Prompts</h4>
        <div class="mb-3">
            <button class="btn btn-primary btn-sm" id="list-prompts-btn">List Prompts</button>
        </div>
        <div class="row">
            <div class="col-md-5">
                <ul class="list-group item-list" id="prompts-list"></ul>
            </div>
            <div class="col-md-7">
                <form id="prompt-form" class="mb-3" hidden>
                    <h5 id="prompt-form-title">Execute Prompt</h5>
                    <div id="prompt-arguments"></div>
                    <button type="submit" class="btn btn-primary btn-sm">Execute</button>
                </form>
                <div id="prompts-result"></div>
            </div>
        </div>
    </section>

    <!-- Tools -->
    <section class="wc-panel capability-panel" id="tools-panel">
        <h4>Tools</h4>
        <div class="mb-3">
            <button class="btn btn-primary btn-sm" id="list-tools-btn">List Tools</button>
        </div>
        <div class="row">
            <div class="col-md-5">
                <ul class="list-group item-list" id="tools-list"></ul>
            </div>
            <div class="col-md-7">
                <form id="tool-form" class="mb-3" hidden>
                    <h5 id="tool-form-title">Call Tool</h5>
                    <div id="tool-arguments"></div>
                    <button type="submit" class="btn btn-primary btn-sm">Call</button>
                </form>
                <div id="tools-result"></div>
            </div>
        </div>
    </section>

    <!-- Resources -->
    <section class="wc-panel capability-panel" id="resources-panel">
        <h4>Resources</h4>
        <div class="mb-3">
            <button class="btn btn-primary btn-sm" id="list-resources-btn">List Resources</button>
        </div>
        <div class="row">
            <div class="col-md-5">
                <ul class="list-group item-list" id="resources-list"></ul>
            </div>
            <div class="col-md-7">
                <div id="resources-result"></div>
            </div>
        </div>
    </section>

    <!-- Debug -->
    <section class="wc-panel">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Internal Debug Log</h4>
            <div>
                <button class="btn btn-outline-secondary btn-sm" id="toggle-debug">Show</button>
                <button class="btn btn-outline-danger btn-sm" id="clear-debug">Clear</button>
            </div>
        </div>
        <div class="debug-panel mt-2" id="debug-panel" hidden></div>
    </section>

</main>

<script>
window.mcpWebClient = {
    endpoints: {
        connect: '<?= htmlspecialchars($base, ENT_QUOTES) ?>/api/connect.php',
        execute: '<?= htmlspecialchars($base, ENT_QUOTES) ?>/api/execute.php',
        complete: '<?= htmlspecialchars($base, ENT_QUOTES) ?>/api/complete.php',
        logs:    '<?= htmlspecialchars($base, ENT_QUOTES) ?>/api/logs.php',
    },
    oauth: {
        status: <?= $oauthStatus !== null ? json_encode($oauthStatus, INLINE_JSON_FLAGS) : 'null' ?>,
        error: <?= $oauthError !== null ? json_encode($oauthError, INLINE_JSON_FLAGS) : 'null' ?>,
        serverId: <?= $oauthServerId !== null ? json_encode($oauthServerId, INLINE_JSON_FLAGS) : 'null' ?>
    },
    initial: <?= $initialState !== null ? json_encode($initialState, INLINE_JSON_FLAGS) : 'null' ?>
};
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script type="module" src="<?= htmlspecialchars($base, ENT_QUOTES) ?>/js/main.js"></script>

</body>
</html>
