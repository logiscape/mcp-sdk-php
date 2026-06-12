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
 *
 * Filename: Server/Subscriptions/SubscriptionBusInterface.php
 */

declare(strict_types=1);

namespace Mcp\Server\Subscriptions;

/**
 * Cross-request event source for the `subscriptions/listen` channel
 * (SEP-2575, revision 2026-07-28).
 *
 * On typical PHP hosting every HTTP request runs in its own process, so a
 * handler that changes the tool/prompt/resource surface cannot hand a
 * list_changed notification to the process holding a listen stream open in
 * memory. Publishers append change events to a bus; the listen loop polls
 * it and forwards matching events onto the open stream.
 *
 * Events are arrays:
 *   ['method' => 'notifications/tools/list_changed', 'params' => [...]]
 * with `params.uri` set for notifications/resources/updated.
 */
interface SubscriptionBusInterface
{
    /**
     * Append a change event.
     *
     * @param string $method The notification method
     *        (notifications/{tools,prompts,resources}/list_changed or
     *        notifications/resources/updated)
     * @param array<string, mixed> $params Notification params (without
     *        _meta; the listen loop stamps the subscriptionId)
     */
    public function publish(string $method, array $params = []): void;

    /**
     * The current end-of-stream cursor. A listen loop captures this when
     * the stream opens so only events published afterwards are delivered.
     */
    public function cursor(): int;

    /**
     * Events published after the given cursor, with the advanced cursor.
     *
     * @return array{cursor: int, events: list<array{method: string, params: array<string, mixed>}>}
     */
    public function pollSince(int $cursor): array;
}
