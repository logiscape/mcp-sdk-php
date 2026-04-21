<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 */

declare(strict_types=1);

use Psr\Log\LoggerInterface;

/**
 * Per-request bootstrap for the MCP Web Client.
 *
 * Responsibilities:
 *   - Resolve the Composer autoloader (webclient/vendor first, then project root fallback)
 *   - Load webclient helper classes that are not covered by the SDK's PSR-4 autoload
 *   - Start the PHP session with reasonable security defaults
 *   - Configure the Monolog logger used by every endpoint
 *   - Install a JSON-emitting exception handler
 *   - Provide small helpers for JSON response / request-body parsing and OAuth URL derivation
 *
 * Every api/*.php endpoint starts with:
 *   require_once __DIR__ . '/../lib/Bootstrap.php';
 *   Bootstrap::init();
 */
final class Bootstrap
{
    private static ?LoggerInterface $logger = null;
    /** @var list<array{datetime: string, level: string, message: string, context: array<string, mixed>}> */
    private static array $logBuffer = [];
    private static bool $initialized = false;

    /**
     * Resolve the webclient root path.
     */
    public static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Locate and include the Composer autoloader.
     *
     * Supports two deployment shapes:
     *   1. Standalone webclient: composer install inside webclient/ → webclient/vendor/
     *   2. Dev checkout of the full repo: reuse the project's root vendor/
     */
    public static function autoload(): void
    {
        $candidates = [
            self::root() . '/vendor/autoload.php',
            dirname(self::root()) . '/vendor/autoload.php',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                require_once $path;
                // Load webclient-only classes that aren't on a PSR-4 autoload.
                require_once __DIR__ . '/WebClientInlineLogger.php';
                require_once __DIR__ . '/SessionTokenStorage.php';
                require_once __DIR__ . '/WebCallbackHandler.php';
                require_once __DIR__ . '/ElicitationCapture.php';
                require_once __DIR__ . '/SessionStore.php';
                return;
            }
        }
        http_response_code(500);
        header('Content-Type: text/plain');
        echo "MCP SDK not installed. Run 'composer install' in the project root or in webclient/.\n";
        exit;
    }

    /**
     * Start PHP session with sensible defaults if not already active.
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = self::isHttps();
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * Build the application logger.
     *
     * Uses Monolog if installed (file + in-memory buffer handler). Falls back
     * to a lightweight inline PSR-3 logger that writes to the same log file
     * and captures records for in-request response embedding, so the webclient
     * still works if Monolog is removed.
     */
    public static function logger(): LoggerInterface
    {
        if (self::$logger !== null) {
            return self::$logger;
        }
        $logDir = self::root() . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0o755, true);
        }
        $logFile = $logDir . '/mcp-web-tester.log';

        if (class_exists('\\Monolog\\Logger')) {
            self::$logger = self::buildMonologLogger($logFile);
        } else {
            self::$logger = new WebClientInlineLogger($logFile, self::$logBuffer);
        }
        return self::$logger;
    }

    private static function buildMonologLogger(string $logFile): LoggerInterface
    {
        $formatter = new \Monolog\Formatter\LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            'Y-m-d H:i:s.u'
        );
        // StreamHandler (not RotatingFileHandler) so the filename stays
        // literal and matches api/logs.php's tail target. Operators who want
        // rotation can use logrotate(8) or a cron truncate.
        $fileHandler = new \Monolog\Handler\StreamHandler($logFile, \Monolog\Logger::DEBUG);
        $fileHandler->setFormatter($formatter);

        // A tiny handler that mirrors every record into self::$logBuffer so
        // bufferedLogs() stays source-agnostic.
        $bufferHandler = new class (self::$logBuffer) extends \Monolog\Handler\AbstractProcessingHandler {
            /** @var list<array{datetime: string, level: string, message: string, context: array<string, mixed>}> */
            private array $sink;
            public function __construct(array &$sink)
            {
                parent::__construct(\Monolog\Logger::DEBUG, true);
                $this->sink = &$sink;
            }
            protected function write($record): void
            {
                $this->sink[] = [
                    'datetime' => is_object($record['datetime'] ?? null)
                        ? $record['datetime']->format('Y-m-d H:i:s.u')
                        : (string)($record['datetime'] ?? ''),
                    'level' => (string)($record['level_name'] ?? ''),
                    'message' => (string)($record['message'] ?? ''),
                    'context' => (array)($record['context'] ?? []),
                ];
            }
        };

        $logger = new \Monolog\Logger('mcp-web-client');
        $logger->pushHandler($fileHandler);
        $logger->pushHandler($bufferHandler);
        return $logger;
    }

    /**
     * Records logged during this PHP request, ready to embed in a JSON response.
     *
     * @return list<array{datetime: string, level: string, message: string, context: array<string, mixed>}>
     */
    public static function bufferedLogs(): array
    {
        return self::$logBuffer;
    }

    /**
     * Install a global exception handler that returns JSON + the log buffer.
     */
    public static function installExceptionHandler(): void
    {
        set_exception_handler(static function (Throwable $e): void {
            $logger = self::logger();
            $logger->error('Uncaught exception: ' . $e->getMessage(), [
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            self::json([
                'success' => false,
                'error' => $e->getMessage(),
                'errorClass' => (new ReflectionClass($e))->getShortName(),
                'logs' => self::bufferedLogs(),
            ], 500);
        });
    }

    /**
     * Emit a JSON response and terminate the script.
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): never
    {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            http_response_code($status);
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    /**
     * Parse the request body as JSON. Emits 400 on failure.
     *
     * @return array<string, mixed>
     */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            self::json(['success' => false, 'error' => 'Missing request body'], 400);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            self::json(['success' => false, 'error' => 'Invalid JSON: ' . $e->getMessage()], 400);
        }
        if (!is_array($decoded)) {
            self::json(['success' => false, 'error' => 'Request body must be a JSON object'], 400);
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Build or reuse the per-deployment encryption secret used by the token store.
     */
    public static function encryptionSecret(): string
    {
        $file = self::root() . '/.token_secret';
        if (is_file($file)) {
            $existing = (string)file_get_contents($file);
            if (strlen($existing) >= 32) {
                return $existing;
            }
        }
        $secret = bin2hex(random_bytes(32));
        file_put_contents($file, $secret);
        @chmod($file, 0o600);
        return $secret;
    }

    /**
     * Absolute URL for the OAuth callback endpoint served by this deployment.
     */
    public static function oauthCallbackUrl(): string
    {
        $proto = self::isHttps() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        // SCRIPT_NAME for an endpoint is /.../webclient/api/connect.php; strip the /api trailer.
        $base = preg_replace('#/api$#', '', $scriptDir) ?? $scriptDir;
        return $proto . '://' . $host . $base . '/api/oauth_callback.php';
    }

    /**
     * Directory that holds per-session token files.
     */
    public static function tokenStoragePath(): string
    {
        return self::root() . '/tokens';
    }

    /**
     * Detect HTTPS taking common reverse-proxy headers into account.
     */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    /**
     * Do everything an endpoint needs before running its logic.
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        // Normalize CWD to the webclient root. Endpoints live under api/, so
        // without this the spawned stdio subprocess inherits api/ as its CWD
        // and relative Args paths (e.g. "test_server.php") resolve against
        // api/ instead of the webclient root where operators expect them.
        // The SDK's StdioTransport passes null for proc_open's cwd argument,
        // so the only way to control it is via the parent's CWD.
        @chdir(self::root());
        self::autoload();
        self::startSession();
        self::logger();
        self::installExceptionHandler();
        self::$initialized = true;
    }
}
