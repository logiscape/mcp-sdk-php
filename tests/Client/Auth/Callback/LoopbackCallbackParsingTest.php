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
 * Filename: tests/Client/Auth/Callback/LoopbackCallbackParsingTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client\Auth\Callback;

use Mcp\Client\Auth\Callback\AuthorizationCallbackResult;
use Mcp\Client\Auth\Callback\LoopbackCallbackHandler;
use Mcp\Client\Auth\OAuthException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for the LoopbackCallbackHandler request parsing (SEP-2468 rework).
 *
 * The handler must return the RAW callback parameters — including the iss
 * parameter and any error parameters — without interpreting them, so the
 * OAuthClient can validate iss against the expected issuer BEFORE acting on
 * error or code content. Only the state (CSRF) check stays in the handler.
 */
final class LoopbackCallbackParsingTest extends TestCase
{
    private const STATE = 'expected-state-123';

    private function parseCallback(string $requestLine): AuthorizationCallbackResult
    {
        $handler = new LoopbackCallbackHandler(0, 1, false);
        $method = new ReflectionMethod(LoopbackCallbackHandler::class, 'parseCallback');
        $method->setAccessible(true);

        $request = "GET {$requestLine} HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n";

        /** @var AuthorizationCallbackResult */
        return $method->invoke($handler, $request, self::STATE);
    }

    /**
     * A successful callback yields code and iss (form-urldecoded) plus the
     * raw parameter set.
     */
    public function testParsesCodeAndIss(): void
    {
        $result = $this->parseCallback(
            '/callback?code=abc123&state=' . self::STATE
            . '&iss=https%3A%2F%2Fas.example.com'
        );

        $this->assertSame('abc123', $result->code);
        $this->assertSame('https://as.example.com', $result->iss);
        $this->assertFalse($result->hasError());
        $this->assertSame(self::STATE, $result->params['state']);
    }

    /**
     * An error callback is returned RAW — no exception is thrown by the
     * handler, so the OAuthClient can validate iss before surfacing the
     * error parameters.
     */
    public function testErrorCallbackReturnedRawWithoutThrowing(): void
    {
        $result = $this->parseCallback(
            '/callback?error=access_denied&error_description=nope&state=' . self::STATE
            . '&iss=https%3A%2F%2Fattacker.example.com'
        );

        $this->assertNull($result->code);
        $this->assertTrue($result->hasError());
        $this->assertSame('access_denied', $result->params['error']);
        $this->assertSame('https://attacker.example.com', $result->iss);
    }

    /**
     * A callback without iss yields a null iss (the OAuthClient decides
     * whether that is acceptable based on the AS advertisement).
     */
    public function testMissingIssYieldsNull(): void
    {
        $result = $this->parseCallback('/callback?code=abc123&state=' . self::STATE);

        $this->assertSame('abc123', $result->code);
        $this->assertNull($result->iss);
    }

    /**
     * State mismatch still throws in the handler (CSRF protection is
     * independent of the deferred iss/error handling).
     */
    public function testStateMismatchThrows(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('State parameter mismatch');

        $this->parseCallback('/callback?code=abc123&state=wrong-state');
    }

    /**
     * A request that is not a parseable GET is rejected.
     */
    public function testInvalidRequestThrows(): void
    {
        $handler = new LoopbackCallbackHandler(0, 1, false);
        $method = new ReflectionMethod(LoopbackCallbackHandler::class, 'parseCallback');
        $method->setAccessible(true);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Invalid callback request');

        $method->invoke($handler, "garbage\r\n\r\n", self::STATE);
    }
}
