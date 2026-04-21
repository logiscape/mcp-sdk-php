// MCP Web Client — renderers for MCP response shapes.

import { buildSchemaForm } from './forms.js';

export function renderToolResult(container, result, elicitations = []) {
  container.innerHTML = '';
  if (!result) {
    appendBlock(container, 'No result.');
    return;
  }
  if (result.isError) {
    const banner = document.createElement('div');
    banner.className = 'alert alert-danger';
    banner.textContent = 'Tool reported an error.';
    container.appendChild(banner);
  }
  renderContentArray(container, result.content ?? []);
  if (result.structuredContent !== undefined && result.structuredContent !== null) {
    const wrap = document.createElement('div');
    wrap.className = 'result-block';
    const label = document.createElement('div');
    label.className = 'fw-bold mb-1';
    label.textContent = 'Structured output';
    wrap.appendChild(label);
    wrap.appendChild(preJson(result.structuredContent));
    container.appendChild(wrap);
  }
  for (const elicit of elicitations) {
    container.appendChild(renderElicitationCard(elicit));
  }
}

export function renderPromptResult(container, result) {
  container.innerHTML = '';
  if (!result) {
    appendBlock(container, 'No messages.');
    return;
  }
  if (result.description) {
    const desc = document.createElement('p');
    desc.className = 'text-muted';
    desc.textContent = result.description;
    container.appendChild(desc);
  }
  const messages = result.messages ?? [];
  if (messages.length === 0) {
    appendBlock(container, 'No messages.');
    return;
  }
  for (const msg of messages) {
    const wrap = document.createElement('div');
    wrap.className = 'result-block';
    const header = document.createElement('div');
    header.className = 'fw-bold mb-2';
    header.textContent = (msg.role ?? 'user').toUpperCase();
    wrap.appendChild(header);
    renderContentArray(wrap, Array.isArray(msg.content) ? msg.content : [msg.content]);
    container.appendChild(wrap);
  }
}

export function renderResourceResult(container, result) {
  container.innerHTML = '';
  const contents = result?.contents ?? [];
  if (contents.length === 0) {
    appendBlock(container, 'Resource is empty.');
    return;
  }
  for (const item of contents) {
    container.appendChild(renderResourceContent(item));
  }
}

function renderContentArray(container, items) {
  for (const item of items ?? []) {
    container.appendChild(renderContentBlock(item));
  }
}

function renderContentBlock(item) {
  if (!item || typeof item !== 'object') {
    return wrapAs(String(item));
  }
  const wrap = document.createElement('div');
  wrap.className = 'result-block';
  switch (item.type) {
    case 'text':
      wrap.appendChild(preText(item.text ?? ''));
      break;
    case 'image':
      wrap.classList.add('result-image');
      wrap.appendChild(buildImage(item.data, item.mimeType));
      break;
    case 'audio':
      wrap.classList.add('result-audio');
      wrap.appendChild(buildAudio(item.data, item.mimeType));
      break;
    case 'resource':
      wrap.appendChild(renderEmbeddedResource(item.resource ?? item));
      break;
    case 'resource_link':
      wrap.appendChild(renderResourceLink(item));
      break;
    default:
      wrap.appendChild(preJson(item));
  }
  return wrap;
}

function renderEmbeddedResource(resource) {
  const container = document.createElement('div');
  const heading = document.createElement('div');
  heading.className = 'fw-bold mb-1';
  heading.textContent = `Embedded resource: ${resource.uri ?? '(no uri)'}`;
  container.appendChild(heading);
  if (resource.mimeType) {
    const meta = document.createElement('div');
    meta.className = 'item-meta mb-2';
    meta.textContent = resource.mimeType;
    container.appendChild(meta);
  }
  if (typeof resource.text === 'string') {
    container.appendChild(preText(resource.text));
  } else if (typeof resource.blob === 'string') {
    const pre = document.createElement('div');
    pre.className = 'item-meta';
    const approxBytes = Math.floor(resource.blob.length * 0.75);
    pre.textContent = `Binary blob (~${approxBytes} bytes, base64)`;
    container.appendChild(pre);
  }
  return container;
}

