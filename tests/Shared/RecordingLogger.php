<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Filename: tests/Shared/RecordingLogger.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Shared;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 spy logger: records every log call so tests can assert on emitted
 * levels and messages (e.g. the SEP-2596 deprecation warnings).
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }

    /** @return list<string> Messages logged at warning level, in order. */
    public function warnings(): array
    {
        $messages = [];
        foreach ($this->records as $record) {
            if ($record['level'] === 'warning') {
                $messages[] = $record['message'];
            }
        }
        return $messages;
    }
}
