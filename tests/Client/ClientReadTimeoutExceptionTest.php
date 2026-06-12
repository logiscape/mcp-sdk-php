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
 * Filename: tests/Client/ClientReadTimeoutExceptionTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client;

use Mcp\Client\ClientSession;
use Mcp\Client\Transport\ReadTimeoutException;
use Mcp\Shared\MemoryStream;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the typed read-timeout exception (WS2 review cleanup).
 *
 * ClientSession's read paths throw ReadTimeoutException — a
 * RuntimeException subclass with the exact pre-existing messages for BC —
 * so negotiate()'s silent-legacy-server detection classifies timeouts via
 * instanceof rather than message-prefix sniffing.
 */
final class ClientReadTimeoutExceptionTest extends TestCase
{
    /**
     * An idle read stream trips the configured read timeout with the
     * typed exception, keeping the historical message intact.
     */
    public function testIdleStreamThrowsTypedReadTimeout(): void
    {
        $session = new ClientSession(new MemoryStream(), new MemoryStream(), readTimeout: 0.05);
        $session->negotiate('modern'); // forced: no probe traffic needed

        try {
            $session->listTools();
            $this->fail('Expected ReadTimeoutException');
        } catch (ReadTimeoutException $e) {
            $this->assertInstanceOf(RuntimeException::class, $e);
            $this->assertStringStartsWith('Timed out waiting for response', $e->getMessage());
        }
    }
}