function renderResourceLink(item) {
  const container = document.createElement('div');
  const link = document.createElement('code');
  link.textContent = item.uri ?? '(no uri)';
  const heading = document.createElement('div');
  heading.className = 'fw-bold mb-1';
  heading.textContent = item.name ?? 'Resource link';
  container.appendChild(heading);
  container.appendChild(link);
  if (item.mimeType) {
    const meta = document.createElement('div');
    meta.className = 'item-meta';
    meta.textContent = item.mimeType;
    container.appendChild(meta);
  }
  return container;
}

function renderResourceContent(item) {
  const wrap = document.createElement('div');
  wrap.className = 'result-block';
  const heading = document.createElement('div');
  heading.className = 'fw-bold mb-1';
  heading.textContent = item.uri ?? '(no uri)';
  wrap.appendChild(heading);
  if (item.mimeType) {
    const meta = document.createElement('div');
    meta.className = 'item-meta mb-2';
    meta.textContent = item.mimeType;
    wrap.appendChild(meta);
  }
  if (typeof item.text === 'string') {
    wrap.appendChild(preText(item.text));
  } else if (typeof item.blob === 'string') {
    const note = document.createElement('div');
    note.className = 'item-meta';
    const approxBytes = Math.floor(item.blob.length * 0.75);
    note.textContent = `Binary blob (~${approxBytes} bytes, base64)`;
    wrap.appendChild(note);
  } else {
    wrap.appendChild(preJson(item));
  }
  return wrap;
}

export function renderElicitationCard(event) {
  const card = document.createElement('div');
  card.className = 'elicitation-card';

  const heading = document.createElement('h6');
  heading.textContent = event.mode === 'url'
    ? 'Server requested elicitation (URL mode)'
    : 'Server requested elicitation (auto-declined)';
  card.appendChild(heading);

  const message = document.createElement('p');
  message.className = 'mb-2';
  message.textContent = event.message ?? '';
  card.appendChild(message);

  if (event.mode === 'url' && event.url) {
    const link = document.createElement('a');
    link.href = event.url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.className = 'btn btn-sm btn-outline-primary';
    link.textContent = 'Open authorization URL';
    card.appendChild(link);
    return card;
  }

  const note = document.createElement('div');
  note.className = 'item-meta mb-2';
  note.textContent = 'The web client declined this elicitation. The fields below show what the server requested.';
  card.appendChild(note);

  if (event.requestedSchema) {
    const preview = buildSchemaForm(event.requestedSchema);
    // Disable all inputs so this is purely a preview of what was asked.
    for (const input of preview.element.querySelectorAll('input, textarea, select')) {
      input.disabled = true;
    }
    card.appendChild(preview.element);
  }
  return card;
}

function preText(text) {
  const pre = document.createElement('pre');
  pre.textContent = text;
  return pre;
}

function preJson(value) {
  const pre = document.createElement('pre');
  try {
    pre.textContent = JSON.stringify(value, null, 2);
  } catch {
    pre.textContent = String(value);
  }
  return pre;
}

function buildImage(base64, mimeType) {
  const img = document.createElement('img');
  img.alt = 'Image content';
  img.src = `data:${mimeType ?? 'image/png'};base64,${base64 ?? ''}`;
  return img;
}

function buildAudio(base64, mimeType) {
  const audio = document.createElement('audio');
  audio.controls = true;
  audio.src = `data:${mimeType ?? 'audio/mpeg'};base64,${base64 ?? ''}`;
  return audio;
}

function wrapAs(text) {
  const wrap = document.createElement('div');
  wrap.className = 'result-block';
  wrap.textContent = text;
  return wrap;
}

function appendBlock(container, text) {
  const wrap = document.createElement('div');
  wrap.className = 'result-block';
  wrap.textContent = text;
  container.appendChild(wrap);
}
