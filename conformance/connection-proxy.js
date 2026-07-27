#!/usr/bin/env node
/**
 * Connection-level round-robin TCP proxy for the conformance harness.
 *
 * Windows only. PHP's built-in web server cannot fork request workers there
 * (PHP_CLI_SERVER_WORKERS is fork-based and POSIX-only), so a lone `php -S`
 * serves one request at a time and the concurrent-stream checks — a held-open
 * subscriptions/listen stream plus a separate triggering call, or several
 * parallel SSE POSTs — can never be exercised locally. run-conformance.php
 * therefore starts several single-worker `php -S` backends and this proxy in
 * front of them on the public port.
 *
 * Each inbound TCP connection is relayed verbatim (no HTTP parsing, no header
 * rewriting) to the next backend in round-robin order, so every scenario still
 * talks to the unmodified SDK stack end-to-end. The resulting topology —
 * isolated PHP processes coordinating only through the file-backed stores
 * (sessions, subscription bus, task store) — is the same shared-nothing model
 * as production PHP-FPM, and the same one the POSIX path's forked workers
 * already impose.
 *
 * Usage: node connection-proxy.js <listenPort> <backendPort> [backendPort...]
 */
'use strict';

const net = require('net');

const ports = process.argv.slice(2).map(Number);
if (ports.length < 2 || ports.some((p) => !Number.isInteger(p) || p <= 0)) {
  console.error('Usage: node connection-proxy.js <listenPort> <backendPort> [backendPort...]');
  process.exit(2);
}
const [listenPort, ...backendPorts] = ports;

// Least-connections routing, round-robin on ties. Blind round-robin is not
// enough here: each backend has exactly one worker, so a connection routed to
// the backend currently holding a subscriptions/listen SSE stream would queue
// behind it until the stream closes — and the scenario's trigger call must be
// served WHILE the stream is open. An open relay is exactly the busy signal,
// so route every new connection to the backend with the fewest active relays.
const active = backendPorts.map(() => 0);
let next = 0;

function pickBackend() {
  let best = next;
  for (let i = 0; i < backendPorts.length; i++) {
    const candidate = (next + i) % backendPorts.length;
    if (active[candidate] < active[best]) {
      best = candidate;
    }
  }
  next = (best + 1) % backendPorts.length;
  return best;
}

const handleConnection = (client) => {
  const index = pickBackend();
  active[index] += 1;
  let released = false;
  const release = () => {
    if (!released) {
      released = true;
      active[index] -= 1;
    }
  };

  const backend = net.connect({ host: '127.0.0.1', port: backendPorts[index], noDelay: true });

  backend.on('connect', () => {
    client.pipe(backend);
    backend.pipe(client);
  });

  // Any error or close on either side tears down both: a half-dead relay
  // would otherwise hold an SSE stream open past its server.
  client.on('error', () => backend.destroy());
  backend.on('error', () => client.destroy());
  client.on('close', () => { backend.destroy(); release(); });
  backend.on('close', () => { client.destroy(); release(); });
};

// Loopback ONLY — never the wildcard address, which would expose the
// conformance server to other machines for the duration of a run (and the
// MCP spec's transport security guidance says locally-running servers bind
// loopback, not all interfaces). "localhost" may resolve to ::1 or 127.0.0.1
// depending on the OS, so cover both families with a loopback listener each:
// 127.0.0.1 is required (the runner's readiness probe polls it), ::1 is
// best-effort (skipped on hosts without IPv6, fatal on any other error —
// EADDRINUSE there would silently divert traffic to a foreign process).
function listenLoopback(host, required, onDone) {
  const server = net.createServer({ noDelay: true }, handleConnection);
  server.on('error', (err) => {
    if (!required && (err.code === 'EADDRNOTAVAIL' || err.code === 'EAFNOSUPPORT')) {
      console.error(`proxy: ${host} unavailable (${err.code}); continuing without it`);
      onDone();
      return;
    }
    console.error(`proxy: listen ${host}:${listenPort} failed: ${err.message}`);
    process.exit(1);
  });
  server.listen(listenPort, host, onDone);
}

listenLoopback('127.0.0.1', true, () => {
  listenLoopback('::1', false, () => {
    console.log(`proxy: listening on loopback ${listenPort} -> ${backendPorts.join(', ')}`);
  });
});
