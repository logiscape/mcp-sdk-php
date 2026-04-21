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

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Minimal PSR-3 logger used by the webclient when Monolog isn't installed.
 *
 * - Appends to a single log file (no rotation — this is the fallback; install
 *   Monolog for rotation, richer formatting, and multiple handlers)
 * - Mirrors every record into a shared buffer array so Bootstrap::bufferedLogs()
 *   can attach them to the JSON response for the current request.
 */
final class WebClientInlineLogger extends AbstractLogger
{
    private const LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private string $file;
    /** @var list<array{datetime: string, level: string, message: string, context: array<string, mixed>}> */
    private array $sink;

    /**
     * @param list<array{datetime: string, level: string, message: string, context: array<string, mixed>}> $sink
     */
    public function __construct(string $file, array &$sink)
    {
        $this->file = $file;
        $this->sink = &$sink;
    }

    public function log($level, $message, array $context = []): void
    {
        $levelName = strtoupper(is_string($level) ? $level : LogLevel::INFO);
        if (!isset(self::LEVELS[strtolower($levelName)])) {
            $levelName = 'INFO';
        }
        $rendered = $this->interpolate((string)$message, $context);
        $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');

        $this->sink[] = [
            'datetime' => $timestamp,
            'level' => $levelName,
            'message' => $rendered,
            'context' => $context,
        ];

        $line = sprintf(
            "[%s] mcp-web-client.%s: %s %s\n",
            $timestamp,
            $levelName,
            $rendered,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_SLASHES)
        );
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Replace `{placeholder}` tokens in the message with scalar values from context.
     *
     * @param array<string, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }
        $replace = [];
        foreach ($context as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                continue;
            }
            $replace['{' . $key . '}'] = (string)$value;
        }
        return strtr($message, $replace);
    }
}
