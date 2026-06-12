<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2025 Logiscape LLC <https://logiscape.com>
 *
 * Developed by:
 * - Josh Abbott
 * - Claude 3.7 Sonnet (Anthropic AI model)
 * - ChatGPT o1 pro mode
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
 * Filename: Server/HttpServerRunner.php
 */

declare(strict_types=1);

namespace Mcp\Server;

use Mcp\Server\Transport\HttpServerTransport;
use Mcp\Server\Transport\Http\Environment;
use Mcp\Server\Transport\Http\HttpIoInterface;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Server\Transport\Http\NativePhpIo;
use Mcp\Server\Transport\Http\SessionStoreInterface;
use Mcp\Server\Transport\Http\StreamedHttpMessage;
use Mcp\Server\Subscriptions\SubscriptionBusInterface;
use Mcp\Types\MetaKeys;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runner for HTTP-based MCP servers.
 * 
 * This class extends the base ServerRunner to provide specific
 * functionality for running MCP servers over HTTP.
 */
class HttpServerRunner extends ServerRunner
{
    /**
     * HTTP transport instance.
     *
     * @var HttpServerTransport
     */
    private HttpServerTransport $transport;

    /**
     * Server session instance.
     *
     * @var HttpServerSession|null
     */
    private ?HttpServerSession $serverSession = null;

    /**
     * SAPI side-effect adapter shared with the transport. Every header,
     * body byte, flush, abort check, and shutdown registration performed
     * on behalf of this runner flows through this object so the runner
     * can be embedded in non-standard hosts or exercised by tests without
     * touching php://output.
     */
    private HttpIoInterface $io;

    /**
     * Constructor.
     *
     * @param Server $server MCP server instance
     * @param InitializationOptions $initOptions Server initialization options
     * @param array<string, mixed> $httpOptions HTTP transport options
     * @param LoggerInterface|null $logger Logger
     * @param SessionStoreInterface|null $sessionStore Session store
     * @param HttpIoInterface|null $io SAPI adapter (defaults to NativePhpIo)
     */
    public function __construct(
        private readonly Server $server,
        private readonly InitializationOptions $initOptions,
        array $httpOptions = [],
        ?LoggerInterface $logger = null,
        ?SessionStoreInterface $sessionStore = null,
        ?HttpIoInterface $io = null
    ) {
        $this->io = $io ?? new NativePhpIo();

        // Create HTTP transport, sharing the SAPI adapter so only one
        // implementation owns side effects for this request.
        $this->transport = new HttpServerTransport($httpOptions, $sessionStore, null, $this->io);

        parent::__construct($server, $initOptions, $logger ?? new NullLogger());
    }
    
