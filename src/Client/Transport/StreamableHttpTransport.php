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
 * Filename: Client/Transport/StreamableHttpTransport.php
 */

declare(strict_types=1);

namespace Mcp\Client\Transport;

use Mcp\Client\Auth\Discovery\MetadataDiscovery;
use Mcp\Client\Auth\OAuthClient;
use Mcp\Client\Auth\OAuthClientInterface;
use Mcp\Client\Auth\Exception\AuthorizationRedirectException;
use Mcp\Client\Auth\OAuthException;
use Mcp\Client\Auth\Token\TokenSet;
use Mcp\Client\Transport\HttpAuthenticationException;
use Mcp\Shared\MemoryStream;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JsonRpcErrorObject;
use Mcp\Types\Meta;
use Mcp\Types\NotificationParams;
use Mcp\Types\RequestId;
use Mcp\Types\RequestParams;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use InvalidArgumentException;
use CurlHandle;

/**
 * Implements the Streamable HTTP transport for MCP.
 *
 * This transport uses HTTP POST for sending messages to the server and
 * supports both direct JSON responses and Server-Sent Events (SSE) for
 * receiving messages from the server.
 *
 * OAuth 2.0 support is integrated for protected MCP servers.
 */
class StreamableHttpTransport
{
    /**
     * The session manager for handling MCP session state
     */
    private HttpSessionManager $sessionManager;

    /**
     * The HTTP configuration
     */
    private HttpConfiguration $config;

    /**
     * The logger instance
     */
    private LoggerInterface $logger;

    /**
     * The SSE connection (if active)
     */
    private ?SseConnection $sseConnection = null;

    /**
     * Whether to automatically attempt to use SSE
     */
    private bool $autoSse;

    /**
     * Queue of pending SSE messages
     *
     * @var array<int, JsonRpcMessage>
     */
    private array $pendingMessages = [];

    /**
     * Optional callback used to dispatch server-initiated requests and
     * notifications synchronously while the transport is still inside a
     * blocking send / SSE read. Without this, a server that interleaves a
     * sampling/createMessage or elicitation/create request on a POST SSE
     * response stream — and waits for the client's response before sending
     * its own — would deadlock the client's BaseSession read loop.
     *
     * Set by Client::connect() / resumeHttpSession() to point at the
     * session's dispatchIncomingMessage(). When unset, the transport falls
     * back to enqueuing all messages for the read loop to drain later.
     *
     * @var \Closure|null Closure(JsonRpcMessage): void
     */
    private ?\Closure $messageDispatcher = null;

    /**
     * OAuth client for protected resources
     */
    private ?OAuthClientInterface $oauthClient = null;

    /**
     * Maximum number of OAuth retry attempts
     */
    private const MAX_OAUTH_RETRIES = 2;

    /**
     * Milliseconds deducted from the server-provided retry delay before
     * reconnecting. A zero value preserves the exact server pacing.
     */
    private const RECONNECT_CONNECT_BUDGET_MS = 0;

    /**
     * Lower bound (ms) on how long any single reconnect GET is allowed to
     * block. Used as a floor for the per-request timeout so very small
     * `retry` values don't starve the read. The effective timeout is
     * `max(RECONNECT_MIN_READ_TIMEOUT_MS, retry + 2000)`, further clamped to
     * the remaining wall-clock budget.
     */
    private const RECONNECT_MIN_READ_TIMEOUT_MS = 5000;

    /**
     * Creates a new StreamableHttpTransport.
     *
     * @param HttpConfiguration $config Configuration for the HTTP transport
     * @param bool $autoSse Whether to automatically use SSE when available
     * @param LoggerInterface|null $logger PSR-3 compatible logger
     * @param HttpSessionManager|null $sessionManager Optional pre-configured session manager for session resumption
     *
     * @throws RuntimeException If cURL extension is not available
     */
    public function __construct(
        HttpConfiguration $config,
        bool $autoSse = true,
        ?LoggerInterface $logger = null,
        ?HttpSessionManager $sessionManager = null
    ) {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('cURL extension is required for StreamableHttpTransport');
        }

        $this->config = $config;
        $this->autoSse = $autoSse && $config->isSseEnabled();
        $this->logger = $logger ?? new NullLogger();
        $this->sessionManager = $sessionManager ?? new HttpSessionManager($this->logger);

