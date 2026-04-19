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
 * Filename: Server/Transport/HttpServerTransport.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport;

use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JSONRPCError;
use Mcp\Types\RequestId;
use Mcp\Types\JsonRpcErrorObject;
use Mcp\Shared\McpError;
use Mcp\Server\Transport\Http\Config;
use Mcp\Server\Transport\Http\Environment;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Server\Transport\Http\HttpSession;
use Mcp\Server\Transport\Http\InMemorySessionStore;
use Mcp\Server\Transport\Http\MessageQueue;
use Mcp\Server\Transport\Http\SessionStoreInterface;
use Mcp\Server\Transport\Http\Sse\SseEmitter;
use Mcp\Server\Transport\Http\Sse\SseFrame;
use Mcp\Server\Transport\Http\Sse\SseSessionState;
use Mcp\Server\Transport\Http\Sse\StreamId;
use Mcp\Server\Transport\Http\Sse\StreamRegistry;
use Mcp\Server\Auth\TokenValidatorInterface;

/**
 * HTTP transport implementation for MCP server.
 * 
 * This class provides an HTTP transport layer for MCP, supporting
 * standard request/response interactions with optional SSE capabilities.
 */
class HttpServerTransport implements Transport
{
    /**
     * Configuration options.
     *
     * @var Config
     */
    private Config $config;
    
    /**
     * Message queue for incoming and outgoing messages.
     *
     * @var MessageQueue
     */
    private MessageQueue $messageQueue;
    
    /**
     * Session store.
     *
     * @var SessionStoreInterface
     */
    private SessionStoreInterface $sessionStore;

    /**
     * Active sessions.
     *
     * @var array<string, HttpSession>
     */
    private array $sessions = [];
    
    /**
     * Current session ID.
     *
     * @var string|null
     */
    private ?string $currentSessionId = null;
    
    /**
     * Whether the transport is started.
     *
     * @var bool
     */
    private bool $isStarted = false;
    
    /**
     * Last session cleanup time.
     *
     * @var int
     */
    private int $lastSessionCleanup = 0;
    
    /**
     * Session cleanup interval in seconds.
     *
     * @var int
     */
    private int $sessionCleanupInterval = 300; // 5 minutes

    /**
     * Last used session.
     *
     * @var HttpSession|null
     */
    private ?HttpSession $lastUsedSession = null;

    /**
     * Token validator for OAuth access tokens.
     */
    private ?TokenValidatorInterface $validator = null;

    /**
     * Response mode selected for the current request. When set to 'sse' the
     * runner should call emitSseResponse() instead of createJsonResponse().
     * Reset at the start of every handleRequest() call.
     */
    private ?string $lastResponseMode = null;

    /**
     * Stream id minted for the current SSE-mode POST. Null in non-SSE mode.
     */
    private ?string $currentStreamId = null;

    /**
     * JSON-RPC id of the client request that this stream responds to. Used
     * by emitSseResponse() to recognise the final response event and mark
     * the stream completed.
     *
     * @var string|int|null
     */
    private string|int|null $currentOriginatingRequestId = null;
    
    /**
     * Constructor.
     *
     * @param array<string, mixed> $options Configuration options
     */
    public function __construct(array $options = [], ?SessionStoreInterface $sessionStore = null, ?TokenValidatorInterface $validator = null)
    {
        $this->config = new Config($options);
        $this->validator = $validator ?? $this->config->getTokenValidator();
        $this->messageQueue = new MessageQueue(
            $this->config->get('max_queue_size')
        );

        // If no store passed, default to an in-memory store
        $this->sessionStore = $sessionStore ?? new InMemorySessionStore();
    }
    
    /**
     * Start the transport.
     *
     * @return void
     * @throws \RuntimeException If transport is already started.
     */
    public function start(): void
    {
        if ($this->isStarted) {
            throw new \RuntimeException('Transport already started');
        }
        
        $this->isStarted = true;
        $this->lastSessionCleanup = time();
    }
    
    /**
     * Stop the transport.
     *
     * @return void
     */
    public function stop(): void
    {
        if (!$this->isStarted) {
            return;
        }
        
        // Close all sessions
        foreach ($this->sessions as $session) {
            $session->expire();
        }
        
        // Clear message queues
        $this->messageQueue->clear();
        
        $this->isStarted = false;
    }
    
    /**
     * Read the next message.
     *
     * @return JsonRpcMessage|null Next message or null if none available
     * @throws \RuntimeException If transport is not started.
     */
    public function readMessage(): ?JsonRpcMessage
    {
        if (!$this->isStarted) {
            throw new \RuntimeException('Transport not started');
        }
        
        // Cleanup expired sessions periodically
        $this->cleanupExpiredSessions();
        
        // Return the next message from the incoming queue
        return $this->messageQueue->dequeueIncoming();
    }
    
