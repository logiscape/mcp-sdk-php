<?php

/**
 * MCP Conformance Test Runner
 *
 * Orchestrates the official MCP conformance test suite against this SDK's
 * everything-server.php and everything-client.php implementations.
 *
 * Two tracks are supported (see docs/v2-development-plan.md, WS7):
 *   - Stable track: the pinned stable conformance tool with the published-spec
 *     scenarios, gated by conformance-baseline.yml. This is the legacy
 *     regression gate.
 *   - Draft track: the pinned 0.2.0-alpha line carrying the 2026-07-28
 *     draft-spec scenarios, run with --suite draft and gated by its own
 *     conformance-draft-baseline.yml. Installed under the npm alias
 *     "conformance-draft" so both pins coexist.
 *
 * Usage:
 *   php conformance/run-conformance.php              # Run both server and client tests (stable track)
 *   php conformance/run-conformance.php server        # Run server tests only
 *   php conformance/run-conformance.php client        # Run client tests only
 *   php conformance/run-conformance.php server <scenario>  # Run a single server scenario
 *   php conformance/run-conformance.php client <scenario>  # Run a single client scenario
 *   php conformance/run-conformance.php draft          # Run both server and client draft tests (suite + baselined scenarios the suite omits)
 *   php conformance/run-conformance.php server-draft   # Draft server tests only
 *   php conformance/run-conformance.php client-draft   # Draft client tests (suite + baselined scenarios the suite omits)
 *   php conformance/run-conformance.php server-draft <scenario>  # Single draft server scenario
 *   php conformance/run-conformance.php client-draft <scenario>  # Single draft client scenario
 *
 * Environment variables:
 *   CONFORMANCE_PORT          Port for test server (default: 3000)
 *   CONFORMANCE_SERVER_SUITE  Server test suite (default: "active"; options: active, all, pending; stable track only)
 *   CONFORMANCE_CLIENT_SUITE  Client test suite (default: "all"; options: all, core, extensions, auth, metadata, sep-835; stable track only)
 *   CONFORMANCE_VERBOSE       Set to "true" for verbose output
 *
 * Prerequisites:
 *   - Node.js (for @modelcontextprotocol/conformance)
 *   - npm install (installs both pinned conformance tool versions)
 *   - composer install (PHP dependencies)
 *
 * Upgrading the conformance tool:
 *   - Update the version in package.json (stable pin and/or the
 *     "conformance-draft" alias pin)
 *   - Run `npm install`
 *   - Re-curate the baseline file tied to the bumped pin
 */

declare(strict_types=1);

$port = (int) ($_SERVER['CONFORMANCE_PORT'] ?? getenv('CONFORMANCE_PORT') ?: '3000');
$verbose = ($_SERVER['CONFORMANCE_VERBOSE'] ?? getenv('CONFORMANCE_VERBOSE') ?: 'false') === 'true';

// Server and client have different suite namespaces — use separate env vars with
// correct per-mode defaults per the conformance tool documentation.
// Server suites: active (default), all, pending
// Client suites: all (default), core, extensions, auth, metadata, sep-835
$serverSuite = $_SERVER['CONFORMANCE_SERVER_SUITE'] ?? getenv('CONFORMANCE_SERVER_SUITE') ?: 'active';
$clientSuite = $_SERVER['CONFORMANCE_CLIENT_SUITE'] ?? getenv('CONFORMANCE_CLIENT_SUITE') ?: 'all';

// Draft client scenarios that carry a conformance-draft-baseline.yml entry
// but are NOT selected by `--suite draft` (the draft suite only includes
// scenarios tagged exclusively DRAFT-2026-v1; auth/pre-registration is tagged
// [2025-11-25, DRAFT-2026-v1], so it lands in the stable suite's namespace,
// not the draft one). The conformance tool only evaluates the baseline against
// scenarios it actually runs, so without an explicit run these entries are
// never checked — a stale entry (upstream fixes the scenario, or adds the
// missing issuer context) would pass CI silently. The aggregate `draft` and
// `client-draft` gates run each of these explicitly after the suite. Keep this
// list in sync with the draft baseline's client entries that fall outside the
// draft suite; re-check at every draft-pin bump.
const DRAFT_CLIENT_EXTRA_SCENARIOS = ['auth/pre-registration'];

