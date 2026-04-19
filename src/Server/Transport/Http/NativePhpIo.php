<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2025 Logiscape LLC <https://logiscape.com>
 *
 * Developed by:
 * - Josh Abbott
 * - Claude 3.7 Sonnet (Anthropic AI model)
 * - ChatGPT o1 pro mode
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 *
 * Filename: Server/Transport/Http/NativePhpIo.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport\Http;

/**
 * Default HttpIoInterface implementation backed by PHP's native SAPI
 * functions. Every function call is guarded with function_exists() so the
 * runner keeps working on hardened shared hosts that remove pieces of the
 * function table via disable_functions.
 *
 * This is the only class in the SDK that should call header(),
 * http_response_code(), echo, flush(), ob_*(), connection_aborted(),
 * ignore_user_abort(), or register_shutdown_function() directly.
 */
final class NativePhpIo implements HttpIoInterface
{
    public function sendStatus(int $code): void
    {
        if (\function_exists('http_response_code')) {
            \http_response_code($code);
        }
    }

    public function sendHeader(string $name, string $value): void
    {
        if (\function_exists('header')) {
            \header("$name: $value");
        }
    }

    public function headersSent(): bool
    {
        if (\function_exists('headers_sent')) {
            return \headers_sent();
        }
        return false;
    }

    public function drainOutputBuffers(): void
    {
        if (!\function_exists('ob_get_level') || !\function_exists('ob_end_flush')) {
            return;
        }

        // A buffer can be non-removable (framework- or SAPI-owned) in which
        // case ob_end_flush() returns false without changing ob_get_level —
        // looping would spin forever. Guard with the REMOVABLE flag and
        // break on a false return so response delivery never hangs.
        while (\ob_get_level() > 0) {
            $status = \function_exists('ob_get_status') ? \ob_get_status() : null;
            if (!\is_array($status)
                || !isset($status['flags'])
                || (((int) $status['flags']) & \PHP_OUTPUT_HANDLER_REMOVABLE) === 0
            ) {
                break;
            }
            if (!\ob_end_flush()) {
                break;
            }
        }
    }

    public function disableAbortKills(): void
    {
        if (\function_exists('ignore_user_abort')) {
            \ignore_user_abort(true);
        }
    }

    public function write(string $bytes): void
    {
        echo $bytes;
    }

    public function flush(): void
    {
        if (\function_exists('flush')) {
            \flush();
        }
    }

    public function connectionAborted(): bool
    {
        if (\function_exists('connection_aborted')) {
            return (bool) \connection_aborted();
        }
        return false;
    }

    public function registerShutdownHandler(callable $fn): void
    {
        if (\function_exists('register_shutdown_function')) {
            \register_shutdown_function($fn);
        }
    }
}
