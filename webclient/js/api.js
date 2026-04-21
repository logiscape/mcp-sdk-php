// MCP Web Client — fetch wrapper for the JSON API endpoints.

const endpoints = window.mcpWebClient?.endpoints ?? {};

export class ApiError extends Error {
  constructor(message, { status, code, payload } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status ?? 0;
    this.code = code ?? null;
    this.payload = payload ?? null;
  }
}

async function request(url, options = {}) {
  const init = {
    method: options.method ?? 'GET',
    headers: { 'Accept': 'application/json', ...(options.headers ?? {}) },
    credentials: 'same-origin',
  };
  if (options.body !== undefined) {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(options.body);
  }

  let response;
  try {
    response = await fetch(url, init);
  } catch (networkErr) {
    throw new ApiError(`Network error: ${networkErr.message}`, { status: 0 });
  }

  const text = await response.text();
  let payload = null;
  if (text) {
    try {
      payload = JSON.parse(text);
    } catch {
      throw new ApiError(
        `Invalid JSON from server (HTTP ${response.status})`,
        { status: response.status }
      );
    }
  }

  if (!response.ok || payload?.success === false) {
    const message = payload?.error ?? `Request failed (HTTP ${response.status})`;
    throw new ApiError(message, {
      status: response.status,
      code: payload?.code ?? null,
      payload,
    });
  }
  return payload ?? {};
}

export const api = {
  endpoints,

  async connect(params) {
    return request(endpoints.connect, { method: 'POST', body: params });
  },

  async disconnect() {
    return request(endpoints.connect, { method: 'DELETE' });
  },

  async execute(operation, args = {}) {
    return request(endpoints.execute, {
      method: 'POST',
      body: { operation, args },
    });
  },

  async complete(ref, argument) {
    return request(endpoints.complete, {
      method: 'POST',
      body: { ref, argument },
    });
  },
};
