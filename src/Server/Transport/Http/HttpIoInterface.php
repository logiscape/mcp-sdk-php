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
 * Filename: Server/Transport/Http/HttpIoInterface.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport\Http;

/**
 * Abstraction over the PHP SAPI side effects the HTTP server runner and
 * transport need to emit a response.
 *
 * Splitting these out of HttpServerRunner / HttpServerTransport lets the
 * runner be embedded in non-standard hosts (Symfony / Slim / FrankenPHP /
 * RoadRunner / tests) by injecting a custom implementation. The default
 * implementation, NativePhpIo, wraps the native PHP functions used on a
 * cPanel/Apache/FPM host; BufferedIo captures bytes for tests and offline
 * integrations.
 *
 * Implementations are expected to be single-request-scoped: a fresh
 * instance per HTTP request keeps shutdown-handler state from leaking
 * between requests in long-running hosts.
 */
interface HttpIoInterface
{
    /**
     * Set the HTTP response status line (http_response_code).
     */
    public function sendStatus(int $code): void;

    /**
     * Send an HTTP response header (header("$name: $value")).
     */
    public function sendHeader(string $name, string $value): void;

    /**
     * Whether headers have already been flushed to the wire.
     */
    public function headersSent(): bool;

    /**
     * Drain active output buffers so subsequent writes reach the client
     * promptly. Implementations should break on non-removable buffers
     * (framework- or SAPI-owned) so they never hang.
     */
    public function drainOutputBuffers(): void;

    /**
     * Disable the SAPI's user-abort short-circuit (ignore_user_abort) so a
     * mid-handler disconnect does not kill the PHP process before it
     * persists final state.
     */
    public function disableAbortKills(): void;

    /**
     * Write raw bytes to the response body (echo).
     */
    public function write(string $bytes): void;

    /**
     * Flush buffered output to the client (flush()).
     */
    public function flush(): void;

    /**
     * Whether the client has disconnected. Implementations that cannot
     * detect this (e.g. buffered/test hosts) should return false.
     */
    public function connectionAborted(): bool;

    /**
     * Register a handler to run at request shutdown. Used by the streaming
     * path to synthesize an error frame if the handler terminates fatally.
     */
    public function registerShutdownHandler(callable $fn): void;
}
