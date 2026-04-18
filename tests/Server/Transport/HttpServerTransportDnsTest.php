<?php

declare(strict_types=1);

namespace Mcp\Tests\Server\Transport;

use Mcp\Server\Transport\HttpServerTransport;
use Mcp\Server\Transport\Http\HttpMessage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DNS rebinding protection in HttpServerTransport.
 *
 * The MCP spec requires servers to validate the Origin header on all incoming
 * connections and respond with HTTP 403 if the Origin is present and invalid.
 *
 * Protection is opt-in via the 'allowed_origins' config option (a list of allowed
 * hostnames). McpServer::runHttp() auto-enables this for localhost servers,
 * matching the pattern used by the TypeScript and Python SDKs post CVE-2025-66414.
 */
final class HttpServerTransportDnsTest extends TestCase
{
    /**
     * Helper: create an HttpMessage with the given method, headers, and body.
     */
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

    private function initBody(): string
    {
        return '{"jsonrpc":"2.0","method":"initialize","id":1,"params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}';
    }

    // -----------------------------------------------------------------------
    // With allowed_origins configured (protection enabled)
    // -----------------------------------------------------------------------

    /**
     * Requests with a non-allowed Origin hostname must be rejected with 403.
     */
    public function testRejectsNonAllowedOriginHostname(): void
    {
        $transport = new HttpServerTransport([
            'allowed_origins' => ['localhost', '127.0.0.1', '::1'],
        ]);

        $request = $this->createRequest('POST', [
            'origin' => 'http://evil.example.com',
            'content-type' => 'application/json',
        ], '{"jsonrpc":"2.0","method":"initialize","id":1}');

        $response = $transport->handleRequest($request);

        $this->assertEquals(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertEquals('2.0', $body['jsonrpc']);
        $this->assertStringContainsString('Origin not allowed', $body['error']['message']);
    }

    /**
     * Requests with an allowed localhost Origin should pass validation (port-agnostic).
     */
    public function testAllowsLocalhostOriginAnyPort(): void
    {
        $transport = new HttpServerTransport([
            'allowed_origins' => ['localhost', '127.0.0.1', '::1'],
        ]);

        $request = $this->createRequest('POST', [
            'origin' => 'http://localhost:9999',
            'content-type' => 'application/json',
        ], $this->initBody());

        $response = $transport->handleRequest($request);
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    /**
     * 127.0.0.1 Origin should pass validation regardless of port.
     */
    public function testAllows127OriginAnyPort(): void
    {
        $transport = new HttpServerTransport([
            'allowed_origins' => ['localhost', '127.0.0.1', '::1'],
        ]);

        $request = $this->createRequest('POST', [
            'origin' => 'http://127.0.0.1:8080',
            'content-type' => 'application/json',
        ], $this->initBody());

        $response = $transport->handleRequest($request);
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    /**
     * IPv6 localhost Origin should pass validation.
     */
    public function testAllowsIpv6LocalhostOrigin(): void
    {
        $transport = new HttpServerTransport([
            'allowed_origins' => ['localhost', '127.0.0.1', '::1'],
        ]);

        $request = $this->createRequest('POST', [
            'origin' => 'http://[::1]:3000',
            'content-type' => 'application/json',
        ], $this->initBody());

        $response = $transport->handleRequest($request);
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    /**
     * Allowlist entries that include IPv6 brackets (e.g. '[::1]') must still match
     * requests with Origin: http://[::1]:port. The request hostname is normalized
     * by stripping brackets; configured entries must receive the same normalization
     * so users who follow a bracketed example do not silently reject valid origins.
     */
    public function testAllowsBracketedIpv6InAllowlist(): void
    {
        $transport = new HttpServerTransport([
            'allowed_origins' => ['[::1]'],
        ]);

        $request = $this->createRequest('POST', [
            'origin' => 'http://[::1]:3000',
            'content-type' => 'application/json',
        ], $this->initBody());

        $response = $transport->handleRequest($request);
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    /**
     * Allowlist entries with uppercase or mixed-case hostnames must match requests
     * whose Origin uses the same hostname in any case. Hostname matching is
     * case-insensitive per RFC 3986, and the request side is already lowercased.
     */
    public function testAllowsUppercaseHostnameInAllowlist(): void
    {
        $transport = new HttpServerTransport([
            'allowed_origins' => ['LOCALHOST'],
        ]);

        $request = $this->createRequest('POST', [
            'origin' => 'http://localhost:9999',
            'content-type' => 'application/json',
        ], $this->initBody());

        $response = $transport->handleRequest($request);
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    /**
     * Requests with no Origin header should pass even when protection is enabled.
     * Non-browser MCP clients typically don't send Origin headers.
     */
    public function testAllowsMissingOriginWhenProtectionEnabled(): void
    {
        $transport = new HttpServerTransport([
            'allowed_origins' => ['localhost', '127.0.0.1', '::1'],
        ]);

        $request = $this->createRequest('POST', [
            'content-type' => 'application/json',
        ], $this->initBody());

        $response = $transport->handleRequest($request);
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Without allowed_origins (protection disabled — default at transport level)
    // -----------------------------------------------------------------------

    /**
     * Without allowed_origins configured, any Origin should be accepted.
     * This ensures remote/web-hosted deployments work when using the transport
     * directly without McpServer's auto-configuration.
     */
    public function testAllowsAnyOriginWhenNotConfigured(): void
    {
        $transport = new HttpServerTransport(); // No allowed_origins

        $request = $this->createRequest('POST', [
            'origin' => 'https://mcp.example.com',
            'content-type' => 'application/json',
        ], $this->initBody());

        $response = $transport->handleRequest($request);
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Remote deployment scenario (non-localhost allowed hostname)
    // -----------------------------------------------------------------------

    /**
     * A remote deployment with a configured domain hostname should accept
     * matching requests and reject non-matching ones.
     */
    public function testRemoteDeploymentAllowsConfiguredDomain(): void
    {
        $transport = new HttpServerTransport([
            'allowed_origins' => ['mcp.example.com'],
        ]);

        // Matching hostname should pass (any port)
        $request = $this->createRequest('POST', [
            'origin' => 'https://mcp.example.com',
            'content-type' => 'application/json',
        ], $this->initBody());

        $response = $transport->handleRequest($request);
        $this->assertNotEquals(403, $response->getStatusCode());

        // Non-matching hostname should be rejected
        $evilRequest = $this->createRequest('POST', [
            'origin' => 'https://evil.example.com',
            'content-type' => 'application/json',
        ], '{"jsonrpc":"2.0","method":"initialize","id":1}');

        $evilResponse = $transport->handleRequest($evilRequest);
        $this->assertEquals(403, $evilResponse->getStatusCode());
    }

    /**
     * Remote deployment should accept requests with the configured domain
     * on any port, since matching is hostname-based.
     */
    public function testRemoteDeploymentPortAgnostic(): void
    {
        $transport = new HttpServerTransport([
            'allowed_origins' => ['mcp.example.com'],
        ]);

        $request = $this->createRequest('POST', [
            'origin' => 'https://mcp.example.com:8443',
            'content-type' => 'application/json',
        ], $this->initBody());

        $response = $transport->handleRequest($request);
        $this->assertNotEquals(403, $response->getStatusCode());
    }
}
