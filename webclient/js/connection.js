// MCP Web Client — connection form, transport toggle, connect/disconnect/ping.

export function registerConnection(app) {
  const dom = {
    form: document.getElementById('connection-form'),
    connectBtn: document.getElementById('connect-btn'),
    disconnectBtn: document.getElementById('disconnect-btn'),
    pingBtn: document.getElementById('ping-btn'),
    badge: document.getElementById('connection-badge'),
    stdioFields: document.getElementById('stdio-fields'),
    httpFields: document.getElementById('http-fields'),
    typeStdio: document.getElementById('serverTypeStdio'),
    typeHttp: document.getElementById('serverTypeHttp'),
    oauthToggle: document.getElementById('field-oauth-enabled'),
    oauthFields: document.getElementById('oauth-fields'),
    oauthStatus: document.getElementById('oauth-status'),
    oauthStatusText: document.getElementById('oauth-status-text'),
    command: document.getElementById('field-command'),
    args: document.getElementById('field-args'),
    env: document.getElementById('field-env'),
    url: document.getElementById('field-url'),
    headers: document.getElementById('field-custom-headers'),
    connTimeout: document.getElementById('field-connection-timeout'),
    readTimeout: document.getElementById('field-read-timeout'),
    verifyTls: document.getElementById('field-verify-tls'),
    oauthClientId: document.getElementById('field-oauth-client-id'),
    oauthClientSecret: document.getElementById('field-oauth-client-secret'),
  };

  // Transport toggle
  for (const input of [dom.typeStdio, dom.typeHttp]) {
    input.addEventListener('change', () => {
      const isStdio = dom.typeStdio.checked;
      dom.stdioFields.classList.toggle('is-active', isStdio);
      dom.httpFields.classList.toggle('is-active', !isStdio);
    });
  }
  dom.oauthToggle.addEventListener('change', () => {
    dom.oauthFields.hidden = !dom.oauthToggle.checked;
  });

  dom.form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    await doConnect();
  });
  dom.disconnectBtn.addEventListener('click', doDisconnect);
  dom.pingBtn.addEventListener('click', doPing);

  // Initial state (if the session already has an active connection) must be
  // applied after every module has wired its listeners — otherwise late
  // subscribers (capabilities.js, logs.js, completions.js) miss the first
  // 'connected' event. main.js emits 'ready' after registering all modules,
  // so that's the correct trigger.
  const initial = window.mcpWebClient?.initial ?? null;
  if (initial) {
    app.on('ready', () => applyInitialState(initial));
  }

  async function doConnect() {
    const params = readForm();
    if (!params) return;
    app.markBusy(true);
    try {
      const resp = await app.api.connect(params);
      app.emit('logs', resp.logs);
      app.emit('connected', resp);
    } catch (err) {
      app.emit('logs', err.payload?.logs);
      if (app.maybeHandleOauthRedirect(err)) {
        showOAuthPending('Redirecting to authorization server...');
        return;
      }
      app.reportError(err, 'Connect failed');
    } finally {
      app.markBusy(false);
    }
  }

  app.on('connected', (resp) => {
    if (resp && resp.transportType) {
      applyConnected(resp);
    }
  });

  async function doDisconnect() {
    app.markBusy(true);
    try {
      const resp = await app.api.disconnect();
      app.emit('logs', resp.logs);
    } catch (err) {
      app.reportError(err, 'Disconnect failed');
    } finally {
      app.markBusy(false);
      applyDisconnected();
    }
  }

  async function doPing() {
    app.markBusy(true);
    try {
      await app.api.execute('ping');
      app.notify('Ping: ok', 'success');
    } catch (err) {
      app.reportError(err, 'Ping failed');
    } finally {
      app.markBusy(false);
    }
  }

  function readForm() {
    const isStdio = dom.typeStdio.checked;
    if (isStdio) {
      const command = dom.command.value.trim();
      if (!command) {
        app.notify('Command is required for stdio connections', 'warn');
        return null;
      }
      return {
        type: 'stdio',
        command,
        args: splitLines(dom.args.value),
        env: parseKeyValue(dom.env.value, '='),
      };
    }
    const url = dom.url.value.trim();
    if (!url) {
      app.notify('URL is required for HTTP connections', 'warn');
      return null;
    }
    const oauth = dom.oauthToggle.checked
      ? {
          enabled: true,
          clientId: dom.oauthClientId.value.trim() || undefined,
          clientSecret: dom.oauthClientSecret.value.trim() || undefined,
        }
      : null;
    return {
      type: 'http',
      url,
      headers: parseHeaders(dom.headers.value),
      connectionTimeout: Number(dom.connTimeout.value) || 30,
      readTimeout: Number(dom.readTimeout.value) || 60,
      verifyTls: dom.verifyTls.checked,
      oauth,
    };
  }

  function splitLines(text) {
    return text
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter(Boolean);
  }

  function parseKeyValue(text, separator) {
    const out = {};
    for (const line of splitLines(text)) {
      const idx = line.indexOf(separator);
      if (idx <= 0) continue;
      const key = line.slice(0, idx).trim();
      const value = line.slice(idx + 1).trim();
      if (key) out[key] = value;
    }
    return out;
  }

  function parseHeaders(text) {
    return parseKeyValue(text, ':');
  }

  function applyConnected(resp) {
    app.setState({
      connection: {
        transportType: resp.transportType,
        serverInfo: resp.serverInfo,
        capabilities: resp.capabilities,
      },
      elicitingTools: new Set(resp.elicitingTools ?? []),
      catalog: { prompts: null, tools: null, resources: null },
    });
    updateBadge(resp.transportType);
    dom.connectBtn.disabled = true;
    dom.disconnectBtn.disabled = false;
    dom.pingBtn.disabled = false;
    dom.oauthStatus.classList.remove('is-active');
    app.notify(`Connected (${resp.transportType})`, 'success');
  }

  function applyInitialState(initial) {
    // The server sends a redacted prefill — identifiers only, no secrets.
    // Secret-carrying fields (env values, header values, OAuth id/secret) show
    // a placeholder indicating the count of hidden items; disconnect clears
    // them and the user re-enters on reconnect.
    const prefill = initial.prefill ?? {};
    if (prefill.type === 'stdio') {
      dom.typeStdio.checked = true;
      dom.typeStdio.dispatchEvent(new Event('change'));
      dom.command.value = prefill.command ?? '';
      dom.args.placeholder = describeHidden(prefill.argCount, 'arg');
      dom.args.value = '';
      dom.env.placeholder = describeHidden(prefill.envCount, 'env var');
      dom.env.value = '';
    } else if (prefill.type === 'http') {
      dom.typeHttp.checked = true;
      dom.typeHttp.dispatchEvent(new Event('change'));
      dom.url.value = prefill.url ?? '';
      dom.headers.placeholder = describeHidden(prefill.headerCount, 'custom header');
      dom.headers.value = '';
      dom.connTimeout.value = prefill.connectionTimeout ?? 30;
      dom.readTimeout.value = prefill.readTimeout ?? 60;
      dom.verifyTls.checked = prefill.verifyTls !== false;
      if (prefill.oauth?.enabled) {
        dom.oauthToggle.checked = true;
        dom.oauthToggle.dispatchEvent(new Event('change'));
        dom.oauthClientId.value = '';
        dom.oauthClientId.placeholder = prefill.oauth.hasStoredCredentials
          ? 'Client credentials stored (disconnect to change)'
          : (prefill.oauth.hasClientId ? 'Client ID supplied (hidden)' : '');
        dom.oauthClientSecret.placeholder = 'Client secret hidden (disconnect to change)';
      }
    }
    app.emit('connected', {
      transportType: initial.transportType,
      serverInfo: initial.serverInfo,
      capabilities: deriveCaps(initial.serverInfo),
      elicitingTools: initial.elicitingTools ?? [],
    });
  }

  function describeHidden(count, label) {
    if (!count) return '';
    const plural = count === 1 ? label : `${label}s`;
    return `${count} ${plural} — hidden on reload; disconnect to change`;
  }

  function deriveCaps(serverInfo) {
    const caps = serverInfo?.capabilities ?? {};
    return {
      prompts: caps.prompts != null,
      tools: caps.tools != null,
      resources: caps.resources != null,
      logging: caps.logging != null,
      completions: caps.completions != null,
    };
  }

  function applyDisconnected() {
    app.setState({
      connection: null,
      elicitingTools: new Set(),
      catalog: { prompts: null, tools: null, resources: null },
    });
    dom.connectBtn.disabled = false;
    dom.disconnectBtn.disabled = true;
    dom.pingBtn.disabled = true;
    dom.badge.hidden = true;
    dom.oauthStatus.classList.remove('is-active');
    app.notify('Disconnected', 'info');
    app.emit('disconnected');
  }

  function updateBadge(transportType) {
    dom.badge.hidden = false;
    dom.badge.textContent = transportType === 'http' ? 'HTTP' : 'stdio';
    dom.badge.classList.remove('bg-secondary', 'bg-success', 'bg-info');
    dom.badge.classList.add(transportType === 'http' ? 'bg-success' : 'bg-info');
  }

  function showOAuthPending(message) {
    dom.oauthStatus.classList.add('is-active');
    dom.oauthStatusText.textContent = message;
  }
}
