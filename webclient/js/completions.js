// MCP Web Client — debounced auto-complete on tool/prompt argument inputs.
//
// Listens for `tool_selected` / `prompt_selected` and attaches an `input`
// handler to every text field inside that form. Typing fires the MCP
// `completion/complete` request via /api/complete.php.

const DEBOUNCE_MS = 300;

export function registerCompletions(app) {
  const toolArgsRoot = document.getElementById('tool-arguments');
  const promptArgsRoot = document.getElementById('prompt-arguments');
  if (!toolArgsRoot || !promptArgsRoot) return;

  let activeRef = null;
  let dropdown = null;
  let dropdownOwner = null;
  let pendingTimer = null;
  let pendingSeq = 0;

  app.on('tool_selected', (tool) => {
    // The MCP spec allows completions on ref/prompt and ref/resource only;
    // tool-args completion isn't supported by the protocol, so we skip wiring.
    activeRef = null;
    hideDropdown();
    detachAll(toolArgsRoot);
  });

  app.on('prompt_selected', (prompt) => {
    const caps = app.state.connection?.capabilities ?? {};
    if (!caps.completions) {
      activeRef = null;
      return;
    }
    activeRef = { type: 'ref/prompt', name: prompt.name };
    attachHandlers(promptArgsRoot);
  });

  app.on('disconnected', () => {
    activeRef = null;
    hideDropdown();
    detachAll(toolArgsRoot);
    detachAll(promptArgsRoot);
  });

  function attachHandlers(root) {
    for (const input of root.querySelectorAll('input[type="text"], textarea')) {
      if (input.dataset.completionWired === '1') continue;
      input.dataset.completionWired = '1';
      input.addEventListener('input', onInput);
      input.addEventListener('focus', onInput);
      input.addEventListener('blur', () => setTimeout(hideDropdown, 150));
    }
  }

  function detachAll(root) {
    for (const input of root.querySelectorAll('input[type="text"], textarea')) {
      input.dataset.completionWired = '';
    }
  }

  function onInput(ev) {
    if (!activeRef) return;
    const input = ev.currentTarget;
    const name = input.id?.replace(/^.*-/, '') ?? input.name ?? '';
    const value = input.value;
    clearTimeout(pendingTimer);
    if (value === '') {
      hideDropdown();
      return;
    }
    const seq = ++pendingSeq;
    pendingTimer = setTimeout(async () => {
      try {
        const res = await app.api.complete(activeRef, {
          name: input.name || name,
          value,
        });
        if (seq !== pendingSeq) return; // stale response
        const values = res.result?.completion?.values ?? [];
        if (values.length === 0) {
          hideDropdown();
          return;
        }
        showDropdown(input, values);
      } catch (err) {
        // Check oauth_required first, regardless of whether this response
        // is stale — an expired token will fail every subsequent request,
        // so redirecting on the first observation is the right move.
        if (app.maybeHandleOauthRedirect(err)) return;
        hideDropdown();
      }
    }, DEBOUNCE_MS);
  }

  function showDropdown(input, values) {
    ensureDropdown();
    dropdownOwner = input;
    dropdown.innerHTML = '';
    for (const v of values) {
      const li = document.createElement('li');
      li.textContent = String(v);
      li.addEventListener('mousedown', (ev) => {
        ev.preventDefault();
        input.value = String(v);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        hideDropdown();
      });
      dropdown.appendChild(li);
    }
    positionDropdown(input);
    dropdown.hidden = false;
  }

  function ensureDropdown() {
    if (dropdown) return;
    dropdown = document.createElement('ul');
    dropdown.className = 'completion-list';
    dropdown.hidden = true;
    document.body.appendChild(dropdown);
  }

  function positionDropdown(input) {
    const rect = input.getBoundingClientRect();
    dropdown.style.top = `${window.scrollY + rect.bottom}px`;
    dropdown.style.left = `${window.scrollX + rect.left}px`;
    dropdown.style.width = `${rect.width}px`;
  }

  function hideDropdown() {
    if (dropdown) dropdown.hidden = true;
    dropdownOwner = null;
  }
}
