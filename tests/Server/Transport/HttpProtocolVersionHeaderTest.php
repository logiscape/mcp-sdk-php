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
 */

declare(strict_types=1);

namespace Mcp\Tests\Server\Transport;

use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Server\Transport\HttpServerTransport;
use PHPUnit\Framework\TestCase;

/**
 * Pins down MCP-Protocol-Version header validation on incoming HTTP
 * requests (spec 2025-11-25 §Transports).
 *
 * The normative requirement is: a present-but-unsupported value MUST
 * be answered with HTTP 400. Absence is handled leniently per the
 * spec's backwards-compatibility clause — the SDK's session carries
 * the negotiated version, so no fallback fabrication is required.
 */
final class HttpProtocolVersionHeaderTest extends TestCase
{
    private function createRequest(string $method, array $headers = [], ?string $body = null): HttpMessage
    {
        $msg = new HttpMessage($body);
        $msg->setMethod($method);
        $msg->setUri('/mcp');
        foreach ($headers as $name => $value) {
            $msg->setHeader($name, $value);
        }
        return $msg;
    }

    private function initBody(int $id = 1): string
    {
        return (string) \json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ]);
    }

    /**
     * Assert a rejected-for-version response shape: 400, JSON-RPC error
     * body, rejected value echoed in the message for client debugging.
     */
    private function assertUnsupportedVersionRejection(HttpMessage $response, string $rejectedValue): void
    {
        $this->assertSame(400, $response->getStatusCode());
        $body = \json_decode($response->getBody() ?? '', true);
        $this->assertIsArray($body);
        $this->assertSame('2.0', $body['jsonrpc']);
        $this->assertNull($body['id']);
        $this->assertSame(-32600, $body['error']['code']);
        $this->assertStringContainsString(
            'MCP-Protocol-Version',
            $body['error']['message'],
        );
        $this->assertStringContainsString($rejectedValue, $body['error']['message']);
    }

    /**
     * Assert a response did NOT come from the version validator — the
     * request either proceeded to normal processing or failed for a
     * different reason. Distinguishes our 400 from any other outcome.
     */
    private function assertNotVersionRejection(HttpMessage $response): void
    {
        $status = $response->getStatusCode();
        if ($status !== 400) {
            // Anything other than 400 proves the version validator did
            // not reject this request.
            $this->assertNotSame(400, $status);
            return;
        }
        // A 400 might still be from a different gate (session id
        // required, empty body, etc.). Confirm it is NOT our version
        // rejection by checking the body marker.
        $body = \json_decode($response->getBody() ?? '', true);
        $message = \is_array($body) && isset($body['error']['message'])
            ? (string) $body['error']['message']
            : '';
        $this->assertStringNotContainsString(
            'Unsupported MCP-Protocol-Version',
            $message,
            'Expected non-version 400, got our version-rejection response: ' . $message,
        );
    }

    // ------------------------------------------------------------------
    // Present-and-unsupported → 400 (the core spec MUST)
    // ------------------------------------------------------------------

    public function testPostWithUnsupportedVersionReturns400(): void
    {
        $transport = new HttpServerTransport();
        $transport->start();

        $response = $transport->handleRequest($this->createRequest('POST', [
            'MCP-Protocol-Version' => '9999-99-99',
            'Content-Type' => 'application/json',
        ], $this->initBody()));

        $this->assertUnsupportedVersionRejection($response, '9999-99-99');
    }

    public function testGetWithUnsupportedVersionReturns400(): void
    {
        // SSE enabled so a GET can legitimately reach the transport.
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        $response = $transport->handleRequest($this->createRequest('GET', [
            'MCP-Protocol-Version' => '1999-01-01',
            'Accept' => 'text/event-stream',
        ]));

        $this->assertUnsupportedVersionRejection($response, '1999-01-01');
    }

    public function testDeleteWithUnsupportedVersionReturns400(): void
    {
        $transport = new HttpServerTransport();
        $transport->start();

        $response = $transport->handleRequest($this->createRequest('DELETE', [
            'MCP-Protocol-Version' => 'banana',
            'Mcp-Session-Id' => 'irrelevant',
        ]));

        $this->assertUnsupportedVersionRejection($response, 'banana');
    }

    /**
     * The spec's MUST applies regardless of method — even the initialize
     * POST itself must 400 on an unsupported header value, because the
     * normative language is about the value being invalid/unsupported,
     * not about the endpoint.
     */
    public function testInitializeWithUnsupportedVersionReturns400(): void
    {
        $transport = new HttpServerTransport();
        $transport->start();

        $response = $transport->handleRequest($this->createRequest('POST', [
            'MCP-Protocol-Version' => '2099-01-01',
            'Content-Type' => 'application/json',
        ], $this->initBody()));

        $this->assertUnsupportedVersionRejection($response, '2099-01-01');
    }

    /**
     * HTTP header names are case-insensitive. HttpMessage normalizes on
     * set/get, so both casings resolve to the same slot — this guards
     * against any future refactor accidentally comparing the raw name.
     */
    public function testLowercaseHeaderNameStillRejected(): void
    {
        $transport = new HttpServerTransport();
        $transport->start();

        $response = $transport->handleRequest($this->createRequest('POST', [
            'mcp-protocol-version' => '9999-99-99',
            'Content-Type' => 'application/json',
        ], $this->initBody()));

        $this->assertUnsupportedVersionRejection($response, '9999-99-99');
    }

    // ------------------------------------------------------------------
    // Present-and-supported → accepted (regression guard)
    // ------------------------------------------------------------------

    public function testSupportedLatestVersionIsAccepted(): void
    {
        $transport = new HttpServerTransport();
        $transport->start();

        $response = $transport->handleRequest($this->createRequest('POST', [
            'MCP-Protocol-Version' => '2025-11-25',
            'Content-Type' => 'application/json',
        ], $this->initBody()));

        $this->assertNotVersionRejection($response);
    }

    /**
     * Older supported versions must stay accepted — the SDK advertises
     * backward compatibility down to 2024-11-05, and the validator must
     * not narrow that window.
     */
    public function testOlderSupportedVersionIsAccepted(): void
    {
        $transport = new HttpServerTransport();
        $transport->start();

        $response = $transport->handleRequest($this->createRequest('POST', [
            'MCP-Protocol-Version' => '2024-11-05',
            'Content-Type' => 'application/json',
        ], $this->initBody()));

        $this->assertNotVersionRejection($response);
    }

    // ------------------------------------------------------------------
    // Absent header → lenient (backwards-compat preserved)
    // ------------------------------------------------------------------

    /**
     * Pre-2025-06-18 clients never sent this header. The SDK supports
     * those spec revisions, so absence must continue to pass through
     * without any 400.
     */
    public function testAbsentHeaderOnInitializeIsAccepted(): void
    {
        $transport = new HttpServerTransport();
        $transport->start();

        $response = $transport->handleRequest($this->createRequest('POST', [
            'Content-Type' => 'application/json',
        ], $this->initBody()));

        $this->assertNotVersionRejection($response);
    }

    /**
     * A fully-initialized session still accepts a follow-up POST with
     * no version header. The session's negotiated version remains
     * authoritative; there is no need to reject absence to satisfy the
     * spec (the SHOULD fallback applies only when the server has no
     * way to identify the version, which is not this SDK).
     */
    public function testAbsentHeaderOnFollowUpWithInitializedSessionIsAccepted(): void
    {
        $transport = new HttpServerTransport();
        $transport->start();

        // Drive initialize to create a session, then send a follow-up
        // POST with the session id but no version header.
        $initResponse = $transport->handleRequest($this->createRequest('POST', [
            'MCP-Protocol-Version' => '2025-11-25',
            'Content-Type' => 'application/json',
        ], $this->initBody()));
        $sessionId = $initResponse->getHeader('Mcp-Session-Id');
        // If the transport didn't surface a session id (SSE disabled, buffered),
        // use getLastUsedSession() directly.
        if ($sessionId === null) {
            $session = $transport->getLastUsedSession();
            $this->assertNotNull($session);
            $sessionId = $session->getId();
        }

        $followUp = $transport->handleRequest($this->createRequest('POST', [
            'Content-Type' => 'application/json',
            'Mcp-Session-Id' => $sessionId,
        ], (string) \json_encode([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping',
        ])));

        $this->assertNotVersionRejection($followUp);
    }
}
