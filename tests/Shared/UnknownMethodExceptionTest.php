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
 * Filename: tests/Shared/UnknownMethodExceptionTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Shared;

use Mcp\Shared\UnknownMethodException;
use Mcp\Types\ClientRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the typed unknown-method exception (WS2 review cleanup).
 *
 * ClientRequest::fromMethodAndParams() throws the dedicated
 * UnknownMethodException for unrecognized methods so
 * ServerSession::answerMalformedRequest() can route -32601 vs -32602 via
 * instanceof instead of message-string sniffing. The exception stays an
 * InvalidArgumentException with the exact pre-existing message for BC.
 */
final class UnknownMethodExceptionTest extends TestCase
{
    /**
     * Unknown methods throw the typed exception with the unchanged
     * message, and the type remains an InvalidArgumentException.
     */
    public function testUnknownClientMethodThrowsTypedException(): void
    {
        try {
            ClientRequest::fromMethodAndParams('bogus/method', []);
            $this->fail('Expected UnknownMethodException');
        } catch (UnknownMethodException $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertSame('Unknown client request method: bogus/method', $e->getMessage());
        }
    }

    /**
     * Known methods with malformed params keep throwing the plain
     * InvalidArgumentException — NOT the unknown-method type — so the
     * -32602 path is unaffected.
     */
    public function testMalformedKnownMethodIsNotUnknownMethod(): void
    {
        try {
            ClientRequest::fromMethodAndParams('tools/call', []); // missing required "name"
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotInstanceOf(UnknownMethodException::class, $e);
        }
    }
}