$conformanceDir = __DIR__;
$projectDir = dirname($conformanceDir);
$baseline = $conformanceDir . DIRECTORY_SEPARATOR . 'conformance-baseline.yml';
$draftBaseline = $conformanceDir . DIRECTORY_SEPARATOR . 'conformance-draft-baseline.yml';
$serverScript = $conformanceDir . DIRECTORY_SEPARATOR . 'everything-server.php';
$clientScript = $conformanceDir . DIRECTORY_SEPARATOR . 'everything-client.php';
$phpBinary = PHP_BINARY;

// Track server process for cleanup
$serverProcess = null;

// Ensure server process is always stopped, even on fatal errors or Ctrl+C
register_shutdown_function(function () use (&$serverProcess) {
    stopServer($serverProcess);
});

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function () use (&$serverProcess) {
        stopServer($serverProcess);
        exit(130);
    });
    pcntl_signal(SIGTERM, function () use (&$serverProcess) {
        stopServer($serverProcess);
        exit(143);
    });
}

// --- Main ---

$mode = $argv[1] ?? 'all';
$scenario = $argv[2] ?? null;

chdir($projectDir);

switch ($mode) {
    case 'server':
        $conformanceCmd = resolveConformanceTool($projectDir, '@modelcontextprotocol' . DIRECTORY_SEPARATOR . 'conformance');
        exit(runServerTests($port, $serverSuite, $scenario, $verbose, $baseline, $serverScript, $phpBinary, $conformanceCmd, $serverProcess));

    case 'client':
        $conformanceCmd = resolveConformanceTool($projectDir, '@modelcontextprotocol' . DIRECTORY_SEPARATOR . 'conformance');
        exit(runClientTests($clientSuite, $scenario, $verbose, $baseline, $clientScript, $phpBinary, $conformanceCmd));

    case 'all':
        $conformanceCmd = resolveConformanceTool($projectDir, '@modelcontextprotocol' . DIRECTORY_SEPARATOR . 'conformance');
        $serverExit = runServerTests($port, $serverSuite, $scenario, $verbose, $baseline, $serverScript, $phpBinary, $conformanceCmd, $serverProcess);
        $clientExit = runClientTests($clientSuite, $scenario, $verbose, $baseline, $clientScript, $phpBinary, $conformanceCmd);
        exit($serverExit !== 0 ? $serverExit : $clientExit);

    case 'server-draft':
        $conformanceCmd = resolveConformanceTool($projectDir, 'conformance-draft');
        exit(runServerTests($port, 'draft', $scenario, $verbose, $draftBaseline, $serverScript, $phpBinary, $conformanceCmd, $serverProcess));

    case 'client-draft':
        $conformanceCmd = resolveConformanceTool($projectDir, 'conformance-draft');
        exit(runClientDraftTests($scenario, $verbose, $draftBaseline, $clientScript, $phpBinary, $conformanceCmd));

    case 'draft':
        $conformanceCmd = resolveConformanceTool($projectDir, 'conformance-draft');
        $serverExit = runServerTests($port, 'draft', $scenario, $verbose, $draftBaseline, $serverScript, $phpBinary, $conformanceCmd, $serverProcess);
        $clientExit = runClientDraftTests($scenario, $verbose, $draftBaseline, $clientScript, $phpBinary, $conformanceCmd);
        exit($serverExit !== 0 ? $serverExit : $clientExit);

    default:
        fwrite(STDERR, "Usage: php conformance/run-conformance.php [server|client|all|server-draft|client-draft|draft] [scenario]\n");
        exit(1);
}

// --- Functions ---

/**
 * Resolve a locally-installed conformance tool (pinned in package.json) to a
 * shell-ready command. Both pins declare the same "conformance" bin name, so
 * node_modules/.bin cannot hold them simultaneously — instead the entry
 * script is read from each package's own manifest and run through node.
 */
