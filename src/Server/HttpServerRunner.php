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
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Server\Transport\Http\Environment;
use Mcp\Server\Transport\Http\SessionStoreInterface;
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
     * Constructor.
     *
     * @param Server $server MCP server instance
     * @param InitializationOptions $initOptions Server initialization options
     * @param array<string, mixed> $httpOptions HTTP transport options
     * @param LoggerInterface|null $logger Logger
     * @param SessionStoreInterface|null $sessionStore Session store
     */
    public function __construct(
        private readonly Server $server,
        private readonly InitializationOptions $initOptions,
        array $httpOptions = [],
        ?LoggerInterface $logger = null,
        ?SessionStoreInterface $sessionStore = null
    ) {
        // Create HTTP transport
        $this->transport = new HttpServerTransport($httpOptions, $sessionStore);
        
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
            // Attempt to restore the higher-level MCP session from the stored array
            $savedState = $httpSession->getMetadata('mcp_server_session');
            if (is_array($savedState)) {
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

            // 4) Now run the session to process whatever got enqueued
            if (!$this->serverSession->isInitialized()) {
                $this->serverSession->start();
            }

            // 5) Build the final HTTP response. When the transport selected
            // SSE mode for this POST, drain the outgoing queue through the
            // resumable SSE emitter; otherwise use the batched JSON path.
            if ($this->transport->lastResponseMode() === 'sse') {
                $response = $this->transport->emitSseResponse($httpSession);
            } else {
                $response = $this->transport->createJsonResponse($httpSession);
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
     * Send an HTTP response.
     *
     * @param HttpMessage $response Response message
     * @return void
     */
    public function sendResponse(HttpMessage $response): void
    {
        // Send headers
        http_response_code($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $value) {
            header("$name: $value");
        }

        // For SSE responses, make the payload visible to the client as
        // promptly as possible: drain any active output buffers and force a
        // flush after writing. Each helper is guarded with function_exists()
        // because hardened shared-hosting environments may remove these from
        // the function table via disable_functions; error suppression (`@`)
        // would not prevent a fatal on a missing/disabled function.
        $contentType = $response->getHeader('Content-Type') ?? '';
        $isSse = \stripos($contentType, 'text/event-stream') !== false;
        if ($isSse) {
            if (\function_exists('ob_end_flush')) {
                // Drain active buffers so SSE frames reach the client
                // promptly. A buffer can be non-removable (framework- or
                // SAPI-owned) in which case ob_end_flush() returns false
                // without changing ob_get_level — looping would spin
                // forever. Guard with the REMOVABLE flag and break on a
                // false return so response delivery never hangs.
                while (\ob_get_level() > 0) {
                    $status = \ob_get_status();
                    if (!\is_array($status)
                        || !isset($status['flags'])
                        || (((int) $status['flags']) & \PHP_OUTPUT_HANDLER_REMOVABLE) === 0
                    ) {
                        break;
                    }
                    if (!\ob_end_flush()) {
                        break;
                    }
                }
            }
            if (\function_exists('ignore_user_abort')) {
                \ignore_user_abort(true);
            }
        }

        // Send body
        $body = $response->getBody();
        if ($body !== null) {
            echo $body;
        }

        if ($isSse && \function_exists('flush')) {
            \flush();
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