    /**
     * Write a message.
     *
     * @param JsonRpcMessage $message Message to write
     * @return void
     * @throws \RuntimeException If transport is not started.
     */
    public function writeMessage(JsonRpcMessage $message): void
    {
        if (!$this->isStarted) {
            throw new \RuntimeException('Transport not started');
        }
        
        // If we have a current session, use that
        if ($this->currentSessionId !== null) {
            $this->messageQueue->queueOutgoing($message, $this->currentSessionId);
            return;
        }
        
        // Otherwise, try to determine the target session based on message type
        $innerMessage = $message->message;
        
        if ($innerMessage instanceof JSONRPCResponse || $innerMessage instanceof JSONRPCError) {
            // For responses, route to the session that made the request
            $this->messageQueue->queueResponse($message);
        } else {
            // For other message types without a clear session target,
            // we have no place to queue it - this is a programming error
            throw new \RuntimeException('Cannot route message: no target session');
        }
    }
    
    /**
     * Handle an HTTP request.
     *
     * @param HttpMessage $request Request message
     * @return HttpMessage Response message
     */
    public function handleRequest(HttpMessage $request): HttpMessage
    {
        // Reset per-request SSE state so a value from a previous request can't
        // leak into this one when the transport is reused across calls.
        $this->lastResponseMode = null;
        $this->currentStreamId = null;
        $this->currentOriginatingRequestId = null;

        // DNS rebinding protection: validate Origin header (MCP spec MUST requirement)
        if ($rejection = $this->validateOrigin($request)) {
            return $rejection;
        }

        $path = parse_url($request->getUri() ?? '/', PHP_URL_PATH);
        if ($request->getMethod() === 'GET' && stripos($path, $this->config->getResourceMetadataPath()) !== false) {
            return HttpMessage::createJsonResponse($this->getProtectedResourceMetadata());
        }

        // Extract session ID from request headers
        $sessionId = $request->getHeader('Mcp-Session-Id');
        $session = null;
        
        // Find or create session
        if ($sessionId !== null) {
            $session = $this->getSession($sessionId);
            
            // If session not found or expired, create a new one
            if ($session === null || $session->isExpired($this->config->get('session_timeout'))) {
                if ($request->getMethod() !== 'POST' || $this->isInitializeRequest($request)) {
                    // For non-POST or initialize requests, create a new session
                    $session = $this->createSession();
                } else {
                    // For other requests with invalid session, return 404
                    return HttpMessage::createJsonResponse(
                        ['error' => 'Session not found or expired'],
                        404
                    );
                }
            }
        } else {
            // No session ID provided
            if ($request->getMethod() !== 'POST' || $this->isInitializeRequest($request)) {
                // For non-POST or initialize requests, create a new session
                $session = $this->createSession();
            } else {
                // For other requests without session ID, return 400
                return HttpMessage::createJsonResponse(
                    ['error' => 'Session ID required'],
                    400
                );
            }
        }
        
        // Set current session for this request
        $this->currentSessionId = $session->getId();

        // Set last used session
        $this->lastUsedSession = $session;

        
        // Process request based on HTTP method
        $response = match (strtoupper($request->getMethod())) {
            'POST' => $this->handlePostRequest($request, $session),
            'GET' => $this->handleGetRequest($request, $session),
            'DELETE' => $this->handleDeleteRequest($request, $session),
            default => HttpMessage::createJsonResponse(
                ['error' => 'Method not allowed'],
                405
            )->setHeader('Allow', 'GET, POST, DELETE')
        };
        
        // Add session ID header to response if we have a session
        if ($session !== null) {
            $response->setHeader('Mcp-Session-Id', $session->getId());
        }
        
        return $response;
    }
    
    /**
     * Handle a POST request.
     *
     * @param HttpMessage $request Request message
     * @param HttpSession $session Session
     * @return HttpMessage Response message
     */
    private function handlePostRequest(HttpMessage $request, HttpSession $session): HttpMessage
    {
        if ($auth = $this->authorizeRequest($request, $session)) {
            return $auth;
        }
        // Get and validate content type
        $contentType = $request->getHeader('Content-Type');
        if ($contentType === null || stripos($contentType, 'application/json') === false) {
            return HttpMessage::createJsonResponse(
                ['error' => 'Unsupported Media Type'],
                415
            );
        }
        
        // Get and validate body
        $body = $request->getBody();
        if ($body === null || $body === '') {
            return HttpMessage::createJsonResponse(
                ['error' => 'Empty request body'],
                400
            );
        }
        
        try {
            // Decode JSON
            $jsonData = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            
            // Process JSON-RPC message
            $containsRequests = $this->processJsonRpcData($jsonData, $session);
            
            // Update session activity
            $session->updateActivity();
            $this->sessionStore->save($session);
            
            if (!$containsRequests) {
                // Only notifications or responses, return 202 Accepted
                return HttpMessage::createEmptyResponse(202);
            }
            
            // Check preferred response format
            $acceptHeader = $request->getHeader('Accept');
            $prefersSse = $acceptHeader !== null &&
                         stripos($acceptHeader, 'text/event-stream') !== false &&
                         $this->config->isSseEnabled();

            if ($prefersSse && Environment::canSupportSse()) {
                // SSE mode: mint a stream id, remember the originating request
                // so emitSseResponse() can recognise its final response event,
                // and tell the runner (via lastResponseMode) to call
                // emitSseResponse() instead of createJsonResponse().
                $this->lastResponseMode = 'sse';
                $this->currentStreamId = StreamId::mint();
                $this->currentOriginatingRequestId = $this->extractFirstRequestId($jsonData);
            }

            // Return a 200 shell; the runner builds the real body after the
            // server session processes the incoming queue.
            return HttpMessage::createEmptyResponse(200);
        } catch (\JsonException $e) {
            // JSON parse error
            return HttpMessage::createJsonResponse(
                ['error' => 'JSON parse error: ' . $e->getMessage()],
                400
            );
        } catch (\Exception $e) {
            // Other errors
            return HttpMessage::createJsonResponse(
                ['error' => 'Internal server error: ' . $e->getMessage()],
                500
            );
        }
    }
    
