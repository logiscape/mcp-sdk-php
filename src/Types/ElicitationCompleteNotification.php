<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Notification sent when a URL elicitation completes.
 *
 * params: { elicitationId: string }
 */
class ElicitationCompleteNotification extends Notification {
    public function __construct(
        public readonly string $elicitationId,
    ) {
        $params = new NotificationParams();
        $params->elicitationId = $elicitationId;
        parent::__construct('notifications/elicitation/complete', $params);
    }
}
