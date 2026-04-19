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
 * Filename: Server/Transport/Http/BufferedIo.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport\Http;

/**
 * In-memory HttpIoInterface implementation that captures every write,
 * status, header, and shutdown handler for inspection. Used by tests and
 * by downstream integrators who want to drive the MCP server runner in a
 * non-SAPI context (worker, CLI batch, synthetic request).
 *
 * No native PHP SAPI functions are called from this class.
 */
final class BufferedIo implements HttpIoInterface
{
    public string $buffer = '';
    public int $writes = 0;
    public int $flushes = 0;
    public ?int $status = null;

    /** @var array<int, array{string, string}> */
    public array $headers = [];

    public bool $headersSent = false;
    public bool $abortKillsDisabled = false;
    public int $outputBufferDrains = 0;

    /** @var array<int, callable> */
    public array $shutdownHandlers = [];

    public bool $aborted = false;

    public function sendStatus(int $code): void
    {
        $this->status = $code;
    }

    public function sendHeader(string $name, string $value): void
    {
        $this->headers[] = [$name, $value];
    }

    public function headersSent(): bool
    {
        return $this->headersSent;
    }

    public function drainOutputBuffers(): void
    {
        $this->outputBufferDrains++;
    }

    public function disableAbortKills(): void
    {
        $this->abortKillsDisabled = true;
    }

    public function write(string $bytes): void
    {
        $this->buffer .= $bytes;
        $this->writes++;
    }

    public function flush(): void
    {
        $this->flushes++;
    }

    public function connectionAborted(): bool
    {
        return $this->aborted;
    }

    public function registerShutdownHandler(callable $fn): void
    {
        $this->shutdownHandlers[] = $fn;
    }

    /**
     * Run every registered shutdown handler in registration order. Tests
     * exercising the fatal-safety-net path call this directly.
     */
    public function runShutdownHandlers(): void
    {
        foreach ($this->shutdownHandlers as $fn) {
            $fn();
        }
    }

    /**
     * Retrieve header values sent for a given name, case-insensitively.
     *
     * @return array<int, string>
     */
    public function headerValues(string $name): array
    {
        $out = [];
        $needle = \strtolower($name);
        foreach ($this->headers as [$key, $value]) {
            if (\strtolower($key) === $needle) {
                $out[] = $value;
            }
        }
        return $out;
    }
}
