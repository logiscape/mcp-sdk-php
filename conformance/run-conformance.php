<?php

/**
 * MCP Conformance Test Runner
 *
 * Orchestrates the official MCP conformance test suite against this SDK's
 * everything-server.php and everything-client.php implementations.
 *
 * Usage:
 *   php conformance/run-conformance.php              # Run both server and client tests
 *   php conformance/run-conformance.php server        # Run server tests only
 *   php conformance/run-conformance.php client        # Run client tests only
 *   php conformance/run-conformance.php server <scenario>  # Run a single server scenario
 *   php conformance/run-conformance.php client <scenario>  # Run a single client scenario
 *
 * Environment variables:
 *   CONFORMANCE_PORT          Port for test server (default: 3000)
 *   CONFORMANCE_SERVER_SUITE  Server test suite (default: "active"; options: active, all, pending)
 *   CONFORMANCE_CLIENT_SUITE  Client test suite (default: "all"; options: all, core, extensions, auth, metadata, sep-835)
 *   CONFORMANCE_VERBOSE       Set to "true" for verbose output
 *
 * Prerequisites:
 *   - Node.js (for @modelcontextprotocol/conformance)
 *   - npm install (installs pinned conformance tool version)
 *   - composer install (PHP dependencies)
 * 
 * Upgrading the conformance tool:
 *   - Update the version in package.json
 *   - Run `npm install`
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

$conformanceDir = __DIR__;
$projectDir = dirname($conformanceDir);
$baseline = $conformanceDir . DIRECTORY_SEPARATOR . 'conformance-baseline.yml';
$serverScript = $conformanceDir . DIRECTORY_SEPARATOR . 'everything-server.php';
$clientScript = $conformanceDir . DIRECTORY_SEPARATOR . 'everything-client.php';
$phpBinary = PHP_BINARY;

// Locate the locally-installed conformance binary (pinned in package.json).
// This avoids floating npx versions that could break the baseline unexpectedly.
$conformanceBin = $projectDir . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR
    . '.bin' . DIRECTORY_SEPARATOR . 'conformance';
if (PHP_OS_FAMILY === 'Windows') {
    $conformanceBin .= '.cmd';
}
if (!file_exists($conformanceBin)) {
    fwrite(STDERR, "ERROR: Conformance tool not installed. Run 'npm install' first.\n");
    exit(1);
}

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
        exit(runServerTests($port, $serverSuite, $scenario, $verbose, $baseline, $serverScript, $phpBinary, $conformanceBin, $serverProcess));

    case 'client':
        exit(runClientTests($clientSuite, $scenario, $verbose, $baseline, $clientScript, $phpBinary, $conformanceBin));

    case 'all':
        $serverExit = runServerTests($port, $serverSuite, $scenario, $verbose, $baseline, $serverScript, $phpBinary, $conformanceBin, $serverProcess);
        $clientExit = runClientTests($clientSuite, $scenario, $verbose, $baseline, $clientScript, $phpBinary, $conformanceBin);
        exit($serverExit !== 0 ? $serverExit : $clientExit);

    default:
        fwrite(STDERR, "Usage: php conformance/run-conformance.php [server|client|all] [scenario]\n");
        exit(1);
}

// --- Functions ---

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

    $serverProcess = proc_open($cmd, $descriptors, $pipes);
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
    string $conformanceBin,
    &$serverProcess
): int {
    startServer($port, $serverScript, $phpBinary, $serverProcess);

    echo "\n=== Running server conformance tests ===\n\n";

    $cmd = sprintf(
        '%s server --url %s --expected-failures %s',
        escapeshellarg($conformanceBin),
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
    string $conformanceBin
): int {
    echo "\n=== Running client conformance tests ===\n\n";

    // Build the command string that the conformance tool will execute.
    // Do not escapeshellarg the individual parts — the whole string gets
    // escaped once when passed as --command to the conformance tool below.
    $clientCommand = "$phpBinary $clientScript";

    $cmd = sprintf(
        '%s client --command %s --expected-failures %s',
        escapeshellarg($conformanceBin),
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