    /**
     * Handle an HTTP request.
     *
     * @param HttpMessage|null $request Request message (created from globals if null)
     * @return HttpMessage Response message
     */
    public function handleRequest(?HttpMessage $request = null): HttpMessage
    {
        // 1) Let the transport parse the HTTP request and enqueue messages
        $transportResponse = $this->transport->handleRequest($request);

        // If transport returned an error response OR a direct response (like metadata), return it immediately
        $statusCode = $transportResponse->getStatusCode();
        $responseBody = $transportResponse->getBody();
        $transportContentType = $transportResponse->getHeader('Content-Type') ?? '';
        $isDirectSseResponse = $statusCode === 200
            && \stripos($transportContentType, 'text/event-stream') !== false;

        // If we got a response with content (like metadata), an error, or a
        // fully-built SSE response (e.g. a GET replay whose log had no events
        // past the cursor yields a valid empty SSE body), return it as-is
        // rather than falling through to the per-session JSON path.
        if (
            $isDirectSseResponse
            || ($statusCode === 200 && $responseBody !== null && $responseBody !== '')
            || ($statusCode !== 200 && $statusCode !== 202 && $statusCode !== 204)
        ) {
            return $transportResponse;
        }

        // 2) Restore the session if one exists or create a new one
        $httpSession = $this->transport->getLastUsedSession();
        if ($httpSession !== null) {
            if ($this->transport->lastRequestSessionless()) {
                // Modern 2026-07-28 request (SEP-2567): always a fresh MCP
                // session — never restore saved state and never reuse the
                // instance left over from a previous request, so no legacy
                // session's negotiated state (or another modern request's
                // adopted era) can leak in. The transport identified the
                // era from per-request metadata; declare it so a modern
                // request whose body lacks the _meta envelope is rejected
                // with the spec's -32602 instead of misrouted to the
                // legacy path.
                $this->serverSession = new HttpServerSession(
                    $this->transport,
                    $this->initOptions,
                    $this->logger
                );
                $this->serverSession->declareTransportModernEra(true);
                // SEP-2243: hand the request's header map to the session so
                // schema-aware validation (Mcp-Param-*) can run at dispatch.
                $this->serverSession->setTransportHttpHeaders(
                    $this->transport->lastRequestHeaders()
                );
                // SEP-2322: forward the authenticated principal so
                // multi-round-trip requestState is bound to the user it
                // was issued for.
                $this->serverSession->setAuthenticatedPrincipal(
                    $this->resolveAuthenticatedPrincipal($httpSession)
                );
            } elseif (is_array($savedState = $httpSession->getMetadata('mcp_server_session'))) {
                // Rebuild the HttpServerSession from the array
                $restored = HttpServerSession::fromArray(
                    $savedState,
                    $this->transport,
                    $this->initOptions,
                    $this->logger
                );
                $this->serverSession = $restored;
            } else {
                // No saved session; create a new one if we don't already have one
                if ($this->serverSession === null) {
                    $this->serverSession = new HttpServerSession(
                        $this->transport,
                        $this->initOptions,
                        $this->logger
                    );
                }
            }

            // 3) Register the session and handlers
            $this->server->setSession($this->serverSession);
            $this->serverSession->registerHandlers($this->server->getHandlers());
            $this->serverSession->registerNotificationHandlers($this->server->getNotificationHandlers());

            // 4) Decide whether this POST should stream SSE frames as the
            // handler runs. Streaming requires the transport to have chosen
            // SSE mode (i.e. the client advertised text/event-stream AND
            // server config has enable_sse=true) AND the request to be a
            // good candidate per Config::shouldStream. Anything else falls
            // through to the existing buffered/JSON paths.
            $streaming = false;
            if ($this->transport->lastResponseMode() === 'sse') {
                $streaming = $this->transport->getConfig()->shouldStream(
                    $request?->getBody()
                );
            }

            if ($streaming) {
                // Streaming path: begin the SSE response (headers + priming
                // frame flushed to the wire) BEFORE the handler runs. Each
                // writeMessage() during handler execution emits a frame
                // directly, so progress notifications arrive live rather
                // than after the handler returns.
                $this->transport->beginStreamingSseOutput($httpSession);
                if (!$this->serverSession->isInitialized()) {
                    $this->serverSession->start();
                }
                $response = $this->transport->finalizeStreamingSse($httpSession);
            } else {
                try {
                    if (!$this->serverSession->isInitialized()) {
                        $this->serverSession->start();
                    }
                } catch (SubscriptionListenException $listen) {
                    // SEP-2575 subscriptions/listen: the session validated
                    // the request; the runner owns the SAPI adapter and
                    // produces the long-lived notification stream. The
                    // ephemeral modern session is discarded like any other
                    // sessionless request.
                    $response = $this->streamSubscriptionListen($listen);
                    $this->transport->discardSession($httpSession);
                    return $response;
                }

                // 5) Build the final HTTP response. When the transport selected
                // SSE mode for this POST, drain the outgoing queue through the
                // resumable SSE emitter; otherwise use the batched JSON path.
                if ($this->transport->lastResponseMode() === 'sse') {
                    $response = $this->transport->emitSseResponse($httpSession);
                } else {
                    $response = $this->transport->createJsonResponse($httpSession);
                }
            }
            if ($this->transport->lastRequestSessionless()) {
                // Sessionless 2026-07-28 lifecycle request (SEP-2567): no
                // Mcp-Session-Id is echoed and nothing is persisted — the
                // ephemeral processing context is deleted outright (its id
                // was never disclosed, so no client could ever reach it).
                // The SEP-2575 HTTP error statuses were already applied by
                // the transport from the structured hint the session
                // stamped on the response message.
                $this->transport->discardSession($httpSession);
                return $response;
            }

            $response->setHeader('Mcp-Session-Id', $httpSession->getId());

            // 6) Store the session
            $httpSession->setMetadata('mcp_server_session', $this->serverSession->toArray());
            $this->transport->saveSession($httpSession);

            // 7) Return the final HTTP response
            return $response;
        }

        // No valid session; return a 400 error
        return HttpMessage::createJsonResponse(['error' => 'No valid session'], 400);
    }
    
