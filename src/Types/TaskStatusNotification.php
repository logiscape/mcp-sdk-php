<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Notification of task status change (experimental).
 */
class TaskStatusNotification extends Notification {
    public function __construct(
        ?NotificationParams $params = null,
    ) {
        parent::__construct('notifications/tasks/status', $params);
    }
}