    /**
     * Handle a GET request.
     *
     * @param HttpMessage $request Request message
     * @param HttpSession $session Session
     * @return HttpMessage Response message
     */
    private function handleGetRequest(HttpMessage $request, HttpSession $session): HttpMessage
    {
        if ($auth = $this->authorizeRequest($request, $session)) {
            return $auth;
        }
        $path = parse_url($request->getUri() ?? '/', PHP_URL_PATH);
        if (stripos($path, $this->config->getResourceMetadataPath()) !== false) {
            return HttpMessage::createJsonResponse($this->getProtectedResourceMetadata());
        }

        // Check if SSE is supported and requested
        $acceptHeader = $request->getHeader('Accept');
        $wantsSse = $acceptHeader !== null &&
                   stripos($acceptHeader, 'text/event-stream') !== false;

        if (!$wantsSse || !$this->config->isSseEnabled() || !Environment::canSupportSse()) {
            return HttpMessage::createJsonResponse(
                ['error' => 'Method not allowed'],
                405
            )->setHeader('Allow', 'POST, DELETE');
        }

        $lastEventId = $request->getHeader('Last-Event-ID');
        if ($lastEventId !== null && $lastEventId !== '') {
            return $this->emitSseReplay($session, $lastEventId);
        }

        // No Last-Event-ID: standalone GET stream. On PHP-FPM there is no
        // background worker to push idle server-initiated messages, so by
        // default this emits priming + retry and closes immediately.
        // Clients that need mid-operation progress can still POST the
        // originating request and reconnect here with Last-Event-ID.
        return $this->emitStandaloneGetSse($session);
    }

    /**
     * Handle a DELETE request.
     *
     * @param HttpMessage $request Request message
     * @param HttpSession $session Session
     * @return HttpMessage Response message
     */
    private function handleDeleteRequest(HttpMessage $request, HttpSession $session): HttpMessage
    {
        if ($auth = $this->authorizeRequest($request, $session)) {
            return $auth;
        }
        // Expire the session
        $session->expire();
        
        // Remove from active sessions
        unset($this->sessions[$session->getId()]);
        
        // Clean up any pending messages
        $this->messageQueue->cleanupExpiredSessions([$session->getId()]);
        
        return HttpMessage::createEmptyResponse(204);
    }
    
    /**
     * Process JSON-RPC data.
     *
     * @param mixed $data JSON-RPC data
     * @param HttpSession $session Session
     * @return bool True if the data contains requests
     * @throws \InvalidArgumentException If the data is invalid.
     */
    private function processJsonRpcData($data, HttpSession $session): bool
    {
        return $this->processSingleMessage($data, $session);
    }
    
    /**
     * Process a single JSON-RPC message.
     *
     * @param array<string, mixed> $data Message data
     * @param HttpSession $session Session
     * @return bool True if the message is a request
     * @throws \InvalidArgumentException If the data is invalid.
     */
    private function processSingleMessage(array $data, HttpSession $session): bool
    {
        // Verify JSON-RPC version
        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            throw new \InvalidArgumentException('Invalid JSON-RPC version');
        }
        
        // Classify message type
        $hasId = isset($data['id']);
        $hasMethod = isset($data['method']);
        $hasResult = isset($data['result']);
        $hasError = isset($data['error']);
        