    /**
     * Resolve the stable authenticated identity of the current request
     * for SEP-2322 requestState binding.
     *
     * Preference order: the validated token's `sub` claim; otherwise a
     * SHA-256 fingerprint of the presented bearer token — a token
     * validator MAY return a valid result with empty claims
     * (TokenValidationResult's claims default), and two such users must
     * never share a binding. The prefixes keep claim-derived and
     * fingerprint-derived identities from colliding. When token
     * validation produced claims but no bearer token is recoverable
     * (unreachable through the transport's own auth path), a random
     * identity is minted so the request FAILS CLOSED: its state can never
     * be replayed, by anyone. Null only when authorization is not in play
     * at all (no validated claims), where no authenticated identity
     * exists to bind.
     */
    private function resolveAuthenticatedPrincipal(\Mcp\Server\Transport\Http\HttpSession $httpSession): ?string
    {
        $claims = $httpSession->getMetadata('oauth_claims');
        if (!is_array($claims)) {
            return null;
        }

        $sub = $claims['sub'] ?? null;
        if (is_string($sub) && $sub !== '') {
            return 'sub:' . $sub;
        }

        $authorization = $this->transport->lastRequestHeaders()['authorization'] ?? '';
        if (preg_match('/^Bearer\s+(\S+)/i', $authorization, $m) === 1) {
            return 'tok:' . hash('sha256', $m[1]);
        }

        return 'tok:' . bin2hex(random_bytes(16));
    }

    /**
     * Run the subscriptions/listen SSE stream (SEP-2575, 2026-07-28).
     *
     * Emits the spec-mandated first frame —
     * `notifications/subscriptions/acknowledged` echoing the filter subset
     * the server agreed to honor — then forwards change events from the
     * configured SubscriptionBusInterface that pass the filter, every
     * frame's `_meta` tagged with `io.modelcontextprotocol/subscriptionId`
     * (the stringified listen request id). There is never a JSON-RPC
     * response: the stream ends when the client disconnects (surfaced by
     * keep-alive writes) or the configured lifetime budget elapses. No
     * Mcp-Session-Id, no SSE event ids, no Last-Event-ID resumption — none
     * of those exist on the modern path.
     *
     * Without a configured bus the stream still opens and acknowledges
     * (honest degradation for hosts with no cross-process channel); it
     * just never carries events.
     */
    private function streamSubscriptionListen(SubscriptionListenException $listen): HttpMessage
    {
        $config = $this->transport->getConfig();
        $bus = $config->get('subscription_bus');
        if (!$bus instanceof SubscriptionBusInterface) {
            $bus = null;
        }
        $maxMs = max(0, (int) ($config->get('listen_max_ms') ?? 30000));
        $pollMs = max(10, (int) ($config->get('listen_poll_ms') ?? 50));
        $keepaliveMs = max($pollMs, (int) ($config->get('listen_keepalive_ms') ?? 250));

        $io = $this->io;
        $io->drainOutputBuffers();
        $io->disableAbortKills();
        if (!$io->headersSent()) {
            $io->sendStatus(200);
            $io->sendHeader('Content-Type', 'text/event-stream');
            $io->sendHeader('Cache-Control', 'no-cache, no-transform');
            $io->sendHeader('X-Accel-Buffering', 'no');
        }

        $subscriptionId = $listen->subscriptionId();
        $filter = $listen->agreedFilter;

        $frame = static function (string $method, array $params) use ($subscriptionId): string {
            $params['_meta'] = [MetaKeys::SUBSCRIPTION_ID => $subscriptionId];
            return 'data: ' . json_encode(
                ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params],
                JSON_UNESCAPED_SLASHES
            ) . "\n\n";
        };

        // Capture the bus position BEFORE the acknowledgement reaches the
        // wire: a client is allowed to trigger changes the moment it sees
        // the ack, so an event published between the flush and a
        // later-captured cursor would land inside the starting offset and
        // be lost. Capturing first can only over-deliver (an event from
        // just before the ack), never drop.
        $cursor = $bus?->cursor() ?? 0;

        // Spec: the acknowledgement MUST be the first message on the stream.
        $ack = $filter->jsonSerialize();
        $io->write($frame(
            'notifications/subscriptions/acknowledged',
            ['notifications' => $ack instanceof \stdClass ? new \stdClass() : $ack]
        ));
        $io->flush();
        $deadline = microtime(true) + ($maxMs / 1000.0);
        $nextKeepalive = microtime(true) + ($keepaliveMs / 1000.0);

