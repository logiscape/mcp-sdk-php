<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Task status values (experimental).
 */
class TaskStatus {
    public const WORKING = 'working';
    public const INPUT_REQUIRED = 'input_required';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    public const ALL = [
        self::WORKING,
        self::INPUT_REQUIRED,
        self::COMPLETED,
        self::FAILED,
        self::CANCELLED,
    ];

    public static function isValid(string $status): bool {
        return in_array($status, self::ALL, true);
    }
}
