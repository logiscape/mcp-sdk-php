// MCP Web Client — dynamic form generation.
//
// Two builders:
//   buildPromptArgsForm(promptArgs, values)   for Prompt.arguments[] (flat, stringly-typed)
//   buildSchemaForm(schema, values, opts)     for JSON Schema (tool inputs, elicitation previews)
//
// Both return { element, readValues() }. Callers append `element`, then call
// `readValues()` on submit to get a plain JS object whose keys match the schema.

let uid = 0;
const nextId = (prefix = 'f') => `${prefix}-${++uid}`;

export function buildPromptArgsForm(promptArgs, values = {}) {
  const container = document.createElement('div');
  const controls = [];
  for (const arg of promptArgs ?? []) {
    const id = nextId('prompt-arg');
    const wrapper = document.createElement('div');
    wrapper.className = 'mb-3';

    const label = document.createElement('label');
    label.className = 'form-label';
    label.setAttribute('for', id);
    label.textContent = arg.name + (arg.required ? ' *' : '');

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control';
    input.id = id;
    input.name = arg.name;
    if (arg.required) input.required = true;
    const initial = values?.[arg.name];
    if (initial !== undefined && initial !== null) input.value = String(initial);

    wrapper.appendChild(label);
    wrapper.appendChild(input);
    if (arg.description) {
      const desc = document.createElement('div');
      desc.className = 'form-text';
      desc.textContent = arg.description;
      wrapper.appendChild(desc);
    }
    container.appendChild(wrapper);
    controls.push({ name: arg.name, input });
  }
  return {
    element: container,
    readValues() {
      const out = {};
      for (const { name, input } of controls) {
        const value = input.value.trim();
        if (value !== '') out[name] = value;
      }
      return out;
    },
  };
}

export function buildSchemaForm(schema, values = {}, opts = {}) {
  const container = document.createElement('div');
  if (!schema || schema.type !== 'object' || !schema.properties) {
    // Not an object schema — fall back to a single raw-JSON textarea.
    return buildJsonTextarea(schema, values);
  }
  const required = new Set(schema.required ?? []);
  const controls = [];
  for (const [propName, propSchema] of Object.entries(schema.properties)) {
    const control = buildField(propName, propSchema, {
      required: required.has(propName),
      value: values?.[propName] ?? propSchema.default,
    });
    container.appendChild(control.wrapper);
    controls.push({ name: propName, control });
  }
  return {
    element: container,
    readValues() {
      const out = {};
      for (const { name, control } of controls) {
        const val = control.read();
        if (val !== undefined) out[name] = val;
      }
      return out;
    },
  };
}