        try {
            $message = null;
            
            if ($hasError && $hasId) {
                // JSON-RPC error response
                $message = $this->createErrorMessage($data);
                $isRequest = false;
            } elseif ($hasMethod && $hasId) {
                // JSON-RPC request
                $message = $this->createRequestMessage($data);
                $isRequest = true;
            } elseif ($hasMethod && !$hasId) {
                // JSON-RPC notification
                $message = $this->createNotificationMessage($data);
                $isRequest = false;
            } elseif ($hasResult && $hasId) {
                // JSON-RPC response
                $message = $this->createResponseMessage($data);
                $isRequest = false;
            } else {
                throw new \InvalidArgumentException('Invalid JSON-RPC message structure');
            }
            
            // Queue the message for processing
            if ($message !== null) {
                $this->messageQueue->queueIncoming($message);
            }
            
            return $isRequest;
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Error creating JSON-RPC message: ' . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Create a JSON-RPC request message.
     *
     * @param array<string, mixed> $data Request data
     * @return JsonRpcMessage Request message
     */
    private function createRequestMessage(array $data): JsonRpcMessage
    {
        $id = new RequestId($data['id']);
        $method = $data['method'];
        
        // Create request params if present
        $params = isset($data['params']) ? $this->createRequestParams($data['params']) : null;
        
        $request = new JSONRPCRequest(
            jsonrpc: '2.0',
            id: $id,
            method: $method,
            params: $params
        );
        
        return new JsonRpcMessage($request);
    }
    
    /**
     * Create a JSON-RPC notification message.
     *
     * @param array<string, mixed> $data Notification data
     * @return JsonRpcMessage Notification message
     */
    private function createNotificationMessage(array $data): JsonRpcMessage
    {
        $method = $data['method'];
        
        // Create notification params if present
        $params = isset($data['params']) ? $this->createNotificationParams($data['params']) : null;
        
        $notification = new JSONRPCNotification(
            jsonrpc: '2.0',
            method: $method,
            params: $params
        );
        
        return new JsonRpcMessage($notification);
    }
    
    /**
     * Create a JSON-RPC response message.
     *
     * The decoded result is left as the raw associative array (including
     * any _meta payload). Typed Result subclasses are constructed
     * downstream by sendRequest()'s response handler in BaseSession, which
     * is the only place that knows which Result subclass to instantiate.
     * Wrapping in a generic Result here would either drop _meta or assign
     * a raw array to its typed ?Meta property and trip a TypeError.
     *
     * @param array<string, mixed> $data Response data
     * @return JsonRpcMessage Response message
     */
    private function createResponseMessage(array $data): JsonRpcMessage
    {
        $id = new RequestId($data['id']);
        $resultArr = is_array($data['result'] ?? null) ? $data['result'] : [];

        $response = new JSONRPCResponse(
            jsonrpc: '2.0',
            id: $id,
            result: $resultArr
        );

        return new JsonRpcMessage($response);
    }
    
    /**
     * Create a JSON-RPC error message.
     *
     * @param array<string, mixed> $data Error data
     * @return JsonRpcMessage Error message
     */
    private function createErrorMessage(array $data): JsonRpcMessage
    {
        $id = new RequestId($data['id']);
        $error = $data['error'];
        
        $errorObj = new JsonRpcErrorObject(
            code: $error['code'],
            message: $error['message'],
            data: $error['data'] ?? null
        );
        
        $errorResponse = new JSONRPCError(
            jsonrpc: '2.0',
            id: $id,
            error: $errorObj
        );
        
        return new JsonRpcMessage($errorResponse);
    }
    
    /**
     * Create request parameters from an array.
     *
     * @param array<string, mixed> $params Parameters array
     * @return \Mcp\Types\RequestParams Request parameters
     */
    private function createRequestParams(array $params): \Mcp\Types\RequestParams
    {
        $requestParams = new \Mcp\Types\RequestParams();
        
        // Handle metadata if present
        if (isset($params['_meta'])) {
            $meta = new \Mcp\Types\Meta();
            foreach ($params['_meta'] as $key => $value) {
                $meta->$key = $value;
            }
            $requestParams->_meta = $meta;
        }
        
        // Copy other parameters
        foreach ($params as $key => $value) {
            if ($key !== '_meta') {
                $requestParams->$key = $value;
            }
        }
        
        return $requestParams;
    }
    
    /**
     * Create notification parameters from an array.
     *
     * @param array<string, mixed> $params Parameters array
     * @return \Mcp\Types\NotificationParams Notification parameters
     */
    private function createNotificationParams(array $params): \Mcp\Types\NotificationParams
    {
        $notificationParams = new \Mcp\Types\NotificationParams();
        
        // Handle metadata if present
        if (isset($params['_meta'])) {
            $meta = new \Mcp\Types\Meta();
            foreach ($params['_meta'] as $key => $value) {
                $meta->$key = $value;
            }
            $notificationParams->_meta = $meta;
        }
        
        // Copy other parameters
        foreach ($params as $key => $value) {
            if ($key !== '_meta') {
                $notificationParams->$key = $value;
            }
        }
        
        return $notificationParams;
    }
    
    /**
     * Response mode chosen for the current request, if any.
     *
     * Returns 'sse' when handlePostRequest selected SSE streaming; null
     * otherwise. The runner uses this to decide between createJsonResponse()
     * and emitSseResponse().
     */
    public function lastResponseMode(): ?string
    {
        return $this->lastResponseMode;
    }

    /**
     * Stream id minted for the current SSE-mode POST, or null.
     */
    public function currentStreamId(): ?string
    {
        return $this->currentStreamId;
    }

    /**
     * Build a resumable SSE response for the current POST stream.
     *
     * Emits a priming event (id=<streamId>:0, empty data, retry field),
     * then one event per queued outgoing JSON-RPC message with incrementing
     * seq. When an event carries the final JSON-RPC response for the
     * originating request, the stream is marked completed in the registry;
     * otherwise it stays open so the client can reconnect via GET with
     * Last-Event-ID to pick up subsequent events.
     *
     * Every emitted frame is appended to the session's event log so future
     * replay is possible.
     *
     * Progress-streaming trade-off: this implementation builds the full SSE
     * body once the server session has drained its incoming queue (i.e.
     * after the tool handler has returned). The wire format is spec-correct
     * and every progress notification appears in the body with its own
     * event id, but the client cannot observe progress *while* the handler
     * is executing — the body is shipped in one HTTP response at the end.
     * Real-time mid-execution streaming would require holding a long-lived
     * request open and flushing per-event, which standard PHP hosting
     * (cPanel/FPM) cannot reliably do. The resumable design is the
     * substitute: a client that expects a long-running tool can issue the
     * POST, and if the connection drops before the final response, reconnect
     * via GET + Last-Event-ID to pick up events the server already logged.
     */
    public function emitSseResponse(HttpSession $session): HttpMessage
    {
        $streamId = $this->currentStreamId;
        if ($streamId === null) {
            // Defensive: should not happen when caller honors lastResponseMode.
            return $this->createJsonResponse($session);
        }

        $state = SseSessionState::loadFrom($session, $this->config->getSseEventLogCapacity());
        $registry = $state->getRegistry();
        $log = $state->getLog();

        $registry->open($streamId, StreamRegistry::KIND_POST, $this->currentOriginatingRequestId);

        $body = '';
        $emitter = new SseEmitter(
            function (string $s) use (&$body): void {
                $body .= $s;
            },
            static fn (): bool => false,
        );

        // Priming event: spec SHOULD send an event with an id + empty data to
        // prime the client's Last-Event-ID before any real payload.
        $primingFrame = new SseFrame(
            id: StreamId::formatEventId($streamId, 0),
            event: null,
            retryMs: $this->config->getSseRetryMs(),
            data: '',
        );
        $emitter->emit($primingFrame);
        $log->append($streamId, 0, $primingFrame);

        $seq = 0;
        $completed = false;
        foreach ($this->messageQueue->flushOutgoing($session->getId()) as $msg) {
            $seq++;
            try {
                $payload = \json_encode($msg->message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
            } catch (\JsonException) {
                continue;
            }

            $frame = new SseFrame(
                id: StreamId::formatEventId($streamId, $seq),
                event: null,
                retryMs: null,
                data: (string) $payload,
            );
            $emitter->emit($frame);
            $log->append($streamId, $seq, $frame);

            if ($this->isFinalResponseFor($msg, $this->currentOriginatingRequestId)) {
                $registry->markCompleted($streamId);
                $completed = true;
                // Spec: SHOULD terminate the SSE stream after the JSON-RPC
                // response has been sent. We stop appending further events
                // for this stream in this response.
                break;
            }
        }

        $registry->setLastSeq($streamId, $seq);
        $state->saveTo($session);

        $response = new HttpMessage($body);
        $response->setStatusCode(200);
        $response->setHeader('Content-Type', 'text/event-stream');
        $response->setHeader('Cache-Control', 'no-cache, no-transform');
        $response->setHeader('X-Accel-Buffering', 'no');
        return $response;
    }

    /**
     * Replay previously-emitted SSE frames for a disconnected stream.
     *
     * Per the 2025-11-25 Streamable HTTP spec, clients resume via GET with
     * a Last-Event-ID header. The server MAY replay messages that would
     * have been sent on THAT stream, and MUST NOT leak events from other
     * streams. Both invariants are enforced by StreamEventLog::replaySince,
     * which filters strictly by streamId.
     */
    private function emitSseReplay(HttpSession $session, string $lastEventId): HttpMessage
    {
        $parsed = StreamId::parse($lastEventId);
        if ($parsed === null) {
            return HttpMessage::createJsonResponse(
                ['error' => 'Invalid Last-Event-ID'],
                400
            );
        }

        $streamId = $parsed['streamId'];
        $cursor = $parsed['seq'];

        $state = SseSessionState::loadFrom($session, $this->config->getSseEventLogCapacity());
        $record = $state->getRegistry()->find($streamId);
        if ($record === null) {
            // Unknown stream (or from a different session): tell the client
            // the session is effectively gone so it can re-initialize.
            return HttpMessage::createJsonResponse(
                ['error' => 'Stream not found'],
                404
            );
        }

        $body = '';
        $emitter = new SseEmitter(
            function (string $s) use (&$body): void {
                $body .= $s;
            },
            static fn (): bool => false,
        );

        foreach ($state->getLog()->replaySince($streamId, $cursor) as $entry) {
            $emitter->emit($entry['frame']);
        }

        $response = new HttpMessage($body);
        $response->setStatusCode(200);
        $response->setHeader('Content-Type', 'text/event-stream');
        $response->setHeader('Cache-Control', 'no-cache, no-transform');
        $response->setHeader('X-Accel-Buffering', 'no');
        return $response;
    }

    /**
     * Open a standalone GET SSE stream with no Last-Event-ID.
     *
     * The priming event + retry hint are always emitted. When
     * `sse_standalone_get_idle_ms` is 0 (the default on PHP-FPM, which has no
     * background worker), the response closes right after priming. When the
     * option is > 0, the handler holds the request open for that window and
     * drains any server-initiated messages queued for this session into the
     * SSE body, appending them to the event log so a later GET reconnect
     * with Last-Event-ID can replay them. The window is capped against
     * `max_execution_time` so shared-hosting processes are not killed mid-wait.
     */
    private function emitStandaloneGetSse(HttpSession $session): HttpMessage
    {
        $streamId = StreamId::mint();

        $state = SseSessionState::loadFrom($session, $this->config->getSseEventLogCapacity());
        $state->getRegistry()->open($streamId, StreamRegistry::KIND_GET, null);

        $body = '';
        $emitter = new SseEmitter(
            function (string $s) use (&$body): void {
                $body .= $s;
            },
            static fn (): bool => false,
        );

        $primingFrame = new SseFrame(
            id: StreamId::formatEventId($streamId, 0),
            event: null,
            retryMs: $this->config->getSseRetryMs(),
            data: '',
        );
        $emitter->emit($primingFrame);
        $state->getLog()->append($streamId, 0, $primingFrame);

        $seq = 0;
        $idleMs = $this->config->getSseStandaloneGetIdleMs();
        if ($idleMs > 0) {
            // Cap the idle window against max_execution_time so the worker
            // isn't killed mid-wait on shared hosts with short limits.
            $maxExec = Environment::detectMaxExecutionTime();
            if ($maxExec > 0) {
                $idleMs = (int) \min($idleMs, (int) ($maxExec * 1000 * 0.75));
            }

            $deadlineNs = \hrtime(true) + ($idleMs * 1_000_000);
            $tickUs = 200_000;
            while (\hrtime(true) < $deadlineNs) {
                // Best-effort abort check. In buffered mode this rarely fires
                // before the response is flushed, but costs nothing to probe.
                if (\connection_aborted()) {
                    break;
                }
                $remainingUs = (int) (($deadlineNs - \hrtime(true)) / 1000);
                if ($remainingUs <= 0) {
                    break;
                }
                \usleep((int) \min($tickUs, $remainingUs));

                foreach ($this->messageQueue->flushOutgoing($session->getId()) as $msg) {
                    $seq++;
                    try {
                        $payload = \json_encode($msg->message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
                    } catch (\JsonException) {
                        continue;
                    }

                    $frame = new SseFrame(
                        id: StreamId::formatEventId($streamId, $seq),
                        event: null,
                        retryMs: null,
                        data: (string) $payload,
                    );
                    $emitter->emit($frame);
                    $state->getLog()->append($streamId, $seq, $frame);
                }
            }
            $state->getRegistry()->setLastSeq($streamId, $seq);
        }

        $state->saveTo($session);
        $this->sessionStore->save($session);

        $response = new HttpMessage($body);
        $response->setStatusCode(200);
        $response->setHeader('Content-Type', 'text/event-stream');
        $response->setHeader('Cache-Control', 'no-cache, no-transform');
        $response->setHeader('X-Accel-Buffering', 'no');
        return $response;
    }

    /**
     * Pick the JSON-RPC request id from a decoded POST body.
     *
     * Only requests (method + id) count — notifications and responses have
     * no id to report. For JSON-RPC batches, the first request's id is
     * used; SSE response emission for batches degrades gracefully because
     * no single originating id can capture "final response" semantics.
     *
     * @return string|int|null
     */
    private function extractFirstRequestId(mixed $jsonData): string|int|null
    {
        if (\is_array($jsonData) && \array_is_list($jsonData)) {
            foreach ($jsonData as $item) {
                $id = $this->extractFirstRequestId($item);
                if ($id !== null) {
                    return $id;
                }
            }
            return null;
        }

        if (\is_array($jsonData)
            && isset($jsonData['method'])
            && \array_key_exists('id', $jsonData)
            && $jsonData['id'] !== null
        ) {
            $id = $jsonData['id'];
            if (\is_string($id) || \is_int($id)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * True when a queued outgoing message is the JSON-RPC response (or error)
     * for the stream's originating client request.
     */
    private function isFinalResponseFor(JsonRpcMessage $msg, string|int|null $originatingId): bool
    {
        if ($originatingId === null) {
            return false;
        }

        $inner = $msg->message;
        if (!($inner instanceof JSONRPCResponse) && !($inner instanceof JSONRPCError)) {
            return false;
        }

        $idValue = $inner->id->getValue();
        return $idValue === $originatingId;
    }

    /**
     * Create a JSON response from pending messages.
     *
     * @param HttpSession $session Session
     * @return HttpMessage Response message
     */
    public function createJsonResponse(HttpSession $session): HttpMessage
    {
        // Get pending messages for the session
        $pendingMessages = $this->messageQueue->flushOutgoing($session->getId());

        if (empty($pendingMessages)) {
            // No pending messages, return 202 Accepted
            return HttpMessage::createEmptyResponse(202);
        }

        // Single message — return it directly
        if (count($pendingMessages) === 1) {
            return HttpMessage::createJsonResponse($pendingMessages[0]->message, 200);
        }

        // Multiple messages — return as a JSON array
        $data = [];
        foreach ($pendingMessages as $msg) {
            $data[] = $msg->message;
        }
        return HttpMessage::createJsonResponse($data, 200);
    }

    /**
     * Generate OAuth protected resource metadata for this server.
     *
     * @return array<string, mixed>
     */
    private function getProtectedResourceMetadata(): array
    {
        $metadata = [];

        $resource = $this->config->getResource();
        if ($resource !== null) {
            $metadata['resource'] = $resource;
        }

        $servers = $this->config->getAuthorizationServers();
        if (!empty($servers)) {
            $metadata['authorization_servers'] = $servers;
        }

        return $metadata;
    }

    /**
     * Build the full URL to the OAuth protected resource metadata endpoint.
     *
     * @param HttpMessage $request Current HTTP request
     */
    private function getResourceMetadataUrl(HttpMessage $request): string
    {
        $path = $this->config->getResourceMetadataPath();

        // Determine scheme from forwarded headers or HTTPS flag
        $scheme = 'http';
        $proto = $request->getHeader('x-forwarded-proto')
            ?? $request->getHeader('X-Forwarded-Proto');
        if ($proto !== null) {
            $scheme = strtolower(trim(explode(',', $proto)[0]));
        } elseif (($request->getHeader('HTTPS') ?? $_SERVER['HTTPS'] ?? 'off') !== 'off') {
            $scheme = 'https';
        }

        // Determine host either from header or configured host/port
        $host = $request->getHeader('host');
        if ($host === null) {
            $host = $this->config->get('host');
            $port = (int)($this->config->get('port') ?? 80);
            if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
                $host .= ':' . $port;
            }
        }

        return $scheme . '://' . rtrim($host, '/') . $path;
    }
    
    /**
     * Get a session by ID.
     *
     * @param string $sessionId Session ID
     * @return HttpSession|null Session or null if not found
     */
    public function getSession(string $sessionId): ?HttpSession
    {
        // Check if we already have it cached
        if (isset($this->sessions[$sessionId])) {
            $session = $this->sessions[$sessionId];
            if ($session->isExpired($this->config->get('session_timeout'))) {
                $session->expire();
                $this->sessionStore->delete($sessionId);
                unset($this->sessions[$sessionId]);
                return null;
            }
            return $session;
        }

        // Otherwise, try loading from store
        $session = $this->sessionStore->load($sessionId);
        if ($session && !$session->isExpired($this->config->get('session_timeout'))) {
            $this->sessions[$sessionId] = $session;
            return $session;
        }

        // If expired or not found, return null
        if ($session) {
            $session->expire();
            $this->sessionStore->delete($sessionId);
        }
        return null;
    }
    
    /**
     * Create a new session.
     *
     * @return HttpSession New session
     */
    public function createSession(): HttpSession
    {
        $session = new HttpSession();
        $session->activate();

        $this->sessions[$session->getId()] = $session;
        $this->sessionStore->save($session);
        return $session;
    }

    /**
     * Get the last used session.
     *
     * @return HttpSession|null Last used session or null if none
     */
    public function getLastUsedSession(): ?HttpSession
    {
        return $this->lastUsedSession;
    }

    /**
     * Save the last used session.
     *
     * @param HttpSession $session Session to save
     * @return void
     */
    public function saveSession(HttpSession $session): void
    {
        $this->sessionStore->save($session);
    }

    /**
     * Perform OAuth authorization for the given request if enabled.
     *
     * Validates the Bearer token from the Authorization header. The validation includes:
     * - Token signature verification (via the configured TokenValidator)
     * - Issuer (iss) and audience (aud) claim validation
     * - Token expiration (exp) checking
     * - Optional: Scope claim validation (if 'required_scope' is configured)
     *
     * Security Note: The audience (aud) claim validation is the primary access control
     * mechanism. It ensures that only tokens explicitly issued for this MCP server
     * are accepted. Scope checking provides additional fine-grained access control
     * but is optional since the aud claim already restricts token usage.
     *
     * @return HttpMessage|null A response on failure or null on success.
     */
    private function authorizeRequest(HttpMessage $request, HttpSession $session): ?HttpMessage
    {
        if (!$this->config->isAuthEnabled()) {
            return null;
        }

        // Check for Bearer token in Authorization header
        $authHeader = $request->getHeader('Authorization');
        if ($authHeader === null || !preg_match('/^Bearer\s+(\S+)/i', $authHeader, $m)) {
            $url = $this->getResourceMetadataUrl($request);
            return HttpMessage::createEmptyResponse(401)
                ->setHeader('WWW-Authenticate', 'Bearer resource_metadata="' . $url . '"');
        }

        // Get the token validator
        $validator = $this->validator ?? $this->config->getTokenValidator();
        if ($validator === null) {
            return HttpMessage::createJsonResponse(['error' => 'No token validator configured'], 500);
        }

        // Validate the token (signature, iss, aud, exp, etc.)
        $result = $validator->validate($m[1]);
        if (!$result->valid) {
            $url = $this->getResourceMetadataUrl($request);
            $errorDesc = $result->error ?? 'invalid_token';
            return HttpMessage::createJsonResponse([
                'error' => 'invalid_token',
                'error_description' => $errorDesc
            ], 401)
                ->setHeader('WWW-Authenticate', 'Bearer error="invalid_token", error_description="' . addslashes($errorDesc) . '", resource_metadata="' . $url . '"');
        }

        // Optional: Check for required scope if configured
        // By default, scope checking is disabled. The audience (aud) claim validation
        // already ensures the token was issued for this specific API.
        $requiredScope = $this->config->get('required_scope');
        if ($requiredScope !== null && $requiredScope !== '' && $requiredScope !== false) {
            $tokenScope = $result->claims['scope'] ?? '';
            if (strpos((string)$tokenScope, (string)$requiredScope) === false) {
                return HttpMessage::createJsonResponse([
                    'error' => 'insufficient_scope',
                    'error_description' => "Token missing required scope: {$requiredScope}",
                    'required_scope' => $requiredScope
                ], 403)
                    ->setHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="' . $requiredScope . '"');
            }
        }

        // Store the validated claims in the session for later use
        $session->setMetadata('oauth_claims', $result->claims);
        return null;
    }

    /**
     * Validate the Origin header to prevent DNS rebinding attacks.
     *
     * Per the MCP spec: servers MUST validate the Origin header on all incoming
     * connections. If the Origin header is present and invalid, servers MUST
     * respond with HTTP 403 Forbidden.
     *
     * Validation is active when the 'allowed_origins' config option is set to a
     * non-empty array of allowed hostnames (port-agnostic, e.g. ['localhost',
     * '127.0.0.1', '::1']). McpServer auto-enables this for localhost servers.
     *
     * @return HttpMessage|null A 403 response on rejection, or null to allow
     */
    private function validateOrigin(HttpMessage $request): ?HttpMessage
    {
        $allowedOrigins = $this->config->get('allowed_origins');
        if ($allowedOrigins === null || $allowedOrigins === []) {
            return null; // No allowlist configured — validation disabled
        }

        $origin = $request->getHeader('origin');
        if ($origin === null) {
            return null; // No Origin header — non-browser client, allow
        }

        // Extract hostname from Origin (port-agnostic, matching TS SDK pattern)
        $host = parse_url($origin, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return HttpMessage::createJsonResponse(
                ['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Forbidden: invalid Origin header']],
                403
            );
        }

        // Normalize: strip IPv6 brackets (parse_url returns [::1], normalize to ::1)
        // and lowercase. Apply the same transform to configured entries so users
        // can pass '[::1]' or mixed-case hostnames without silent non-match.
        $hostname = strtolower(trim($host, '[]'));

        $allowedNormalized = array_map(
            static fn ($entry): string => strtolower(trim((string)$entry, '[]')),
            $allowedOrigins
        );

        if (!in_array($hostname, $allowedNormalized, true)) {
            return HttpMessage::createJsonResponse(
                ['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Forbidden: Origin not allowed']],
                403
            );
        }

        return null;
    }

    /**
     * Clean up expired sessions.
     *
     * @return void
     */
    private function cleanupExpiredSessions(): void
    {
        $now = time();
        
        // Only perform cleanup periodically
        if ($now - $this->lastSessionCleanup < $this->sessionCleanupInterval) {
            return;
        }
        
        $this->lastSessionCleanup = $now;
        $sessionTimeout = $this->config->get('session_timeout');
        $expiredSessionIds = [];
        
        foreach ($this->sessions as $sessionId => $session) {
            if ($session->isExpired($sessionTimeout)) {
                $expiredSessionIds[] = $sessionId;
                unset($this->sessions[$sessionId]);
            }
        }
        
        // Clean up message queues for expired sessions
        if (!empty($expiredSessionIds)) {
            $this->messageQueue->cleanupExpiredSessions($expiredSessionIds);
        }
    }
    
    /**
     * Check if a request is an initialize request.
     *
     * @param HttpMessage $request Request message
     * @return bool True if the request is an initialize request
     */
    private function isInitializeRequest(HttpMessage $request): bool
    {
        // Only POST requests can be initialize requests
        if ($request->getMethod() !== 'POST') {
            return false;
        }
        
        // Get and validate body
        $body = $request->getBody();
        if ($body === null || $body === '') {
            return false;
        }
        
        try {
            // Decode JSON
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            
            return $this->isInitializeMessage($data);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Check if a message is an initialize message.
     *
     * @param array<string, mixed> $message Message data
     * @return bool True if the message is an initialize message
     */
    private function isInitializeMessage(array $message): bool
    {
        return isset($message['method']) && $message['method'] === 'initialize';
    }
    
    /**
     * Get configuration.
     *
     * @return Config Configuration
     */
    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Set a token validator.
     */
    public function setTokenValidator(TokenValidatorInterface $validator): void
    {
        $this->validator = $validator;
    }

    /**
     * Get the token validator if configured.
     */
    public function getTokenValidator(): ?TokenValidatorInterface
    {
        return $this->validator;
    }

    /**
     * Check if the transport is started.
     *
     * @return bool True if the transport is started
     */ 
    public function isStarted(): bool
    {
        return $this->isStarted;
    }

}
