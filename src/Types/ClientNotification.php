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
 * Filename: Types/ClientNotification.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Union type for client notifications:
 * type ClientNotification =
 *   | CancelledNotification
 *   | InitializedNotification
 *   | ProgressNotification
 *   | RootsListChangedNotification
 *
 * This acts as a root model for that union and provides a factory method
 * similar to ClientRequest.
 */
class ClientNotification implements McpModel {
    use ExtraFieldsTrait;

    private Notification $notification;

    public function __construct(Notification $notification) {
        if (!(
            $notification instanceof CancelledNotification ||
            $notification instanceof InitializedNotification ||
            $notification instanceof ProgressNotification ||
            $notification instanceof RootsListChangedNotification
        )) {
            throw new \InvalidArgumentException('Invalid client notification type');
        }
        $this->notification = $notification;
    }

    /**
     * Factory method to create a ClientNotification from method and params.
     *
     * @param string $method The notification method string (e.g., 'notifications/cancelled')
     * @param array<string, mixed>|null $params The notification parameters from the JSON-RPC message
     */
    public static function fromMethodAndParams(string $method, ?array $params): self {
        $params = $params ?? [];

        return match ($method) {
            'notifications/cancelled' => self::createCancelledNotification($params),
            'notifications/initialized' => self::createInitializedNotification($params),
            'notifications/progress' => self::createProgressNotification($params),
            'notifications/roots/list_changed' => self::createRootsListChangedNotification($params),
            default => throw new \InvalidArgumentException("Unknown client notification method: $method"),
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function createCancelledNotification(array $params): self {
        if (!isset($params['requestId'])) {
            throw new \InvalidArgumentException('CancelledNotification requires "requestId"');
        }

        // requestId: RequestId (string|number)
        // reason?: string
        $requestIdValue = $params['requestId'];
        $requestId = new RequestId($requestIdValue);

        $reason = $params['reason'] ?? null;

        $notification = new CancelledNotification($requestId, $reason);

        // Forward any spec-extension fields beyond the two known keys onto
        // the inner NotificationParams so handlers see the full wire payload.
        // The constructor has already populated $notification->params with
        // requestId / reason, so this just augments that object. _meta is
        // normalized into a Meta object by applyWireFields().
        $notification->params->applyWireFields($params, ['requestId', 'reason']);

        return new self($notification);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function createInitializedNotification(array $params): self {
        // No params expected
        $notification = new InitializedNotification();

        // If there are extra fields, store them on the notification
        foreach ($params as $k => $v) {
            $notification->$k = $v;
        }

        return new self($notification);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function createProgressNotification(array $params): self {
        // progressToken: string|number (required)
        if (!isset($params['progressToken'])) {
            throw new \InvalidArgumentException('ProgressNotification requires "progressToken"');
        }

        // progress: number (required)
        if (!isset($params['progress'])) {
            throw new \InvalidArgumentException('ProgressNotification requires "progress"');
        }

        $progressTokenValue = $params['progressToken'];
        $progressToken = new ProgressToken($progressTokenValue);

        $progress = $params['progress'];
        if (!is_float($progress) && !is_int($progress)) {
            throw new \InvalidArgumentException('Progress must be a number');
        }
        $progress = (float)$progress;

        $total = null;
        if (isset($params['total'])) {
            $t = $params['total'];
            if (!is_float($t) && !is_int($t)) {
                throw new \InvalidArgumentException('Total must be a number if present');
            }
            $total = (float)$t;
        }

        $progressParams = new ProgressNotificationParams(
            progressToken: $progressToken,
            progress: $progress,
            total: $total
        );

        // Extra fields not in the known set are forwarded; _meta is normalized
        // into a Meta object by applyWireFields().
        $progressParams->applyWireFields($params, ['progressToken', 'progress', 'total']);

        return new self(new ProgressNotification($progressParams));
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function createRootsListChangedNotification(array $params): self {
        $notification = new RootsListChangedNotification();
        foreach ($params as $k => $v) {
            $notification->$k = $v;
        }
        return new self($notification);
    }

    public function validate(): void {
        $this->notification->validate();
    }

    public function getNotification(): Notification {
        return $this->notification;
    }

    public function jsonSerialize(): mixed {
        $data = $this->notification->jsonSerialize();
        return array_merge((array)$data, $this->extraFields);
    }
}