function buildField(name, schema, { required, value }) {
  const id = nextId('field');
  const wrapper = document.createElement('div');
  wrapper.className = 'mb-3';

  const label = document.createElement('label');
  label.className = 'form-label';
  label.setAttribute('for', id);
  label.textContent = (schema.title ?? name) + (required ? ' *' : '');
  wrapper.appendChild(label);

  const type = Array.isArray(schema.type) ? schema.type[0] : (schema.type ?? 'string');
  const enumValues = Array.isArray(schema.enum) ? schema.enum : null;
  let input;
  let read;

  if (enumValues) {
    input = document.createElement('select');
    input.className = 'form-select';
    input.id = id;
    if (!required) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = '(unset)';
      input.appendChild(opt);
    }
    for (const v of enumValues) {
      const opt = document.createElement('option');
      opt.value = String(v);
      opt.textContent = String(v);
      input.appendChild(opt);
    }
    if (value !== undefined && value !== null) input.value = String(value);
    read = () => {
      if (input.value === '') return undefined;
      return coerce(input.value, type);
    };
  } else if (type === 'boolean') {
    wrapper.classList.replace('mb-3', 'form-check');
    wrapper.classList.add('mb-3');
    input = document.createElement('input');
    input.type = 'checkbox';
    input.className = 'form-check-input';
    input.id = id;
    input.checked = Boolean(value);
    // Reposition label to match Bootstrap form-check layout.
    label.classList.remove('form-label');
    label.classList.add('form-check-label');
    wrapper.innerHTML = '';
    wrapper.appendChild(input);
    wrapper.appendChild(label);
    read = () => input.checked;
  } else if (type === 'integer' || type === 'number') {
    input = document.createElement('input');
    input.type = 'number';
    input.className = 'form-control';
    input.id = id;
    if (type === 'integer') input.step = '1';
    if (schema.minimum !== undefined) input.min = String(schema.minimum);
    if (schema.maximum !== undefined) input.max = String(schema.maximum);
    if (value !== undefined && value !== null) input.value = String(value);
    read = () => {
      if (input.value === '') return undefined;
      return type === 'integer' ? parseInt(input.value, 10) : parseFloat(input.value);
    };
  } else if (type === 'array') {
    input = document.createElement('textarea');
    input.className = 'form-control font-monospace';
    input.id = id;
    input.rows = 3;
    input.placeholder = 'One JSON value per line';
    if (Array.isArray(value)) {
      input.value = value.map((v) => (typeof v === 'string' ? v : JSON.stringify(v))).join('\n');
    }
    read = () => {
      const text = input.value.trim();
      if (text === '') return undefined;
      return text.split(/\r?\n/).map((line) => {
        const trimmed = line.trim();
        if (trimmed === '') return null;
        try {
          return JSON.parse(trimmed);
        } catch {
          return trimmed;
        }
      }).filter((x) => x !== null);
    };
  } else if (type === 'object') {
    input = document.createElement('textarea');
    input.className = 'form-control font-monospace';
    input.id = id;
    input.rows = 4;
    input.placeholder = '{ "key": "value" }';
    if (value && typeof value === 'object') {
      input.value = JSON.stringify(value, null, 2);
    }
    read = () => {
      const text = input.value.trim();
      if (text === '') return undefined;
      try {
        return JSON.parse(text);
      } catch (err) {
        throw new Error(`Field "${name}" must be valid JSON: ${err.message}`);
      }
    };
  } else {
    // string (default)
    const multiline = schema.format === 'textarea' || (schema.maxLength ?? 0) > 200;
    input = document.createElement(multiline ? 'textarea' : 'input');
    input.className = 'form-control';
    input.id = id;
    if (!multiline) input.type = schema.format === 'password' ? 'password' : 'text';
    else input.rows = 3;
    if (schema.pattern) input.pattern = schema.pattern;
    if (value !== undefined && value !== null) input.value = String(value);
    read = () => {
      const v = input.value;
      return v === '' ? undefined : v;
    };
  }

  // Skip boolean: JSON Schema `required` means "property must be present",
  // not "boolean must be true". A checkbox always reads as true or false,
  // so presence is already guaranteed — setting input.required would force
  // the browser to block submitting an unchecked (false) required flag.
  if (required && input && type !== 'boolean') input.required = true;
  if (type !== 'boolean') wrapper.appendChild(input);

  if (schema.description) {
    const desc = document.createElement('div');
    desc.className = 'form-text';
    desc.textContent = schema.description;
    wrapper.appendChild(desc);
  }

  return { wrapper, input, read };
}

function buildJsonTextarea(schema, value) {
  const wrapper = document.createElement('div');
  wrapper.className = 'mb-3';
  const label = document.createElement('label');
  label.className = 'form-label';
  label.textContent = 'Arguments (JSON)';
  const textarea = document.createElement('textarea');
  textarea.className = 'form-control font-monospace';
  textarea.rows = 4;
  if (value && typeof value === 'object') {
    textarea.value = JSON.stringify(value, null, 2);
  }
  wrapper.appendChild(label);
  wrapper.appendChild(textarea);
  if (schema?.description) {
    const desc = document.createElement('div');
    desc.className = 'form-text';
    desc.textContent = schema.description;
    wrapper.appendChild(desc);
  }
  return {
    element: wrapper,
    readValues() {
      const text = textarea.value.trim();
      if (text === '') return {};
      try {
        const parsed = JSON.parse(text);
        return typeof parsed === 'object' && parsed !== null ? parsed : {};
      } catch (err) {
        throw new Error(`Invalid JSON: ${err.message}`);
      }
    },
  };
}

function coerce(value, type) {
  if (type === 'integer') return parseInt(value, 10);
  if (type === 'number') return parseFloat(value);
  if (type === 'boolean') return value === 'true' || value === true;
  return value;
}
