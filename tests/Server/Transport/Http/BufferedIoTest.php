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
 */

declare(strict_types=1);

namespace Mcp\Tests\Server\Transport\Http;

use Mcp\Server\Transport\Http\BufferedIo;
use Mcp\Server\Transport\Http\HttpIoInterface;
use PHPUnit\Framework\TestCase;

/**
 * BufferedIo is the test-support / embedder implementation of the
 * HttpIoInterface abstraction. These tests pin down the capture semantics
 * so future changes to the transport's streaming wiring have a stable
 * target to assert against.
 */
final class BufferedIoTest extends TestCase
{
    /**
     * Sanity check: BufferedIo is a contract implementation, so anywhere
     * the SDK accepts an HttpIoInterface it must accept this too.
     */
    public function testImplementsHttpIoInterface(): void
    {
        $io = new BufferedIo();
        $this->assertInstanceOf(HttpIoInterface::class, $io);
    }

    /**
     * Each write appends to the capture buffer and bumps the counter so
     * tests can distinguish "many small frames" from "one coalesced blob"
     * without parsing the buffer.
     */
    public function testWriteAccumulatesBytesAndWriteCount(): void
    {
        $io = new BufferedIo();

        $io->write("hello ");
        $io->write("world");

        $this->assertSame("hello world", $io->buffer);
        $this->assertSame(2, $io->writes);
    }

    /**
     * Status and headers are recorded in order. Duplicates are preserved
     * (Set-Cookie-style headers).
     */
    public function testStatusAndHeadersAreCapturedInOrder(): void
    {
        $io = new BufferedIo();

        $io->sendStatus(200);
        $io->sendHeader('Content-Type', 'text/event-stream');
        $io->sendHeader('Cache-Control', 'no-cache, no-transform');
        $io->sendHeader('X-Duplicate', 'a');
        $io->sendHeader('X-Duplicate', 'b');

        $this->assertSame(200, $io->status);
        $this->assertSame(
            [
                ['Content-Type', 'text/event-stream'],
                ['Cache-Control', 'no-cache, no-transform'],
                ['X-Duplicate', 'a'],
                ['X-Duplicate', 'b'],
            ],
            $io->headers,
        );
        $this->assertSame(['a', 'b'], $io->headerValues('x-duplicate'));
    }

    /**
     * Drain/flush/abort-kill toggles should record without touching the
     * body buffer — they are structural events the streaming path emits
     * before the first frame.
     */
    public function testStructuralSideEffectsAreCountedIndependently(): void
    {
        $io = new BufferedIo();

        $io->drainOutputBuffers();
        $io->drainOutputBuffers();
        $io->disableAbortKills();
        $io->flush();
        $io->flush();
        $io->flush();

        $this->assertSame(2, $io->outputBufferDrains);
        $this->assertTrue($io->abortKillsDisabled);
        $this->assertSame(3, $io->flushes);
        $this->assertSame('', $io->buffer);
        $this->assertSame(0, $io->writes);
    }

    /**
     * connectionAborted defaults to false (tests assume the client stays
     * connected) but must honor an explicit flip so resume/abort paths
     * can be exercised.
     */
    public function testConnectionAbortedDefaultsToFalseAndIsToggleable(): void
    {
        $io = new BufferedIo();
        $this->assertFalse($io->connectionAborted());

        $io->aborted = true;
        $this->assertTrue($io->connectionAborted());
    }

    /**
     * Shutdown handlers are queued, not executed at registration. The
     * streaming fatal-safety-net registers one on begin; tests trigger
     * it via runShutdownHandlers() to verify the synthesized error frame
     * without actually fatal-ing the PHP process.
     */
    public function testShutdownHandlersAreQueuedAndRunOnDemandInOrder(): void
    {
        $io = new BufferedIo();
        $calls = [];

        $io->registerShutdownHandler(function () use (&$calls): void {
            $calls[] = 'first';
        });
        $io->registerShutdownHandler(function () use (&$calls): void {
            $calls[] = 'second';
        });

        $this->assertSame([], $calls, 'handlers must not run at registration time');
        $this->assertCount(2, $io->shutdownHandlers);

        $io->runShutdownHandlers();

        $this->assertSame(['first', 'second'], $calls);
    }
}
