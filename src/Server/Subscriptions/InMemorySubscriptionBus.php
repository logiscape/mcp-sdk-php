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
 * Filename: Server/Subscriptions/InMemorySubscriptionBus.php
 */

declare(strict_types=1);

namespace Mcp\Server\Subscriptions;

/**
 * In-process subscription bus for tests and single-process embedders
 * (long-running runtimes like FrankenPHP/RoadRunner where publisher and
 * listen loop share memory). Has no cross-process visibility — multi-
 * process deployments need {@see FileSubscriptionBus} or a custom
 * implementation.
 */
final class InMemorySubscriptionBus implements SubscriptionBusInterface
{
    /** @var list<array{method: string, params: array<string, mixed>}> */
    private array $events = [];

    public function publish(string $method, array $params = []): void
    {
        $this->events[] = ['method' => $method, 'params' => $params];
    }

    public function cursor(): int
    {
        return count($this->events);
    }

    public function pollSince(int $cursor): array
    {
        $cursor = max(0, min($cursor, count($this->events)));
        return [
            'cursor' => count($this->events),
            'events' => array_slice($this->events, $cursor),
        ];
    }
}
