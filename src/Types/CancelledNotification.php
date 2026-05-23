<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2024 Logiscape LLC <https://logiscape.com>
 *
 * Based on the Python SDK for the Model Context Protocol
 * https://github.com/modelcontextprotocol/python-sdk
 *
 * PHP conversion developed by:
 * - Josh Abbott
 * - Claude 3.5 Sonnet (Anthropic AI model)
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
 * Filename: Types/CancelledNotification.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Notification for cancelled requests.
 *
 * Stores `requestId` and `reason` as direct properties for ergonomic access
 * by both the send-side caller (who builds the notification) and the
 * receive-side dispatch path (which reads them off the typed instance).
 *
 * The wire form per spec carries those values inside `params.requestId` /
 * `params.reason`, so the constructor also populates the parent
 * `Notification::$params` slot. That is what `BaseSession::sendNotification()`
 * actually serializes onto the JSON-RPC frame; without it the wire
 * notification would be missing the required `requestId` and the receiver
 * would silently drop it.
 */
class CancelledNotification extends Notification {
    public function __construct(
        public readonly RequestId $requestId,
        public ?string $reason = null,
    ) {
        $params = new NotificationParams();
        $params->requestId = $requestId->getValue();
        if ($reason !== null) {
            $params->reason = $reason;
        }
        parent::__construct('notifications/cancelled', $params);
    }

    public function validate(): void {
        parent::validate();
        $this->requestId->validate();
    }
}