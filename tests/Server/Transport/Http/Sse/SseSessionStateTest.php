<?php

declare(strict_types=1);

namespace Mcp\Tests\Server\Transport\Http\Sse;

use Mcp\Server\Transport\Http\HttpSession;
use Mcp\Server\Transport\Http\Sse\SseFrame;
use Mcp\Server\Transport\Http\Sse\SseSessionState;
use Mcp\Server\Transport\Http\Sse\StreamRegistry;
use PHPUnit\Framework\TestCase;

/**
 * SseSessionState owns persistence of the SSE log + registry into
 * HttpSession metadata under two sibling keys. These tests anchor that
 * contract so future transport changes don't silently drop state.
 */
final class SseSessionStateTest extends TestCase
{
    /**
     * A fresh session has no SSE metadata; loadFrom returns empty state.
     */
    public function testLoadFromEmptySessionReturnsDefaults(): void
    {
        $session = new HttpSession();
        $state = SseSessionState::loadFrom($session);
        $this->assertSame(0, $state->getLog()->totalCount());
        $this->assertSame([], $state->getRegistry()->all());
    }

    /**
     * saveTo + loadFrom preserves registry records and log entries, so
     * a session can be persisted between HTTP requests (the typical
     * PHP web-hosting lifecycle) and resumed on the next one.
     */
    public function testSaveAndLoadRoundtripsViaHttpSession(): void
    {
        $session = new HttpSession();
        $state = SseSessionState::createEmpty();
        $state->getRegistry()->open('s1', StreamRegistry::KIND_POST, 'req-1');
        $state->getLog()->append('s1', 1, new SseFrame('s1:1', null, null, 'hello'));
        $state->saveTo($session);

        $reloaded = SseSessionState::loadFrom($session);
        $rec = $reloaded->getRegistry()->find('s1');
        $this->assertNotNull($rec);
        $this->assertSame('req-1', $rec['originatingRequestId']);

        $out = $reloaded->getLog()->replaySince('s1', 0);
        $this->assertCount(1, $out);
        $this->assertSame('hello', $out[0]['frame']->data);
    }

    /**
     * forgetStream removes both the registry entry and the log entries
     * for a stream, which is the cleanup path after a stream completes
     * and the client has reconnected past its final response.
     */
    public function testForgetStreamRemovesLogAndRegistryEntries(): void
    {
        $state = SseSessionState::createEmpty();
        $state->getRegistry()->open('s1', StreamRegistry::KIND_POST, 1);
        $state->getLog()->append('s1', 1, new SseFrame('s1:1', null, null, 'x'));

        $state->forgetStream('s1');

        $this->assertNull($state->getRegistry()->find('s1'));
        $this->assertSame(0, $state->getLog()->countForStream('s1'));
    }

    /**
     * The two metadata keys must match the published constants — other
     * code (HttpServerTransport) reads and writes them directly.
     */
    public function testMetadataKeysMatchPublishedConstants(): void
    {
        $this->assertSame('mcp_stream_events', SseSessionState::META_KEY_EVENTS);
        $this->assertSame('mcp_streams', SseSessionState::META_KEY_STREAMS);
    }
}