        while (microtime(true) < $deadline) {
            if ($io->connectionAborted()) {
                break;
            }

            $wrote = false;
            if ($bus !== null) {
                $poll = $bus->pollSince($cursor);
                $cursor = $poll['cursor'];
                foreach ($poll['events'] as $event) {
                    $uri = isset($event['params']['uri']) && is_string($event['params']['uri'])
                        ? $event['params']['uri']
                        : null;
                    if ($filter->wants($event['method'], $uri)) {
                        $io->write($frame($event['method'], $event['params']));
                        $wrote = true;
                    }
                }
            }
            if ($wrote) {
                $io->flush();
                $nextKeepalive = microtime(true) + ($keepaliveMs / 1000.0);
            } elseif (microtime(true) >= $nextKeepalive) {
                // SSE comment: ignored by clients, surfaces a closed
                // connection so the loop can end promptly.
                $io->write(": keepalive\n\n");
                $io->flush();
                if ($io->connectionAborted()) {
                    break;
                }
                $nextKeepalive = microtime(true) + ($keepaliveMs / 1000.0);
            }

            usleep($pollMs * 1000);
        }

        $response = new StreamedHttpMessage('');
        $response->setStatusCode(200);
        $response->setHeader('Content-Type', 'text/event-stream');
        $response->setHeader('Cache-Control', 'no-cache, no-transform');
        $response->setHeader('X-Accel-Buffering', 'no');
        $response->setHeader('X-Mcp-Already-Emitted', '1');
        return $response;
    }

    /**
     * Send an HTTP response.
     *
     * @param HttpMessage $response Response message
     * @return void
     */
    public function sendResponse(HttpMessage $response): void
    {
        // Streaming SSE path: the transport already wrote headers + priming
        // + progress frames + final response through the SAPI adapter during
        // handler execution. sendResponse() is called at the tail of the
        // runner loop just to keep a single exit point; for an already-
        // emitted response we have nothing to add to the wire. Skip the
        // body path entirely and let the request end.
        //
        // The StreamedHttpMessage instanceof check is the canonical signal;
        // the legacy X-Mcp-Already-Emitted header is still recognized for
        // backward compatibility with any external code that inspected it
        // before StreamedHttpMessage existed.
        if ($response instanceof StreamedHttpMessage
            || $response->getHeader('X-Mcp-Already-Emitted') === '1'
        ) {
            return;
        }

        // Send status + headers through the adapter. All SAPI side effects
        // live inside HttpIoInterface implementations — the runner itself
        // stays free of direct header()/echo/flush calls.
        $this->io->sendStatus($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $value) {
            $this->io->sendHeader($name, $value);
        }

        // For SSE responses, make the payload visible to the client as
        // promptly as possible: drain any active output buffers and disable
        // user-abort short-circuits before writing.
        $contentType = $response->getHeader('Content-Type') ?? '';
        $isSse = \stripos($contentType, 'text/event-stream') !== false;
        if ($isSse) {
            $this->io->drainOutputBuffers();
            $this->io->disableAbortKills();
        }

        // Send body
        $body = $response->getBody();
        if ($body !== null) {
            $this->io->write($body);
        }

        if ($isSse) {
            $this->io->flush();
        }
    }
    
    /**
     * Stop the server.
     *
     * @return void
     */
    public function stop(): void
    {
        if ($this->serverSession !== null) {
            try {
                $this->serverSession->close();
            } catch (\Exception $e) {
                $this->logger->error('Error while stopping server session: ' . $e->getMessage());
            }
            $this->serverSession = null;
        }
        
        try {
            $this->transport->stop();
        } catch (\Exception $e) {
            $this->logger->error('Error while stopping transport: ' . $e->getMessage());
        }
        
        $this->logger->info('HTTP Server stopped');
    }
    
    /**
     * Get the transport instance.
     *
     * @return HttpServerTransport Transport instance
     */
    public function getTransport(): HttpServerTransport
    {
        return $this->transport;
    }
    
    /**
     * Get the server session.
     *
     * @return HttpServerSession|null Server session
     */
    public function getServerSession(): ?HttpServerSession
    {
        return $this->serverSession;
    }
    
}
