<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\Transport\HttpServerTransport;
use Mcp\Server\Transport\Http\HttpMessage;
use PHPUnit\Framework\TestCase;

/**
 * Anchors MCP Streamable HTTP spec §5.8.2 enforcement: when session
 * management is active (enable_sse=true) the server must reject GETs and
 * DELETEs that lack a session id or target an unknown/uninitialized session
 * rather than silently creating an orphan session.
 *
 * The previous behavior auto-created a session on anonymous GET and returned
 * a standalone SSE stream, which let arbitrary callers spawn event streams
 * against an uninitialized protocol state.
 */
final class HttpSseSessionValidationTest extends TestCase
{
    private function initBody(int $id = 1): string
    {
        return (string) \json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
            ],
        ]);
    }

    private function postRequest(string $body): HttpMessage
    {
        $request = new HttpMessage($body);
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', 'application/json, text/event-stream');
        return $request;
    }

    private function getSseRequest(?string $sessionId): HttpMessage
    {
        $request = new HttpMessage();
        $request->setMethod('GET');
        $request->setUri('/');
        $request->setHeader('Accept', 'text/event-stream');
        if ($sessionId !== null) {
            $request->setHeader('Mcp-Session-Id', $sessionId);
        }
        return $request;
    }

    private function deleteRequest(?string $sessionId): HttpMessage
    {
        $request = new HttpMessage();
        $request->setMethod('DELETE');
        $request->setUri('/');
        if ($sessionId !== null) {
            $request->setHeader('Mcp-Session-Id', $sessionId);
        }
        return $request;
    }

    private function markSessionInitialized(HttpServerTransport $transport): void
    {
        $session = $transport->getLastUsedSession();
        if ($session === null) {
            return;
        }
        $session->setMetadata('mcp_server_session', [
            'initializationState' => 3, // InitializationState::Initialized
        ]);
        $transport->saveSession($session);
    }

    /**
     * Spec §5.8.2: "Servers that require a session ID SHOULD respond to
     * requests without an Mcp-Session-Id header (other than initialization)
     * with HTTP 400 Bad Request." With enable_sse=true the SDK opts into
     * strict session management; an anonymous SSE GET must therefore be
     * rejected rather than auto-creating an orphan session.
     */
    public function testAnonymousGetSseReturns400WhenSseEnabled(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        $resp = $transport->handleRequest($this->getSseRequest(null));

        $this->assertSame(400, $resp->getStatusCode());
        $body = $resp->getBody() ?? '';
        $this->assertStringContainsString('Session ID required', $body);
    }

    /**
     * A GET carrying an Mcp-Session-Id that does not correspond to any known
     * session must return 404 so the client can re-initialize per §5.8.4.
     */
    public function testGetWithUnknownSessionReturns404(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        $resp = $transport->handleRequest($this->getSseRequest('deadbeef-not-a-real-session'));

        $this->assertSame(404, $resp->getStatusCode());
    }

    /**
     * GET on a session that exists but has not completed the `initialize`
     * handshake is a protocol violation. Returning 400 makes the skipped
     * step obvious to clients instead of opening an event stream against
     * an uninitialized protocol state.
     */
    public function testGetWithUninitializedSessionReturns400(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        $postResp = $transport->handleRequest($this->postRequest($this->initBody(1)));
        $sessionId = $postResp->getHeader('Mcp-Session-Id');
        $this->assertNotNull($sessionId);
        // Deliberately SKIP markSessionInitialized: the initialize POST
        // reached the transport but the runner-level persistence never ran.

        $resp = $transport->handleRequest($this->getSseRequest($sessionId));

        $this->assertSame(400, $resp->getStatusCode());
        $body = $resp->getBody() ?? '';
        $this->assertStringContainsString('Session not initialized', $body);
    }

    /**
     * Once a session has been marked initialized (mirroring the metadata the
     * HttpServerRunner persists after initialize completes) a GET with a
     * Last-Event-ID is accepted and returns the replay path's response.
     */
    public function testInitializedSessionPermitsSseGet(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        $postResp = $transport->handleRequest($this->postRequest($this->initBody(1)));
        $sessionId = $postResp->getHeader('Mcp-Session-Id');
        $this->assertNotNull($sessionId);
        $this->markSessionInitialized($transport);

        // GET with malformed Last-Event-ID still reaches the replay path,
        // which returns 400 for bad cursor. That confirms the session gate
        // passed and the GET was routed to handleGetRequest.
        $getReq = $this->getSseRequest($sessionId);
        $getReq->setHeader('Last-Event-ID', 'not-a-cursor');
        $resp = $transport->handleRequest($getReq);

        $this->assertSame(400, $resp->getStatusCode());
        $this->assertStringContainsString('Invalid Last-Event-ID', $resp->getBody() ?? '');
    }

    /**
     * DELETE with SSE enabled must also carry a session id: without it, the
     * previous behavior silently auto-created a session just to expire it.
     */
    public function testAnonymousDeleteReturns400WhenSseEnabled(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        $resp = $transport->handleRequest($this->deleteRequest(null));

        $this->assertSame(400, $resp->getStatusCode());
    }

    /**
     * DELETE on an unknown session returns 404 (session-not-found), matching
     * the GET behavior. The client's next move is to reinitialize.
     */
    public function testDeleteWithUnknownSessionReturns404(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        $resp = $transport->handleRequest($this->deleteRequest('deadbeef'));

        $this->assertSame(404, $resp->getStatusCode());
    }

    /**
     * With enable_sse=false the SDK intentionally preserves legacy lenient
     * behavior: an anonymous GET falls through to the 405 path in
     * handleGetRequest (which emits the Allow header listing POST + DELETE).
     * This keeps deployments that never opted into GET-SSE unchanged.
     */
    public function testAnonymousGetReturns405WhenSseDisabled(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => false]);
        $transport->start();

        $resp = $transport->handleRequest($this->getSseRequest(null));

        $this->assertSame(405, $resp->getStatusCode());
        $this->assertSame('POST, DELETE', $resp->getHeader('Allow'));
    }
}
