<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Filename: tests/Server/Subscriptions/FileSubscriptionBusTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server\Subscriptions;

use Mcp\Server\Subscriptions\FileSubscriptionBus;
use Mcp\Server\Subscriptions\InMemorySubscriptionBus;
use PHPUnit\Framework\TestCase;

/**
 * The file-backed subscriptions/listen event bus: cursor semantics (only
 * events after the cursor are delivered), cross-instance visibility (the
 * multi-process contract), truncation re-anchoring, and the in-memory
 * twin used by tests/single-process embedders.
 */
final class FileSubscriptionBusTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp-bus-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . DIRECTORY_SEPARATOR . 'subscription-events.jsonl');
        @rmdir($this->dir);
    }

    public function testCursorSkipsEarlierEvents(): void
    {
        $bus = new FileSubscriptionBus($this->dir);
        $bus->publish('notifications/tools/list_changed');
        $cursor = $bus->cursor();
        $bus->publish('notifications/prompts/list_changed');

        $poll = $bus->pollSince($cursor);
        $this->assertCount(1, $poll['events']);
        $this->assertSame('notifications/prompts/list_changed', $poll['events'][0]['method']);

        // The advanced cursor sees nothing new.
        $again = $bus->pollSince($poll['cursor']);
        $this->assertSame([], $again['events']);
    }

    public function testCrossInstanceVisibility(): void
    {
        // Publisher and listener are different processes in real
        // deployments; two instances over the same directory model that.
        $listener = new FileSubscriptionBus($this->dir);
        $cursor = $listener->cursor();

        $publisher = new FileSubscriptionBus($this->dir);
        $publisher->publish('notifications/resources/updated', ['uri' => 'test://watched-resource']);

        $poll = $listener->pollSince($cursor);
        $this->assertCount(1, $poll['events']);
        $this->assertSame('test://watched-resource', $poll['events'][0]['params']['uri']);
    }

    public function testTruncationReAnchors(): void
    {
        $bus = new FileSubscriptionBus($this->dir, maxBytes: 1);
        $bus->publish('notifications/tools/list_changed');
        $staleCursor = $bus->cursor();
        // Over the cap: the next publish truncates to a fresh log.
        $bus->publish('notifications/prompts/list_changed');

        $poll = $bus->pollSince($staleCursor + 1000);
        $this->assertCount(
            1,
            $poll['events'],
            'A cursor beyond the shrunken file re-anchors to the start instead of silently stalling'
        );
    }

    public function testInMemoryBusMirrorsContract(): void
    {
        $bus = new InMemorySubscriptionBus();
        $bus->publish('notifications/tools/list_changed');
        $cursor = $bus->cursor();
        $bus->publish('notifications/prompts/list_changed', ['x' => 1]);

        $poll = $bus->pollSince($cursor);
        $this->assertCount(1, $poll['events']);
        $this->assertSame(['x' => 1], $poll['events'][0]['params']);
        $this->assertSame([], $bus->pollSince($poll['cursor'])['events']);
    }
}
