// MCP Web Client — prompts / tools / resources list + invoke flows.

import { buildPromptArgsForm, buildSchemaForm } from './forms.js';
import { renderPromptResult, renderResourceResult, renderToolResult } from './results.js';

export function registerCapabilities(app) {
  const dom = {
    serverInfoPanel: document.getElementById('server-info-panel'),
    serverInfoFields: document.getElementById('server-info-fields'),

    promptsPanel: document.getElementById('prompts-panel'),
    promptsList: document.getElementById('prompts-list'),
    promptsResult: document.getElementById('prompts-result'),
    listPromptsBtn: document.getElementById('list-prompts-btn'),
    promptForm: document.getElementById('prompt-form'),
    promptFormTitle: document.getElementById('prompt-form-title'),
    promptArgs: document.getElementById('prompt-arguments'),

    toolsPanel: document.getElementById('tools-panel'),
    toolsList: document.getElementById('tools-list'),
    toolsResult: document.getElementById('tools-result'),
    listToolsBtn: document.getElementById('list-tools-btn'),
    toolForm: document.getElementById('tool-form'),
    toolFormTitle: document.getElementById('tool-form-title'),
    toolArgs: document.getElementById('tool-arguments'),

    resourcesPanel: document.getElementById('resources-panel'),
    resourcesList: document.getElementById('resources-list'),
    resourcesResult: document.getElementById('resources-result'),
    listResourcesBtn: document.getElementById('list-resources-btn'),
  };

  let activePrompt = null;
  let activeTool = null;
  let currentPromptReader = null;
  let currentToolReader = null;

  dom.listPromptsBtn.addEventListener('click', loadPrompts);
  dom.listToolsBtn.addEventListener('click', loadTools);
  dom.listResourcesBtn.addEventListener('click', loadResources);

  dom.promptForm.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (!activePrompt || !currentPromptReader) return;
    let values;
    try {
      values = currentPromptReader();
    } catch (err) {
      app.notify(err.message, 'error');
      return;
    }
    await runOperation('get_prompt', { name: activePrompt.name, arguments: values }, (res) => {
      renderPromptResult(dom.promptsResult, res.result);
      renderElicitations(dom.promptsResult, res.elicitations);
    });
  });

  dom.toolForm.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (!activeTool || !currentToolReader) return;
    let values;
    try {
      values = currentToolReader();
    } catch (err) {
      app.notify(err.message, 'error');
      return;
    }
    await runOperation('call_tool', { name: activeTool.name, arguments: values }, (res) => {
      renderToolResult(dom.toolsResult, res.result, res.elicitations ?? []);
      if ((res.elicitations ?? []).length > 0) {
        app.state.elicitingTools.add(activeTool.name);
        decorateToolRow(activeTool.name);
      }
    });
  });

  app.on('connected', (info) => {
    applyCapabilities(info);
    renderServerInfo(info.serverInfo);
  });
  app.on('disconnected', () => {
    hideAllPanels();
    dom.promptsList.innerHTML = '';
    dom.toolsList.innerHTML = '';
    dom.resourcesList.innerHTML = '';
    dom.promptsResult.innerHTML = '';
    dom.toolsResult.innerHTML = '';
    dom.resourcesResult.innerHTML = '';
    dom.promptForm.hidden = true;
    dom.toolForm.hidden = true;
    activePrompt = null;
    activeTool = null;
    currentPromptReader = null;
    currentToolReader = null;
  });

  async function runOperation(operation, args, onSuccess) {
    app.markBusy(true);
    try {
      const res = await app.api.execute(operation, args);
      app.emit('logs', res.logs);
      onSuccess(res);
    } catch (err) {
      app.emit('logs', err.payload?.logs);
      if (app.maybeHandleOauthRedirect(err)) return;
      app.reportError(err, 'Operation failed');
    } finally {
      app.markBusy(false);
    }
  }

  async function loadPrompts() {
    await runOperation('list_prompts', {}, (res) => {
      const prompts = res.result?.prompts ?? [];
      app.state.catalog.prompts = prompts;
      renderList(dom.promptsList, prompts, 'name', (item) => selectPrompt(item));
      if (prompts.length === 0) app.notify('No prompts available', 'info');
    });
  }

  async function loadTools() {
    await runOperation('list_tools', {}, (res) => {
      const tools = res.result?.tools ?? [];
      app.state.catalog.tools = tools;
      renderList(dom.toolsList, tools, 'name', (item) => selectTool(item), (row, item) => {
        if (app.state.elicitingTools.has(item.name)) {
          const badge = document.createElement('span');
          badge.className = 'elicitation-badge ms-1';
          badge.textContent = 'elicits';
          row.appendChild(badge);
        }
      });
      if (tools.length === 0) app.notify('No tools available', 'info');
    });
  }

  async function loadResources() {
    await runOperation('list_resources', {}, (res) => {
      const resources = res.result?.resources ?? [];
      app.state.catalog.resources = resources;
      renderList(dom.resourcesList, resources, 'name', (item) => selectResource(item));
      if (resources.length === 0) app.notify('No resources available', 'info');
    });
  }

  function selectPrompt(prompt) {
    activePrompt = prompt;
    markSelected(dom.promptsList, prompt.name);
    dom.promptFormTitle.textContent = `Execute: ${prompt.name}`;
    dom.promptArgs.innerHTML = '';
    const form = buildPromptArgsForm(prompt.arguments ?? [], {});
    dom.promptArgs.appendChild(form.element);
    currentPromptReader = form.readValues;
    dom.promptForm.hidden = false;
    app.emit('prompt_selected', prompt);
  }

  function selectTool(tool) {
    activeTool = tool;
    markSelected(dom.toolsList, tool.name);
    dom.toolFormTitle.textContent = `Call: ${tool.name}`;
    dom.toolArgs.innerHTML = '';
    const schema = tool.inputSchema ?? { type: 'object', properties: {} };
    const form = buildSchemaForm(schema, {});
    dom.toolArgs.appendChild(form.element);
    currentToolReader = form.readValues;
    dom.toolForm.hidden = false;
    app.emit('tool_selected', tool);
  }

  function selectResource(resource) {
    markSelected(dom.resourcesList, resource.name);
    const uri = resource.uri;
    if (!uri) {
      app.notify('Resource has no URI', 'warn');
      return;
    }
    runOperation('read_resource', { uri }, (res) => {
      renderResourceResult(dom.resourcesResult, res.result);
    });
  }

  function renderList(ul, items, keyField, onClick, decorate) {
    ul.innerHTML = '';
    for (const item of items) {
      const li = document.createElement('li');
      li.className = 'list-group-item';
      li.dataset.key = item[keyField] ?? '';
      const title = document.createElement('div');
      title.className = 'fw-bold';
      title.textContent = item.title ?? item[keyField] ?? '(unnamed)';
      const meta = document.createElement('div');
      meta.className = 'item-meta';
      meta.textContent = [item.description ?? '', item.mimeType ?? '', item.uri ?? '']
        .filter(Boolean).join(' · ');
      li.appendChild(title);
      if (meta.textContent) li.appendChild(meta);
      if (decorate) decorate(title, item);
      li.addEventListener('click', () => onClick(item));
      ul.appendChild(li);
    }
  }

  function markSelected(ul, key) {
    for (const li of ul.querySelectorAll('.list-group-item')) {
      li.classList.toggle('selected', li.dataset.key === key);
    }
  }

  function decorateToolRow(toolName) {
    for (const li of dom.toolsList.querySelectorAll('.list-group-item')) {
      if (li.dataset.key !== toolName) continue;
      if (li.querySelector('.elicitation-badge')) continue;
      const badge = document.createElement('span');
      badge.className = 'elicitation-badge ms-1';
      badge.textContent = 'elicits';
      li.querySelector('.fw-bold')?.appendChild(badge);
    }
  }

  function renderElicitations(container, elicitations) {
    if (!Array.isArray(elicitations) || elicitations.length === 0) return;
    // Handled inline by renderToolResult for tools; for prompts we append cards.
    for (const evt of elicitations) {
      const card = document.createElement('div');
      card.className = 'elicitation-card mt-2';
      const h = document.createElement('h6');
      h.textContent = 'Server requested elicitation (declined)';
      card.appendChild(h);
      const p = document.createElement('p');
      p.className = 'mb-0';
      p.textContent = evt.message ?? '';
      card.appendChild(p);
      container.appendChild(card);
    }
  }

  function applyCapabilities(info) {
    const caps = info?.capabilities ?? {};
    setPanelVisible(dom.serverInfoPanel, true);
    setPanelVisible(dom.promptsPanel, Boolean(caps.prompts));
    setPanelVisible(dom.toolsPanel, Boolean(caps.tools));
    setPanelVisible(dom.resourcesPanel, Boolean(caps.resources));
  }

  function setPanelVisible(panel, visible) {
    if (!panel) return;
    panel.classList.toggle('is-visible', visible);
  }

  function hideAllPanels() {
    setPanelVisible(dom.serverInfoPanel, false);
    setPanelVisible(dom.promptsPanel, false);
    setPanelVisible(dom.toolsPanel, false);
    setPanelVisible(dom.resourcesPanel, false);
  }

  function renderServerInfo(serverInfo) {
    dom.serverInfoFields.innerHTML = '';
    if (!serverInfo || typeof serverInfo !== 'object') return;
    const rows = [];
    const info = serverInfo.serverInfo ?? {};
    if (info.name) rows.push(['Name', info.name]);
    if (info.version) rows.push(['Version', info.version]);
    if (info.title) rows.push(['Title', info.title]);
    if (serverInfo.negotiatedProtocolVersion) rows.push(['Protocol', serverInfo.negotiatedProtocolVersion]);
    if (serverInfo.instructions) rows.push(['Instructions', serverInfo.instructions]);
    const caps = serverInfo.capabilities ?? {};
    const capList = Object.entries(caps)
      .filter(([_, v]) => v != null)
      .map(([k]) => k)
      .join(', ');
    if (capList) rows.push(['Capabilities', capList]);
    for (const [label, value] of rows) {
      const dt = document.createElement('dt');
      dt.textContent = label;
      const dd = document.createElement('dd');
      dd.textContent = value;
      dom.serverInfoFields.appendChild(dt);
      dom.serverInfoFields.appendChild(dd);
    }
  }
}
