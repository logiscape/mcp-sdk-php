// MCP Web Client — entry point. Wires modules onto a shared app instance.

import { api, ApiError } from './api.js';
import { registerConnection } from './connection.js';
import { registerCapabilities } from './capabilities.js';
import { registerCompletions } from './completions.js';
import { registerLogs } from './logs.js';
import { registerOauth } from './oauth.js';

/**
 * Shared state + event bus passed to every module.
 * Modules must never mutate `state` directly; they go through `setState`
 * so subscribers get a consistent view.
 */
class App {
  constructor() {
    this.state = {
      connection: null,
      catalog: { prompts: null, tools: null, resources: null },
      elicitingTools: new Set(),
      isBusy: false,
    };
    this.handlers = new Map();
    this.api = api;
  }

  on(event, handler) {
    if (!this.handlers.has(event)) {
      this.handlers.set(event, []);
    }
    this.handlers.get(event).push(handler);
  }

  emit(event, data) {
    const list = this.handlers.get(event) ?? [];
    for (const h of list) {
      try {
        h(data);
      } catch (err) {
        console.error(`Handler for "${event}" threw:`, err);
      }
    }
  }

  setState(patch) {
    Object.assign(this.state, patch);
    this.emit('state', this.state);
  }

  markBusy(busy) {
    this.setState({ isBusy: Boolean(busy) });
    document.body.classList.toggle('is-busy', Boolean(busy));
  }

  notify(message, level = 'info') {
    const area = document.getElementById('notification-area');
    if (!area) return;
    const toast = document.createElement('div');
    const levelClass = { error: 'bg-danger', success: 'bg-success', warn: 'bg-warning', info: 'bg-primary' }[level] ?? 'bg-primary';
    toast.className = `toast align-items-center text-white ${levelClass} border-0 show mb-2`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
      <div class="d-flex">
        <div class="toast-body"></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" aria-label="Close"></button>
      </div>`;
    toast.querySelector('.toast-body').textContent = message;
    toast.querySelector('.btn-close').addEventListener('click', () => toast.remove());
    area.appendChild(toast);
    setTimeout(() => toast.remove(), 6000);
  }

  reportError(err, fallback = 'Operation failed') {
    const msg = err instanceof ApiError
      ? err.message
      : (err?.message ?? fallback);
    this.notify(msg, 'error');
    console.error(err);
  }

  // If the backend returned an `oauth_required` payload, notify the user and
  // navigate to the authorization URL. Returns true when a redirect is
  // initiated so callers can skip their normal error reporting.
  maybeHandleOauthRedirect(err) {
    if (!(err instanceof ApiError) || err.code !== 'oauth_required') return false;
    const authUrl = err.payload?.oauth?.authUrl;
    if (!authUrl) return false;
    this.notify('Authorization required — redirecting...', 'warn');
    window.location.href = authUrl;
    return true;
  }
}

const app = new App();

document.addEventListener('DOMContentLoaded', () => {
  registerConnection(app);
  registerCapabilities(app);
  registerCompletions(app);
  registerLogs(app);
  registerOauth(app);
  app.emit('ready');
});

// Expose for quick ad-hoc debugging in the browser console.
window.mcpApp = app;
