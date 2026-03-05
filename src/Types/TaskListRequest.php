<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Request to list tasks (experimental). Supports pagination.
 */
class TaskListRequest extends PaginatedRequest {
    public function __construct(?string $cursor = null) {
        parent::__construct('tasks/list', $cursor);
    }
}