        // Initialize OAuth client if configured
        if ($config->hasOAuth()) {
            $this->oauthClient = new OAuthClient($config->getOAuthConfig(), $this->logger);
        }
    }

    /**
     * Establishes connection to the MCP server.
     *
     * @return array{MemoryStream, MemoryStream} Tuple of read and write streams
     *
     * @throws RuntimeException If connection fails
     */
    public function connect(): array
    {
        $this->logger->info("Connecting to MCP endpoint: {$this->config->getEndpoint()}");

        // Initialize read and write streams
        $readStream = $this->createReadStream();
        $writeStream = $this->createWriteStream();

        if ($this->autoSse) {
            // Attempt to establish an SSE connection
            $this->attemptSseConnection();
        }

        return [$readStream, $writeStream];
    }

    /**
     * Attempts to establish an SSE connection for receiving server messages.
     *
     * This is an optimization - if successful, we'll have a channel for the server
     * to send us messages without us having to poll.
     */
    private function attemptSseConnection(): void
    {
        try {
            $headers = $this->prepareRequestHeaders([
                'Accept' => 'text/event-stream'
            ]);

            $ch = curl_init($this->config->getEndpoint());
            if ($ch === false) {
                throw new RuntimeException('Failed to initialize cURL');
            }

            $this->configureCurlHandle($ch, $headers);
            curl_setopt($ch, CURLOPT_HTTPGET, true);

            // Set up write callback - we're going to check headers to see if this is an SSE stream
            $responseHeaders = [];
            $headerCallback = function ($ch, $header) use (&$responseHeaders) {
                $length = strlen($header);

                // Parse header line
                $parts = explode(':', $header, 2);
                if (count($parts) == 2) {
                    $name = trim($parts[0]);
                    $value = trim($parts[1]);
                    $responseHeaders[strtolower($name)] = $value;
                }

                return $length;
            };

            curl_setopt($ch, CURLOPT_HEADERFUNCTION, $headerCallback);

            // Set a small buffer to check if the server responds with SSE
            $buffer = '';
            $writeCallback = function ($ch, $data) use (&$buffer) {
                $buffer .= $data;
                // Only capture a little bit to check the response type
                return strlen($data) > 0 && strlen($buffer) < 256 ? strlen($data) : 0;
            };

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, $writeCallback);

            // Execute the request
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Check if the server supports SSE
            if ($result !== false && $httpCode === 200) {
                $contentType = $responseHeaders['content-type'] ?? '';
                if (strpos($contentType, 'text/event-stream') !== false) {
                    $this->logger->info('Server supports SSE, will establish streaming connection');

                    // Create an actual SSE connection (reusing the configuration)
                    $this->sseConnection = new SseConnection(
                        $this->config,
                        $this->sessionManager,
                        $this->logger
                    );

                    // Start the connection (which runs in a separate thread/process)
                    $this->sseConnection->start();
                    return;
                }
            }

            // If we get here, SSE is not supported or failed
            $this->logger->info('Server does not support SSE or returned error, will use polling');
        } catch (\Exception $e) {
            $this->logger->warning("Failed to establish SSE connection: {$e->getMessage()}");
        }
    }

    /**
     * Sends a JSON-RPC message to the server via HTTP POST.
     *
     * @param JsonRpcMessage $message The message to send
     * @return array{statusCode: int, headers: array<string, string>, body: string} The response data
     *
     * @throws RuntimeException If the request fails
     */
    public function sendMessage(JsonRpcMessage $message): array
    {
        // Proactively refresh tokens if needed
        $this->proactiveTokenRefresh();

        return $this->sendMessageWithOAuthRetry($message, 0);
    }

    /**
     * Send message with OAuth retry logic for 401/403 responses.
     *
     * @param JsonRpcMessage $message The message to send
     * @param int $attempt Current attempt number
     * @return array{statusCode: int, headers: array<string, string>, body: string} The response data
     */
    private function sendMessageWithOAuthRetry(JsonRpcMessage $message, int $attempt): array
    {
        $endpoint = $this->config->getEndpoint();
        $payload = json_encode($message->jsonSerialize());

        if ($payload === false) {
            throw new RuntimeException('Failed to encode message: ' . json_last_error_msg());
        }

        $headers = $this->prepareRequestHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream'
        ]);

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL');
        }

        $this->configureCurlHandle($ch, $headers);

        // Configure for POST request with the JSON payload
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        // Stream-parse SSE responses inside the cURL write callback so that
        // server-initiated requests/notifications interleaved on the POST SSE
        // stream are dispatched to the session synchronously *while* cURL is
        // still receiving the rest of the stream. Without this, a server
        // that sends `sampling/createMessage` (or similar) and waits for the
        // client's response before producing its own response will deadlock
        // until the cURL read timeout fires.
        // $responseBody captures the body for non-SSE (application/json)
        // responses. For SSE responses, $sseRawBody captures the full raw
        // stream for callers that read it from the public return shape;
        // $sseBuffer is the parser's internal partial-event buffer (mutated
        // by parseStreaming) and is NOT suitable for return because it
        // ends up empty after every complete event is consumed.
        $responseBody = '';
        $sseRawBody = '';
        $sseBuffer = '';
        $sseActive = false;
        $sseCursor = [
            'lastEventId' => null,
            'lastRetryMs' => null,
            'gotResponseForRequest' => false,
        ];
        $outboundRequestId = $this->extractOutboundRequestId($message);

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders, &$sseActive) {
            $length = strlen($header);

            // Parse header line
            $parts = explode(':', $header, 2);
            if (count($parts) == 2) {
                $name = trim(strtolower($parts[0]));
                $value = trim($parts[1]);
                $responseHeaders[$name] = $value;
                if ($name === 'content-type' && strpos($value, 'text/event-stream') !== false) {
                    $sseActive = true;
                }
            }

            return $length;
        });

        curl_setopt(
            $ch,
            CURLOPT_WRITEFUNCTION,
            function ($ch, $data) use (
                &$responseBody,
                &$sseRawBody,
                &$sseBuffer,
                &$sseActive,
                &$sseCursor,
                $outboundRequestId
            ) {
                if (!$sseActive) {
                    $responseBody .= $data;
                    return strlen($data);
                }
                $sseRawBody .= $data;
                $this->consumeSseChunk($data, $sseBuffer, $sseCursor, $outboundRequestId);
                return strlen($data);
            }
        );

        // Execute the request
        $result = curl_exec($ch);

        if ($result === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP request failed: ({$errno}) {$error}");
        }

        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Handle OAuth-related responses
        if ($this->oauthClient !== null && $attempt < self::MAX_OAUTH_RETRIES) {
            // Handle 401 Unauthorized
            if ($statusCode === 401) {
                $this->logger->info('Received 401, initiating OAuth flow');
                $wwwAuth = $this->parseWwwAuthenticate($responseHeaders['www-authenticate'] ?? '');

                try {
                    $this->oauthClient->handleUnauthorized($endpoint, $wwwAuth);
                    // Retry the request with the new token
                    return $this->sendMessageWithOAuthRetry($message, $attempt + 1);
                } catch (AuthorizationRedirectException $e) {
                    throw $e;
                } catch (OAuthException $e) {
                    $this->logger->error("OAuth authorization failed: {$e->getMessage()}");
                    throw new RuntimeException("OAuth authorization failed: {$e->getMessage()}", 0, $e);
                }
            }

            // Handle 403 Forbidden with insufficient_scope
            if ($statusCode === 403) {
                $wwwAuth = $this->parseWwwAuthenticate($responseHeaders['www-authenticate'] ?? '');

                if (isset($wwwAuth['error']) && $wwwAuth['error'] === 'insufficient_scope') {
                    $this->logger->info('Received 403 insufficient_scope, requesting additional scopes');

                    $tokens = $this->oauthClient->getTokens($endpoint);
                    if ($tokens !== null) {
                        try {
                            $this->oauthClient->handleInsufficientScope($endpoint, $wwwAuth, $tokens);
                            // Retry the request with the new token
                            return $this->sendMessageWithOAuthRetry($message, $attempt + 1);
                        } catch (AuthorizationRedirectException $e) {
                            throw $e;
                        } catch (OAuthException $e) {
                            $this->logger->error("OAuth step-up authorization failed: {$e->getMessage()}");
                            throw new RuntimeException("OAuth step-up failed: {$e->getMessage()}", 0, $e);
                        }
                    }
                }
            }
        }

        // Check for HTTP error status codes that weren't handled by OAuth
        // This prevents hanging when server returns error responses
        if ($statusCode === 401) {
            // 401 without OAuth configured - throw HttpAuthenticationException with parsed header
            $this->logger->error('Server returned 401 Unauthorized but OAuth is not configured');
            $wwwAuth = $this->parseWwwAuthenticate($responseHeaders['www-authenticate'] ?? '');
            throw new HttpAuthenticationException(
                401,
                $wwwAuth,
                'Server requires authentication (HTTP 401). Configure OAuth or provide valid credentials.'
            );
        }

        if ($statusCode === 403) {
            // 403 that wasn't handled by OAuth insufficient_scope
            $this->logger->error('Server returned 403 Forbidden');
            throw new RuntimeException(
                'Access forbidden (HTTP 403). Your credentials may be invalid or you lack permission.',
                403
            );
        }

        if ($statusCode >= 400) {
            // Other 4xx/5xx errors - fail fast with status code
            $this->logger->error("Server returned HTTP {$statusCode}", [
                'statusCode' => $statusCode,
                'body' => substr($responseBody, 0, 500) // Log first 500 chars for debugging
            ]);
            throw new RuntimeException(
                "HTTP request failed with status {$statusCode}",
                $statusCode
            );
        }

        // Check if we should process the response differently based on content-type
        $contentType = $responseHeaders['content-type'] ?? '';

        // Process SSE response if that's what we got. Events have already been
        // stream-parsed and dispatched (or queued) inside the cURL
        // WRITEFUNCTION above — here we just (1) reconcile session headers
        // and (2) decide whether the SEP-1699 reconnect loop is needed.
        if ($sseActive) {
            $this->logger->info('Received SSE response to JSON-RPC message');

            $isInitialization = $this->isInitializationMessage($message);
            $sessionValid = $this->sessionManager->processResponseHeaders(
                $responseHeaders,
                $statusCode,
                $isInitialization
            );
            if (!$sessionValid) {
                $this->logger->warning('Session invalidated during SSE response');
            }

            // If the POST SSE stream closed gracefully before the server sent
            // the JSON-RPC response for our request, resume via GET with
            // Last-Event-ID. Per SEP-1699 the server SHOULD send `retry` but
            // is not required to — the event id is the signal that the stream
            // is resumable, and the default retry interval from configuration
            // is used when `retry` is absent.
            if (
                $outboundRequestId !== null
                && $sseCursor['gotResponseForRequest'] === false
                && $sseCursor['lastEventId'] !== null
            ) {
                $retryMs = $sseCursor['lastRetryMs'];
                return $this->awaitResponseViaReconnect(
                    $outboundRequestId,
                    $retryMs === null ? null : (int) $retryMs,
                    (string) $sseCursor['lastEventId']
                );
            }

            return [
                'statusCode' => $statusCode,
                'headers' => $responseHeaders,
                'body' => $sseRawBody,
                'isEventStream' => true,
            ];
        }

        // Update session based on response
        $isInitialization = $this->isInitializationMessage($message);
        $sessionValid = $this->sessionManager->processResponseHeaders($responseHeaders, $statusCode, $isInitialization);

        if (!$sessionValid) {
            $this->logger->warning('Session invalidated, client should reinitialize');
        }

        // ENQUEUE the HTTP JSON-RPC response for the read loop
        $decoded = json_decode($responseBody, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // Support both single and batch responses
            $batch = isset($decoded[0]) && is_array($decoded) ? $decoded : [$decoded];
            foreach ($batch as $item) {
                if (is_array($item)) {
                    $this->enqueueJsonRpcPayload($item);
                }
            }
        }

        return [
            'statusCode' => $statusCode,
            'headers' => $responseHeaders,
            'body' => $responseBody
        ];
    }

    /**
     * Proactively refresh tokens if they're about to expire.
     */
    private function proactiveTokenRefresh(): void
    {
        if ($this->oauthClient === null) {
            return;
        }

        $endpoint = $this->config->getEndpoint();

        try {
            if (method_exists($this->oauthClient, 'proactiveRefresh')) {
                $this->oauthClient->proactiveRefresh($endpoint);
            }
        } catch (\Exception $e) {
            $this->logger->debug("Proactive token refresh skipped: {$e->getMessage()}");
        }
    }

    /**
     * Parse WWW-Authenticate header.
     *
     * @param string $header The WWW-Authenticate header value
     * @return array<string, string|null> Parsed header values
     */
    private function parseWwwAuthenticate(string $header): array
    {
        return MetadataDiscovery::parseWwwAuthenticate($header);
    }

    /**
     * Append a chunk of raw SSE data to the streaming buffer, parse out any
     * complete events (per the WHATWG SSE framing), update the cursor state
     * (last id / retry / response-arrived flag), and enqueue or dispatch the
     * JSON-RPC payloads carried in `data:` fields. Shared between the POST
     * SSE write callback and the reconnect GET write callback.
     *
     * Per the MCP spec, the SSE event id is only meaningful for resumption
     * of THIS specific stream — never write it into the shared session
     * manager, since that would emit Last-Event-ID on unrelated POSTs and
     * DELETEs and trick a compliant server into replaying messages on the
     * wrong stream.
     *
     * @param array{lastEventId: ?string, lastRetryMs: ?int, gotResponseForRequest: bool} $cursor
     * @param-out array{lastEventId: ?string, lastRetryMs: ?int, gotResponseForRequest: bool} $cursor
     */
    protected function consumeSseChunk(
        string $data,
        string &$buffer,
        array &$cursor,
        int|string|null $outboundRequestId
    ): void {
        $buffer .= $data;
        foreach (SseEventParser::parseStreaming($buffer) as $event) {
            if ($event['id'] !== null) {
                $cursor['lastEventId'] = $event['id'];
            }
            if ($event['retry'] !== null) {
                $cursor['lastRetryMs'] = $event['retry'];
            }
            if ($event['data'] === '' || $event['event'] !== 'message') {
                continue;
            }
            if ($this->enqueueSseDataPayload($event['data'], $outboundRequestId)) {
                $cursor['gotResponseForRequest'] = true;
            }
        }
    }

    /**
     * Decode a single SSE data payload and enqueue any JSON-RPC messages it
     * contains. Returns true if one of the enqueued messages is the response
     * to the in-flight request identified by $outboundRequestId.
     */
    private function enqueueSseDataPayload(string $data, int|string|null $outboundRequestId): bool
    {
        $decoded = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return false;
        }

        $batch = isset($decoded[0]) && is_array($decoded) ? $decoded : [$decoded];
        $matched = false;
        foreach ($batch as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!$this->enqueueJsonRpcPayload($item)) {
                continue;
            }
            // Only count as the in-flight response when the payload is
            // actually response-shaped. A server-initiated request can carry
            // an `id` whose value happens to collide with our outbound
            // counter; we must not let that short-circuit the SEP-1699
            // reconnect loop.
            $isResponseShaped = array_key_exists('result', $item) || isset($item['error']);
            if (
                $isResponseShaped
                && $outboundRequestId !== null
                && isset($item['id'])
                && $this->idsEqual($item['id'], $outboundRequestId)
            ) {
                $matched = true;
            }
        }
        return $matched;
    }

    /**
     * Enqueue a single decoded JSON-RPC payload into the pending queue.
     *
     * Per the MCP Streamable HTTP transport spec (revision 2025-11-25), a
     * server MAY interleave JSON-RPC requests and notifications on the POST
     * SSE response stream before delivering the response, and on the GET SSE
     * stream (including resumption GETs). All four message types must be
     * surfaced to the read loop so BaseSession can dispatch them — dropping
     * notifications/requests would silently lose progress, logging,
     * sampling, elicitation, ping, and roots/list traffic.
     *
     * Returns true if a message was enqueued, false if the payload was not a
     * recognizable JSON-RPC message.
     *
     * @param array<mixed, mixed> $item
     */
    private function enqueueJsonRpcPayload(array $item): bool
    {
        if (!isset($item['jsonrpc']) || $item['jsonrpc'] !== '2.0') {
            return false;
        }

        // Success response: jsonrpc + id + result (id may be null per JSON-RPC,
        // but a response always carries the `result` key).
        if (array_key_exists('result', $item) && isset($item['id'])) {
            $inner = new JSONRPCResponse(
                jsonrpc: '2.0',
                id: new RequestId($item['id']),
                result: $item['result']
            );
            $this->pendingMessages[] = new JsonRpcMessage($inner);
            return true;
        }

        // Error response
        if (isset($item['error'], $item['id']) && is_array($item['error'])) {
            $err = $item['error'];
            if (!isset($err['code']) || !isset($err['message'])) {
                return false;
            }
            $errorCode = is_numeric($err['code']) ? (int) $err['code'] : 0;
            $errorMessage = is_string($err['message']) ? $err['message'] : 'Unknown error';
            $errorData = $err['data'] ?? null;
            $inner = new JSONRPCError(
                jsonrpc: '2.0',
                id: new RequestId($item['id']),
                error: new JsonRpcErrorObject(
                    code: $errorCode,
                    message: $errorMessage,
                    data: $errorData
                )
            );
            $this->pendingMessages[] = new JsonRpcMessage($inner);
            return true;
        }

        // Server-initiated request: jsonrpc + method + id (no result/error)
        if (isset($item['method'], $item['id']) && is_string($item['method'])) {
            $params = null;
            if (isset($item['params']) && is_array($item['params'])) {
                $params = $this->parseRequestParams($item['params']);
            } elseif (isset($item['params'])) {
                $params = $item['params'];
            }
            $inner = new JSONRPCRequest(
                jsonrpc: '2.0',
                id: new RequestId($item['id']),
                params: $params,
                method: $item['method']
            );
            $this->deliverServerInitiatedMessage(new JsonRpcMessage($inner));
            return true;
        }

        // Notification: jsonrpc + method, no id
        if (isset($item['method']) && is_string($item['method']) && !isset($item['id'])) {
            $params = null;
            if (isset($item['params']) && is_array($item['params'])) {
                $params = $this->parseNotificationParams($item['params']);
            } elseif (isset($item['params'])) {
                $params = $item['params'];
            }
            $inner = new JSONRPCNotification(
                jsonrpc: '2.0',
                method: $item['method'],
                params: $params
            );
            $this->deliverServerInitiatedMessage(new JsonRpcMessage($inner));
            return true;
        }

        return false;
    }

    /**
     * Deliver a server-initiated request or notification. Dispatched
     * synchronously through the registered messageDispatcher when one is
     * set so the session can service the message *while* the outer cURL
     * transfer is still in flight; this prevents the deadlock where a
     * server holds the POST SSE stream open waiting for the client's
     * response to a sampling/elicitation/ping request.
     *
     * Falls back to the pending-message queue when no dispatcher is
     * registered (e.g. transport used outside of a Client wiring).
     */
    private function deliverServerInitiatedMessage(JsonRpcMessage $message): void
    {
        if ($this->messageDispatcher !== null) {
            try {
                ($this->messageDispatcher)($message);
                return;
            } catch (\Throwable $e) {
                // A failed dispatch must not derail the SSE read loop. Log
                // and queue so the message is not silently lost — the read
                // loop can still surface it later if the session recovers.
                $this->logger->error(
                    'Synchronous dispatch of server-initiated message failed; '
                    . 'falling back to pending queue: ' . $e->getMessage()
                );
            }
        }
        $this->pendingMessages[] = $message;
    }

    /**
     * Build a typed RequestParams from the raw `params` map, extracting any
     * `_meta` block. Mirrors SseConnection::parseRequestParams() so messages
     * arriving on the POST SSE / reconnect GET path are structurally
     * identical to those from the long-lived GET stream.
     *
     * @param array<string, mixed> $params
     */
    private function parseRequestParams(array $params): RequestParams
    {
        $meta = isset($params['_meta']) && is_array($params['_meta'])
            ? $this->metaFromArray($params['_meta'])
            : null;

        $requestParams = new RequestParams($meta);
        foreach ($params as $key => $value) {
            if ($key !== '_meta') {
                $requestParams->$key = $value;
            }
        }

        return $requestParams;
    }

    /**
     * Build a typed NotificationParams from the raw `params` map. Mirrors
     * SseConnection::parseNotificationParams().
     *
     * @param array<string, mixed> $params
     */
    private function parseNotificationParams(array $params): NotificationParams
    {
        $meta = isset($params['_meta']) && is_array($params['_meta'])
            ? $this->metaFromArray($params['_meta'])
            : null;

        $notificationParams = new NotificationParams($meta);
        foreach ($params as $key => $value) {
            if ($key !== '_meta') {
                $notificationParams->$key = $value;
            }
        }

        return $notificationParams;
    }

    /**
     * @param array<string, mixed> $metaArr
     */
    private function metaFromArray(array $metaArr): Meta
    {
        $meta = new Meta();
        foreach ($metaArr as $key => $value) {
            $meta->$key = $value;
        }
        return $meta;
    }

    /**
     * Extract the JSON-RPC id from an outbound message if — and only if — it
     * is a request. Notifications and responses return null.
     */
    private function extractOutboundRequestId(JsonRpcMessage $message): int|string|null
    {
        $inner = $message->message;
        if (!($inner instanceof \Mcp\Types\JSONRPCRequest)) {
            return null;
        }
        return $inner->id->getValue();
    }

    /**
     * Compare two JSON-RPC ids. Ids are int|string; JSON decode may yield an
     * int while the outbound counter also uses int, so strict compare works
     * for the common case. Fall back to string compare to tolerate servers
     * that quote numeric ids.
     */
    private function idsEqual(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ((is_int($a) || is_string($a)) && (is_int($b) || is_string($b))) {
            return (string) $a === (string) $b;
        }
        return false;
    }

    /**
     * Await the JSON-RPC response for an in-flight request via GET SSE
     * resumption, as specified by the MCP Streamable HTTP transport and
     * SEP-1699.
     *
     * Loops until one of the following is true:
     *  - the response for $requestId is enqueued (success),
     *  - the response for $requestId is not delivered before the wall-clock
     *    budget is exhausted.
     *
     * Each GET includes `Last-Event-ID` set to the latest cursor from the
     * in-flight stream. The cursor is tracked entirely in method-local state
     * — it is never written into the shared session manager, because
     * Last-Event-ID is only meaningful for the specific stream being resumed.
     *
     * @param int|string $requestId JSON-RPC id of the in-flight request
     * @param ?int $retryMs Initial retry interval from the POST SSE priming
     *                     event, or null if the server omitted `retry:`
     * @param string $lastEventId Event id from the POST SSE priming event
     * @return array{statusCode: int, headers: array<string, string>, body: string, isEventStream: bool}
     */
    private function awaitResponseViaReconnect(
        int|string $requestId,
        ?int $retryMs,
        string $lastEventId
    ): array {
        $defaultRetryMs = (int) ($this->config->getSseDefaultRetryDelay() * 1000);
        $budgetMs = (int) ($this->config->getSseReconnectBudget() * 1000);
        $deadlineMs = $this->nowMs() + $budgetMs;

        $effectiveRetry = $retryMs ?? $defaultRetryMs;
        $cursor = $lastEventId;
        $attempt = 0;

        while (true) {
            $attempt++;

            $remainingMs = $deadlineMs - $this->nowMs();
            if ($remainingMs <= 0) {
                throw new RuntimeException(
                    "SSE reconnect budget of {$budgetMs}ms exhausted after {$attempt} "
                    . "attempt(s) for request id {$requestId}"
                );
            }

            $sleepMs = min(
                max(0, $effectiveRetry - self::RECONNECT_CONNECT_BUDGET_MS),
                $remainingMs
            );
            if ($sleepMs > 0) {
                $this->logger->info(
                    "Waiting {$sleepMs}ms before SSE reconnect GET "
                    . "(retry={$effectiveRetry}ms, attempt={$attempt})"
                );
                usleep($sleepMs * 1000);
            }

            $remainingAfterSleep = $deadlineMs - $this->nowMs();
            if ($remainingAfterSleep <= 0) {
                throw new RuntimeException(
                    "SSE reconnect budget of {$budgetMs}ms exhausted during sleep "
                    . "(attempt {$attempt}) for request id {$requestId}"
                );
            }

            [$found, $newRetry, $newCursor] = $this->performReconnectGet(
                $requestId,
                $cursor,
                $effectiveRetry,
                $remainingAfterSleep
            );

            if ($found !== null) {
                return $found;
            }

            // Per the MCP transport spec and SEP-1699, once the server has
            // sent an event id the stream is resumable indefinitely — a
            // resumed GET that closes or times out before the pending
            // response arrives is a normal case, and the client must keep
            // reconnecting with the existing Last-Event-ID and retry until
            // the wall-clock budget is exhausted. If the server does send a
            // fresh priming event (new cursor or updated retry), adopt it;
            // otherwise keep using what we have.
            if ($newRetry !== null) {
                $effectiveRetry = $newRetry;
            }
            if ($newCursor !== null) {
                $cursor = $newCursor;
            }
        }
    }

    /**
     * Issue a single resumption GET SSE request and progressively parse events
     * until either the target response is enqueued or the stream ends.
     *
     * The $cursor is sent as `Last-Event-ID` on this request only. Any new
     * cursor and retry values observed on the GET stream are returned to the
     * caller for use in subsequent iterations — they are NOT written into the
     * shared session manager.
     *
     * @param int|string $requestId JSON-RPC id to match the in-flight request
     * @param string $cursor Last-Event-ID to send on this specific GET
     * @param int $retryMs Current retry interval (used to size the read timeout)
     * @param int $remainingBudgetMs Wall-clock budget remaining for the whole loop
     * @return array{0: ?array{statusCode: int, headers: array<string, string>, body: string, isEventStream: bool}, 1: ?int, 2: ?string}
     */
    protected function performReconnectGet(
        int|string $requestId,
        string $cursor,
        int $retryMs,
        int $remainingBudgetMs
    ): array {
        // Set Last-Event-ID explicitly (and only) on this request. The
        // additionalHeaders merge in prepareRequestHeaders() ensures it
        // overrides anything the session manager might carry.
        $headers = $this->prepareRequestHeaders([
            'Accept' => 'text/event-stream',
            'Last-Event-ID' => $cursor,
        ]);

        $ch = curl_init($this->config->getEndpoint());
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL for SSE reconnect');
        }

        // Reuse the common cURL setup (TLS, config-level options) but override
        // the read timeout with a tighter bound scaled to the retry interval,
        // clamped to the remaining wall-clock budget.
        $this->configureCurlHandle($ch, $headers);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        $timeoutMs = min(
            max(self::RECONNECT_MIN_READ_TIMEOUT_MS, $retryMs + 2000),
            $remainingBudgetMs
        );
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMs);

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
            $length = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                $value = trim($parts[1]);
                $responseHeaders[$name] = $value;
            }
            return $length;
        });

        $buffer = '';
        $cursor = [
            'lastEventId' => null,
            'lastRetryMs' => null,
            'gotResponseForRequest' => false,
        ];
        curl_setopt(
            $ch,
            CURLOPT_WRITEFUNCTION,
            function ($ch, $data) use (&$buffer, &$cursor, $requestId) {
                $this->consumeSseChunk($data, $buffer, $cursor, $requestId);
                if ($cursor['gotResponseForRequest']) {
                    // Short-write signals curl to abort. The calling code
                    // treats CURLE_WRITE_ERROR (23) as success when
                    // gotResponseForRequest is true.
                    return 0;
                }
                return strlen($data);
            }
        );

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!$cursor['gotResponseForRequest'] && $result === false && $errno !== 0 && $errno !== CURLE_OPERATION_TIMEDOUT) {
            throw new RuntimeException("SSE reconnect GET failed: ({$errno}) {$curlError}");
        }

        if ($statusCode >= 400) {
            throw new RuntimeException("SSE reconnect GET returned HTTP {$statusCode}");
        }

        if ($cursor['gotResponseForRequest']) {
            return [
                [
                    'statusCode' => $statusCode ?: 200,
                    'headers' => $responseHeaders,
                    'body' => '',
                    'isEventStream' => true,
                ],
                $cursor['lastRetryMs'],
                $cursor['lastEventId'],
            ];
        }

        return [null, $cursor['lastRetryMs'], $cursor['lastEventId']];
    }

    /**
     * Current monotonic-ish clock in whole milliseconds.
     */
    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * Check if a message is an initialization message.
     *
     * @param JsonRpcMessage $message The message to check
     * @return bool True if it's an initialization message
     */
    private function isInitializationMessage(JsonRpcMessage $message): bool
    {
        // Examine the inner message to see if it's an initialize request
        $innerMessage = $message->message;

        // Check if it's a request with method "initialize"
        if (property_exists($innerMessage, 'method') && $innerMessage->method === 'initialize') {
            return true;
        }

        return false;
    }

    /**
     * Prepares HTTP headers for a request, merging defaults with the
     * configuration headers and session headers.
     *
     * @param array<string, string> $additionalHeaders Additional headers to include
     * @return array<int, string> Complete set of cURL-format headers ("Name: Value")
     */
    private function prepareRequestHeaders(array $additionalHeaders = []): array
    {
        // Start with headers from configuration
        $headers = $this->config->getHeaders();

        // Add session headers if available
        if ($this->sessionManager->isInitialized()) {
            $headers = array_merge($headers, $this->sessionManager->getRequestHeaders());
        }

        // Add OAuth Authorization header if tokens are available
        if ($this->oauthClient !== null) {
            $endpoint = $this->config->getEndpoint();
            $tokens = $this->oauthClient->getTokens($endpoint);
            if ($tokens !== null && !$tokens->isExpired()) {
                $headers['Authorization'] = $tokens->getAuthorizationHeader();
            }
        }

        // Add any additional headers for this specific request
        $headers = array_merge($headers, $additionalHeaders);

        // Convert to cURL format (array of "Name: Value" strings)
        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = "{$name}: {$value}";
        }

        return $curlHeaders;
    }

    /**
     * Configures a cURL handle with common options.
     *
     * @param CurlHandle $ch The cURL handle to configure
     * @param array<int, string> $headers HTTP headers in cURL format ("Name: Value")
     */
    private function configureCurlHandle($ch, array $headers): void
    {
        // Set common cURL options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, (int) ($this->config->getConnectionTimeout() * 1000));
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, (int) ($this->config->getReadTimeout() * 1000));

        // Configure TLS verification
        if ($this->config->isVerifyTlsEnabled()) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            // Use custom CA file if provided
            if ($this->config->getCaFile() !== null) {
                curl_setopt($ch, CURLOPT_CAINFO, $this->config->getCaFile());
            }
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        // Apply any additional cURL options from configuration
        foreach ($this->config->getCurlOptions() as $option => $value) {
            curl_setopt($ch, $option, $value);
        }
    }

    /**
     * Creates a memory stream for reading responses from the server.
     *
     * @return MemoryStream The read stream
     */
    private function createReadStream(): MemoryStream
    {
        return new class ($this) extends MemoryStream {
            private StreamableHttpTransport $transport;

            public function __construct(StreamableHttpTransport $transport)
            {
                $this->transport = $transport;
            }

            /**
             * Receive a message from the transport.
             *
             * @return JsonRpcMessage|null The received message or null if none available
             */
            public function receive(): mixed
            {
                // First check if we have any pending messages from the SSE connection
                if ($message = $this->transport->receiveFromSse()) {
                    return $message;
                }

                // Check if we have any pending messages from the HTTP JSON-RPC response queue
                if ($message = $this->transport->receiveFromHttp()) {
                    return $message;
                }

                // No messages available right now
                return null;
            }
        };
    }

    /**
     * Creates a memory stream for writing messages to the server.
     *
     * @return MemoryStream The write stream
     */
    private function createWriteStream(): MemoryStream
    {
        return new class ($this) extends MemoryStream {
            private StreamableHttpTransport $transport;

            public function __construct(StreamableHttpTransport $transport)
            {
                $this->transport = $transport;
            }

            /**
             * Send a message through the transport.
             *
             * @param mixed $message The message to send
             * @throws InvalidArgumentException If the message is not a JsonRpcMessage
             */
            public function send(mixed $message): void
            {
                if (!$message instanceof JsonRpcMessage) {
                    throw new InvalidArgumentException('StreamableHttpTransport can only send JsonRpcMessage objects');
                }

                // Send the message via HTTP POST
                $this->transport->sendMessage($message);
            }
        };
    }

    /**
     * Receives a message from the SSE connection, if available.
     *
     * @return JsonRpcMessage|null The received message or null if none available
     */
    public function receiveFromSse(): ?JsonRpcMessage
    {
        // Check if we have an active SSE connection
        if ($this->sseConnection === null) {
            return null;
        }

        // Check if connection is healthy
        if (!$this->sseConnection->isActive()) {
            $this->logger->warning('SSE connection is no longer active');
            $this->sseConnection = null;
            return null;
        }

        // Try to get a message from the SSE connection
        return $this->sseConnection->receiveMessage();
    }

    /**
     * Receives a message from the HTTP JSON-RPC response queue.
     *
     * @return JsonRpcMessage|null The received message or null if none available
     * @throws RuntimeException When there are persistent errors and no more messages
     */
    public function receiveFromHttp(): ?JsonRpcMessage
    {
        $message = array_shift($this->pendingMessages);

        if ($message !== null) {
            // Check if this is an error message
            $innerMessage = $message->message;
            if ($innerMessage instanceof \Mcp\Types\JSONRPCError) {
                $error = $innerMessage->error;
                // If it's a critical error throw an exception
                $this->logger->error("Critical MCP error: {$error->message} (code: {$error->code})");
                throw new RuntimeException("Critical MCP error: {$error->message} (code: {$error->code})");
            }

            return $message;
        } else {
            return null;
        }
    }

    /**
     * Closes the transport connection.
     */
    public function close(): void
    {
        $this->logger->info('Closing HTTP transport connection');

        // Close SSE connection if active
        if ($this->sseConnection !== null) {
            $this->sseConnection->stop();
            $this->sseConnection = null;
        }

        // If we have a valid session, send DELETE request to terminate it
        if ($this->sessionManager->isValid() && $this->sessionManager->getSessionId() !== null) {
            try {
                $this->terminateSession();
            } catch (\Exception $e) {
                $this->logger->warning("Failed to terminate session: {$e->getMessage()}");
            }
        }
    }

    /**
     * Explicitly terminates the MCP session by sending an HTTP DELETE request.
     */
    private function terminateSession(): void
    {
        $endpoint = $this->config->getEndpoint();
        $headers = $this->prepareRequestHeaders();

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL for session termination');
        }

        $this->configureCurlHandle($ch, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

        // We don't care about the response content
        curl_setopt($ch, CURLOPT_NOBODY, true);

        // Execute the request
        $result = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false) {
            $this->logger->warning('Failed to send session termination request');
        } else {
            $this->logger->info("Session termination request sent, status: {$statusCode}");
        }

        // Invalidate our session state
        $this->sessionManager->invalidateSession();
    }

    /**
     * Get the OAuth client (for testing or advanced usage).
     *
     * @return OAuthClientInterface|null
     */
    public function getOAuthClient(): ?OAuthClientInterface
    {
        return $this->oauthClient;
    }

    /**
     * Register a synchronous dispatcher for server-initiated requests and
     * notifications received on POST SSE / reconnect GET streams.
     *
     * The dispatcher is invoked from inside the cURL WRITEFUNCTION as soon
     * as a server→client request or notification is parsed off the stream,
     * so the session's request/notification handlers can run while the
     * outer cURL transfer is still in progress. This is what allows a
     * server-initiated `sampling/createMessage` or `elicitation/create`
     * interleaved on the POST response stream to be serviced before the
     * server's own response arrives.
     *
     * Pass null to clear (e.g. on session teardown).
     */
    public function setMessageDispatcher(?\Closure $dispatcher): void
    {
        $this->messageDispatcher = $dispatcher;
    }

    /**
     * Get the session manager for extracting session state.
     *
     * @return HttpSessionManager
     */
    public function getSessionManager(): HttpSessionManager
    {
        return $this->sessionManager;
    }

    /**
     * Detach from the transport without terminating the server-side session.
     *
     * Unlike close(), this does NOT send an HTTP DELETE request to the server.
     * The server-side session remains active for later resumption.
     * SSE connections are closed since they cannot persist across PHP requests.
     */
    public function detach(): void
    {
        $this->logger->info('Detaching HTTP transport (preserving server session)');

        // Close SSE connection if active (can't persist across PHP requests)
        if ($this->sseConnection !== null) {
            $this->sseConnection->stop();
            $this->sseConnection = null;
        }

        // Do NOT send HTTP DELETE — that's the key difference from close()
    }
}
