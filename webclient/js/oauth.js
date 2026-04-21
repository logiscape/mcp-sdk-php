// MCP Web Client — post-OAuth-redirect resume driver.
//
// When oauth_callback.php finishes the code exchange, it redirects back to
// index.php with ?oauth=success&oauth_server=<state>. This module detects
// that marker and POSTs to /api/connect.php {action: "resume_oauth"} to
// actually open the MCP connection (the server-side token is now cached, so
// the SDK's next authorize() call returns silently).

export function registerOauth(app) {
  const state = window.mcpWebClient?.oauth ?? {};

  if (state.status === 'error') {
    const detail = new URLSearchParams(window.location.search).get('oauth_error_detail');
    app.notify(`OAuth error: ${state.error ?? 'unknown'}${detail ? ` — ${detail}` : ''}`, 'error');
    stripOauthParams();
    return;
  }

  if (state.status !== 'success' || !state.serverId) {
    return;
  }

  app.on('ready', async () => {
    app.markBusy(true);
    try {
      const resp = await app.api.connect({
        action: 'resume_oauth',
        serverId: state.serverId,
      });
      app.emit('logs', resp.logs);
      app.notify('OAuth authorization complete', 'success');
      app.emit('connected', resp);
      // Update connection UI via connection.js (which listens on 'connected').
    } catch (err) {
      app.emit('logs', err.payload?.logs);
      // The AS may demand another pass (e.g. insufficient scope on the
      // first grant); connect.php returns oauth_required again, so route
      // it through the shared redirect helper instead of reporting.
      if (app.maybeHandleOauthRedirect(err)) return;
      app.reportError(err, 'OAuth resume failed');
    } finally {
      app.markBusy(false);
      stripOauthParams();
    }
  });
}

function stripOauthParams() {
  const url = new URL(window.location.href);
  for (const param of ['oauth', 'oauth_server', 'oauth_error', 'oauth_error_detail']) {
    url.searchParams.delete(param);
  }
  window.history.replaceState({}, '', url.toString());
}