function resolveConformanceTool(string $projectDir, string $packageDirName): string
{
    $packageDir = $projectDir . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . $packageDirName;
    $manifestPath = $packageDir . DIRECTORY_SEPARATOR . 'package.json';
    if (!file_exists($manifestPath)) {
        fwrite(STDERR, "ERROR: Conformance tool not installed at node_modules/$packageDirName. Run 'npm install' first.\n");
        exit(1);
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    $bin = $manifest['bin'] ?? null;
    $entry = is_array($bin) ? ($bin['conformance'] ?? reset($bin)) : $bin;
    if (!is_string($entry) || $entry === '') {
        fwrite(STDERR, "ERROR: Could not resolve the conformance entry script from $manifestPath.\n");
        exit(1);
    }

    $entryPath = $packageDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
    if (!file_exists($entryPath)) {
        fwrite(STDERR, "ERROR: Conformance entry script missing: $entryPath. Run 'npm install' first.\n");
        exit(1);
    }

    return 'node ' . escapeshellarg($entryPath);
}

function startServer(int $port, string $serverScript, string $phpBinary, &$serverProcess): void
{
    // Check for port conflicts
    $conn = @fsockopen('localhost', $port, $errno, $errstr, 1);
    if ($conn) {
        fclose($conn);
        fwrite(STDERR, "ERROR: Port $port is already in use. Set CONFORMANCE_PORT to use a different port.\n");
        exit(1);
    }

    echo "Starting conformance test server on port $port...\n";

    $cmd = sprintf('%s -S localhost:%d %s', escapeshellarg($phpBinary), $port, escapeshellarg($serverScript));
    $descriptors = [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
        2 => ['pipe', 'w'],
    ];

    // The subscriptions/listen scenarios hold an SSE stream open on one
    // request while a second concurrent tools/call triggers a change, and
    // server-sse-multiple-streams makes three parallel POSTs — a
    // single-worker built-in server would deadlock. Multi-worker mode is
    // POSIX-only (the env var is ignored on Windows, where those checks
    // cannot run locally anyway).
    $env = null;
    if (PHP_OS_FAMILY !== 'Windows') {
        $env = getenv();
        $env['PHP_CLI_SERVER_WORKERS'] = $env['PHP_CLI_SERVER_WORKERS'] ?? '4';
    }

    $serverProcess = proc_open($cmd, $descriptors, $pipes, null, $env);
    if (!is_resource($serverProcess)) {
        fwrite(STDERR, "ERROR: Failed to start PHP built-in server\n");
        exit(1);
    }

    $stderrPipe = $pipes[2];

    // Wait for server to be ready
    $maxWait = 30;
    for ($i = 0; $i < $maxWait; $i++) {
        $status = proc_get_status($serverProcess);
        if (!$status['running']) {
            $stderr = stream_get_contents($stderrPipe);
            fclose($stderrPipe);
            fwrite(STDERR, "ERROR: Server process exited unexpectedly\n");
            if ($stderr) {
                fwrite(STDERR, $stderr);
            }
            $serverProcess = null;
            exit(1);
        }

        $conn = @fsockopen('localhost', $port, $errno, $errstr, 1);
        if ($conn) {
            fclose($conn);
            fclose($stderrPipe);
            $pid = $status['pid'];
            echo "Server ready (PID $pid)\n";
            return;
        }

        sleep(1);
    }

    fclose($stderrPipe);
    fwrite(STDERR, "ERROR: Server failed to start on port $port after {$maxWait}s\n");
    stopServer($serverProcess);
    $serverProcess = null;
    exit(1);
}

function stopServer(&$serverProcess): void
{
    if ($serverProcess === null || !is_resource($serverProcess)) {
        return;
    }

    $status = proc_get_status($serverProcess);
    if ($status['running']) {
        $pid = $status['pid'];
        echo "Stopping conformance test server (PID $pid)...\n";

        // On Windows, proc_terminate sends taskkill which handles the process tree
        if (PHP_OS_FAMILY === 'Windows') {
            // Kill the process tree so child php-cgi workers are also stopped
            exec("taskkill /F /T /PID $pid 2>NUL", $output, $exitCode);
        } else {
            proc_terminate($serverProcess, 15); // SIGTERM
            // Give it a moment to exit gracefully
            usleep(500_000);
            $status = proc_get_status($serverProcess);
            if ($status['running']) {
                proc_terminate($serverProcess, 9); // SIGKILL
            }
        }
    }

    proc_close($serverProcess);
    $serverProcess = null;
}

function runServerTests(
    int $port,
    string $suite,
    ?string $scenario,
    bool $verbose,
    string $baseline,
    string $serverScript,
    string $phpBinary,
    string $conformanceCmd,
    &$serverProcess
): int {
    startServer($port, $serverScript, $phpBinary, $serverProcess);

    echo "\n=== Running server conformance tests (suite: $suite) ===\n\n";

    $cmd = sprintf(
        '%s server --url %s --expected-failures %s',
        $conformanceCmd,
        escapeshellarg("http://localhost:$port"),
        escapeshellarg($baseline)
    );

    if ($scenario !== null) {
        // --scenario and --suite are alternatives; omit suite for single-scenario runs
        $cmd .= ' --scenario ' . escapeshellarg($scenario);
    } else {
        $cmd .= ' --suite ' . escapeshellarg($suite);
    }
    if ($verbose) {
        $cmd .= ' --verbose';
    }

    passthru($cmd, $exitCode);

    stopServer($serverProcess);

    return $exitCode;
}

function runClientTests(
    string $suite,
    ?string $scenario,
    bool $verbose,
    string $baseline,
    string $clientScript,
    string $phpBinary,
    string $conformanceCmd
): int {
    echo "\n=== Running client conformance tests (suite: $suite) ===\n\n";

    // Build the command string that the conformance tool will execute.
    // Do not escapeshellarg the individual parts — the whole string gets
    // escaped once when passed as --command to the conformance tool below.
    $clientCommand = "$phpBinary $clientScript";

    // Tell the everything-client which track it is validating. The draft
    // track runs the SDK's spec-aligned defaults (e.g. mandatory issuer
    // binding for pre-registered credentials); the stable track opts into
    // the published-spec legacy behaviors those scenarios assume.
    if ($suite === 'draft') {
        $clientCommand .= ' --track=draft';
    }

    $cmd = sprintf(
        '%s client --command %s --expected-failures %s',
        $conformanceCmd,
        escapeshellarg($clientCommand),
        escapeshellarg($baseline)
    );

    if ($scenario !== null) {
        // --scenario and --suite are alternatives; omit suite for single-scenario runs
        $cmd .= ' --scenario ' . escapeshellarg($scenario);
    } else {
        $cmd .= ' --suite ' . escapeshellarg($suite);
    }
    if ($verbose) {
        $cmd .= ' --verbose';
    }

    passthru($cmd, $exitCode);

    return $exitCode;
}

/**
 * Run the draft client gate: the draft suite, plus every baselined scenario
 * the suite omits (DRAFT_CLIENT_EXTRA_SCENARIOS), so those baseline entries
 * are actually evaluated and a stale entry fails CI instead of going unnoticed.
 *
 * A single explicit scenario request (e.g. `client-draft auth/pre-registration`)
 * runs only that scenario and skips the extras — the caller asked for one thing.
 * The aggregate run (no scenario) returns the first non-zero exit code across
 * the suite and the extras, so any regression or stale entry propagates.
 */
function runClientDraftTests(
    ?string $scenario,
    bool $verbose,
    string $baseline,
    string $clientScript,
    string $phpBinary,
    string $conformanceCmd
): int {
    if ($scenario !== null) {
        return runClientTests('draft', $scenario, $verbose, $baseline, $clientScript, $phpBinary, $conformanceCmd);
    }

    $exitCode = runClientTests('draft', null, $verbose, $baseline, $clientScript, $phpBinary, $conformanceCmd);

    foreach (DRAFT_CLIENT_EXTRA_SCENARIOS as $extraScenario) {
        echo "\n=== Running draft-baselined client scenario omitted by the draft suite: $extraScenario ===\n";
        $extraExit = runClientTests('draft', $extraScenario, $verbose, $baseline, $clientScript, $phpBinary, $conformanceCmd);
        if ($extraExit !== 0 && $exitCode === 0) {
            $exitCode = $extraExit;
        }
    }

    return $exitCode;
}
