// MCP Web Client — internal debug log panel.
//
// This panel shows logs produced by the webclient's PHP endpoints — *not*
// MCP server log messages streamed over the `logging/` channel.

export function registerLogs(app) {
  const panel = document.getElementById('debug-panel');
  const toggle = document.getElementById('toggle-debug');
  const clearBtn = document.getElementById('clear-debug');
  if (!panel || !toggle) return;

  toggle.addEventListener('click', () => {
    const isHidden = panel.hasAttribute('hidden');
    if (isHidden) {
      panel.removeAttribute('hidden');
      toggle.textContent = 'Hide';
    } else {
      panel.setAttribute('hidden', '');
      toggle.textContent = 'Show';
    }
  });

  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      panel.innerHTML = '';
    });
  }

  app.on('logs', (entries) => {
    if (!Array.isArray(entries) || entries.length === 0) return;
    for (const entry of entries) {
      panel.appendChild(renderEntry(entry));
    }
    panel.scrollTop = panel.scrollHeight;
  });
}

function renderEntry(entry) {
  const row = document.createElement('div');
  const ts = document.createElement('span');
  ts.className = 'timestamp';
  ts.textContent = `[${entry.datetime ?? ''}]`;
  const level = document.createElement('span');
  level.className = 'level';
  level.textContent = ` ${entry.level ?? ''}`;
  const msg = document.createElement('span');
  msg.className = 'message';
  msg.textContent = ` ${entry.message ?? ''}`;
  row.appendChild(ts);
  row.appendChild(level);
  row.appendChild(msg);
  if (entry.context && Object.keys(entry.context).length > 0) {
    const ctx = document.createElement('span');
    ctx.className = 'text-muted';
    ctx.textContent = ' ' + JSON.stringify(entry.context);
    row.appendChild(ctx);
  }
  return row;
}
