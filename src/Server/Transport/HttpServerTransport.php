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
use Mcp\Shared\Version;
use Mcp\Server\Transport\Http\Config;
use Mcp\Server\Transport\Http\Environment;
use Mcp\Server\Transport\Http\HttpIoInterface;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Server\Transport\Http\HttpSession;
use Mcp\Server\Transport\Http\InMemorySessionStore;
use Mcp\Server\Transport\Http\MessageQueue;
use Mcp\Server\Transport\Http\NativePhpIo;
use Mcp\Server\Transport\Http\SessionStoreInterface;
use Mcp\Server\Transport\Http\StreamedHttpMessage;
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
     * Streaming SSE emission state. When active, writeMessage() flushes each
     * JSON-RPC message to php://output as its own SSE frame instead of
     * queuing it for the buffered emitter. The fields are only meaningful
     * between beginStreamingSseOutput() and finalizeStreamingSse().
     */
    private bool $streamingActive = false;

    private ?SseEmitter $streamingEmitter = null;

    private ?SseSessionState $streamingState = null;

    private ?HttpSession $streamingSession = null;

    private int $streamingSeq = 0;

    private bool $streamingFinalEmitted = false;

    /**
     * Set when a server-to-client JSONRPCRequest (sampling, elicitation)
     * is flushed on the current stream. Presence of such a request means
     * the handler legitimately suspended and the final response will
     * arrive on a later POST — finalizeStreamingSse() must not synthesize
     * an error frame or mark the stream completed in this case.
     */
    private bool $streamingSuspensionDetected = false;

    private bool $streamingShutdownGuarded = false;

    /**
     * SAPI side-effect adapter. All direct PHP output (headers, body, flush,
     * abort handling, shutdown registration) flows through this interface so
     * the transport can be embedded in non-standard hosts and exercised by
     * tests without touching php://output.
     */
    private HttpIoInterface $io;

    /**
     * Originating request id of the handler currently being RESUMED after a
     * suspend (e.g., inside HttpServerSession::handleElicitationResponse).
     * While set, every outgoing message — response, notification, nested
     * server→client request — is appended to the stream that originally
     * carried that request, so related frames stay on the originating
     * stream per spec §5.6.5 ("messages SHOULD relate to the originating
     * client request"). Cleared when the resumed handler returns.
     */
    private string|int|null $resumeContextOriginatingId = null;
    
    /**
     * Constructor.
     *
     * @param array<string, mixed> $options Configuration options
     */
    public function __construct(
        array $options = [],
        ?SessionStoreInterface $sessionStore = null,
        ?TokenValidatorInterface $validator = null,
        ?HttpIoInterface $io = null
    ) {
        $this->config = new Config($options);
        $this->validator = $validator ?? $this->config->getTokenValidator();
        $this->messageQueue = new MessageQueue(
            $this->config->get('max_queue_size')
        );

        // If no store passed, default to an in-memory store
        $this->sessionStore = $sessionStore ?? new InMemorySessionStore();

        $this->io = $io ?? new NativePhpIo();
    }

    /**
     * Swap the SAPI adapter after construction. Intended for embedders that
     * build the transport eagerly (e.g. inside a DI container) and inject a
     * per-request adapter once the HTTP context is known.
     */
    public function setIo(HttpIoInterface $io): void
    {
        $this->io = $io;
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

        // Streaming SSE short-circuit: push the frame straight to the wire
        // (and the event log) before the handler returns. Bypassing the
        // outgoing queue is what lets clients observe progress notifications
        // mid-execution, as the spec intends.
        if ($this->streamingActive) {
            $this->emitStreamingFrame($message);
            return;
        }

        // Resume-context short-circuit. When HttpServerSession is re-invoking
        // a suspended handler (typically inside handleElicitationResponse),
        // it sets resumeContextOriginatingId to the original tools/call id
        // for the duration of the call. While that context is active, ALL
        // outputs — responses, progress notifications, and any chained
        // server→client requests — are appended to the stream that carried
        // the original request, matching spec §5.6.5's "SHOULD relate to
        // the originating client request" on that stream. Without this
        // routing, notifications + chained requests would strand in the
        // outgoing queue and either drop on the 202 path or leak onto the
        // wrong HTTP response.
        if ($this->resumeContextOriginatingId !== null) {
            if ($this->appendToResumeStream($message, $this->resumeContextOriginatingId)) {
                return;
            }
        }

        // Response-only fallback. Even without an explicit resume context,
        // a response whose id matches an open stream belongs on that stream
        // — guards paths where the session writes a response outside the
        // documented handleElicitationResponse flow.
        $inner = $message->message;
        if ($inner instanceof JSONRPCResponse || $inner instanceof JSONRPCError) {
            if ($this->appendResponseToOpenStream($message, $inner)) {
                return;
            }
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

        // Spec 2025-11-25 §Transports: an invalid or unsupported
        // MCP-Protocol-Version header MUST be answered with 400 Bad Request.
        // Absence is handled leniently per the spec's backwards-compatibility
        // clause — the SDK's session already carries the negotiated version,
        // so there is no need to fabricate a fallback here.
        if ($rejection = $this->validateProtocolVersionHeader($request)) {
            return $rejection;
        }

        $path = parse_url($request->getUri() ?? '/', PHP_URL_PATH);
        if ($request->getMethod() === 'GET' && stripos($path, $this->config->getResourceMetadataPath()) !== false) {
            return HttpMessage::createJsonResponse($this->getProtectedResourceMetadata());
        }

        // Extract session ID from request headers
        $sessionId = $request->getHeader('Mcp-Session-Id');
        $session = null;
        $method = strtoupper($request->getMethod() ?? '');
        $isInitializePost = $method === 'POST' && $this->isInitializeRequest($request);
        // Spec §5.8.2: servers that require a session id SHOULD respond to
        // non-initialization requests lacking Mcp-Session-Id with 400. The
        // strict path engages when SSE is enabled (the only mode in which an
        // anonymous GET could otherwise open an orphan event stream) — this
        // keeps byte-identical behavior for deployments that never opted in.
        $strictSessionEnforcement = $this->config->isSseEnabled();

        // Find or create session
        if ($sessionId !== null) {
            $session = $this->getSession($sessionId);

            // If session not found or expired, create a new one
            if ($session === null || $session->isExpired($this->config->get('session_timeout'))) {
                if ($isInitializePost) {
                    // Initialize requests re-establish the session fresh.
                    $session = $this->createSession();
                } else {
                    // For any other request with an unknown/expired session,
                    // 404 signals the client MUST start a new session via
                    // InitializeRequest without a session id (spec §5.8.4).
                    return HttpMessage::createJsonResponse(
                        ['error' => 'Session not found or expired'],
                        404
                    );
                }
            }
        } else {
            // No session ID provided
            if ($isInitializePost) {
                // Initialize is the only request allowed to create a session.
                $session = $this->createSession();
            } elseif ($method === 'POST') {
                // Non-initialize POST without a session id — existing behavior.
                return HttpMessage::createJsonResponse(
                    ['error' => 'Session ID required'],
                    400
                );
            } elseif ($strictSessionEnforcement) {
                // GET/DELETE without a session id when SSE is enabled: reject
                // per spec rather than auto-creating an orphan session that
                // would accept anonymous GET-SSE streams on an uninitialized
                // context.
                return HttpMessage::createJsonResponse(
                    ['error' => 'Session ID required'],
                    400
                );
            } else {
                // SSE disabled: preserve legacy lenient behavior (GET falls
                // through to the 405 path in handleGetRequest, DELETE expires
                // a freshly-created session).
                $session = $this->createSession();
            }
        }

        // GET/DELETE on a known-but-uninitialized session: the handshake is
        // a prerequisite for server-to-client channels. Returning 400 here
        // matches the TypeScript reference and makes it obvious the client
        // skipped `initialize`. Gated on strict enforcement so legacy
        // non-SSE deployments don't see behavior changes.
        if (
            $strictSessionEnforcement
            && ($method === 'GET' || $method === 'DELETE')
            && $session !== null
            && !$session->isInitialized()
        ) {
            return HttpMessage::createJsonResponse(
                ['error' => 'Session not initialized'],
                400
            );
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
     * Begin the streaming-SSE path for a POST that should flush frames as
     * the tool handler runs.
     *
     * Spec §5.6.1 says the server SHOULD immediately send a priming event
     * (event id + empty data) so the client has a Last-Event-ID in hand
     * before any real payload. `beginStreamingSseOutput()` commits to the
     * SSE response by writing status + headers + the priming frame BEFORE
     * the handler is invoked; from this point on writeMessage() flushes
     * each frame straight to php://output.
     *
     * Headers are sent through the configured HttpIoInterface rather than
     * returned on an HttpMessage because the runner needs to hand control
     * back to the server session while the HTTP response is mid-body. The
     * runner later sees a StreamedHttpMessage from finalizeStreamingSse()
     * and skips its body-emission path so we don't double-echo.
     *
     * On any runtime that can't reliably flush (see Environment::canStreamSse),
     * callers should not invoke this method — they should fall back to
     * emitSseResponse() which buffers the body and sends it at the end.
     *
     * @param HttpSession $session The HTTP session being streamed.
     * @param object|null $ioSink  Optional per-stream IO override.
     *                             - HttpIoInterface: fully overrides
     *                               $this->io for this stream; status,
     *                               headers, body, flush, and shutdown
     *                               registration all route to it.
     *                             - Legacy duck-typed object (write/
     *                               aborted only): wrapped in a no-op
     *                               shim for existing tests. New callers
     *                               should use HttpIoInterface or the
     *                               transport constructor instead.
     *                             - null: uses $this->io (production).
     */
    public function beginStreamingSseOutput(HttpSession $session, ?object $ioSink = null): void
    {
        $streamId = $this->currentStreamId;
        if ($streamId === null) {
            throw new \RuntimeException(
                'Cannot begin streaming SSE without a minted stream id'
            );
        }

        $state = SseSessionState::loadFrom($session, $this->config->getSseEventLogCapacity());
        $state->getRegistry()->open(
            $streamId,
            StreamRegistry::KIND_POST,
            $this->currentOriginatingRequestId
        );

        // Resolve which HttpIoInterface this stream will use. Priority:
        //   1. $ioSink is already an HttpIoInterface → use it directly;
        //      it fully overrides $this->io for this stream (status,
        //      headers, body, flush, shutdown).
        //   2. $ioSink is a duck-typed legacy object (write/aborted only)
        //      → wrap in a shim whose sendStatus/sendHeader/drain/flush/
        //        registerShutdownHandler are no-ops, preserving the pre-
        //        refactor contract of "tests inject a sink and bypass
        //        SAPI entirely" while still letting frames flow.
        //   3. $ioSink is null (production) → use $this->io, which
        //      defaults to NativePhpIo.
        $io = $this->resolveStreamingIo($ioSink);

        // Route status + headers through the resolved IO. For the legacy
        // duck-typed shim these are no-ops, matching the pre-refactor
        // behavior that the test path recorded no SAPI calls. For
        // production and for a direct HttpIoInterface embedder, this is
        // the only place the SSE response headers and status land.
        $capabilities = $this->detectStreamingRuntime();
        $this->sendStreamingHeaders($session, $capabilities, $io);

        $write = static function (string $s) use ($io): void {
            $io->write($s);
            $io->flush();
        };
        $aborted = static function () use ($io): bool {
            return $io->connectionAborted();
        };

        $emitter = new SseEmitter($write, $aborted);

        $primingFrame = new SseFrame(
            id: StreamId::formatEventId($streamId, 0),
            event: null,
            retryMs: $this->config->getSseRetryMs(),
            data: '',
        );
        $emitter->emit($primingFrame);
        $state->getLog()->append($streamId, 0, $primingFrame);
        $state->saveTo($session);
        $this->sessionStore->save($session);

        $this->streamingActive = true;
        $this->streamingEmitter = $emitter;
        $this->streamingState = $state;
        $this->streamingSession = $session;
        $this->streamingSeq = 0;
        $this->streamingFinalEmitted = false;
        $this->streamingSuspensionDetected = false;

        $this->registerStreamingShutdownGuard($io);
    }

    /**
     * Flush a single outgoing JSON-RPC message as an SSE frame while the
     * streaming path is active.
     *
     * The frame is appended to the event log and the session state is
     * persisted per-frame. That cost buys two spec-level properties:
     *   (a) a client reconnecting via GET + Last-Event-ID mid-handler can
     *       see frames that were emitted before the reconnect, provided the
     *       session store is shared across processes (e.g. FileSessionStore);
     *   (b) a fatal mid-handler still leaves a coherent log so the shutdown
     *       safety net can replay events that already reached the wire.
     */
    private function emitStreamingFrame(JsonRpcMessage $message): void
    {
        if (!$this->streamingActive
            || $this->streamingEmitter === null
            || $this->streamingState === null
            || $this->streamingSession === null
            || $this->currentStreamId === null
        ) {
            // Defensive: should not happen because writeMessage() only
            // routes here when streamingActive is true.
            return;
        }

        try {
            $payload = \json_encode(
                $message->message,
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES
            );
        } catch (\JsonException) {
            return;
        }

        $this->streamingSeq++;
        $frame = new SseFrame(
            id: StreamId::formatEventId($this->currentStreamId, $this->streamingSeq),
            event: null,
            retryMs: null,
            data: (string) $payload,
        );
        $this->streamingEmitter->emit($frame);
        $this->streamingState->getLog()->append(
            $this->currentStreamId,
            $this->streamingSeq,
            $frame
        );
        $this->streamingState->getRegistry()->setLastSeq(
            $this->currentStreamId,
            $this->streamingSeq
        );

        if ($this->isFinalResponseFor($message, $this->currentOriginatingRequestId)) {
            $this->streamingState->getRegistry()->markCompleted($this->currentStreamId);
            $this->streamingFinalEmitted = true;
        } elseif ($message->message instanceof JSONRPCRequest) {
            // Server→client request on this stream (elicitation/create or
            // sampling/createMessage). The handler has legitimately suspended
            // waiting for the client's response, which will arrive on a later
            // POST. finalizeStreamingSse() must not treat the missing final
            // response as an error in this case.
            $this->streamingSuspensionDetected = true;
        }

        // Persist after each frame. For in-memory session stores this is a
        // cheap map assignment; for FileSessionStore it's the cost that makes
        // mid-handler Last-Event-ID resume possible.
        $this->streamingState->saveTo($this->streamingSession);
        $this->sessionStore->save($this->streamingSession);
    }

    /**
     * Close out the streaming-SSE response after the server session has
     * drained its incoming queue. Returns a sentinel HttpMessage the runner
     * uses to skip its body-emission path without losing the session-id
     * header it still needs to set.
     *
     * Three cases are distinguished:
     *  1. The handler emitted a final JSON-RPC response for the originating
     *     request — mark the stream completed and return.
     *  2. The handler emitted a server→client request (elicitation/create,
     *     sampling/createMessage) and suspended — leave the stream open and
     *     do not synthesize a response, because the real response will be
     *     emitted on a later POST after the client replies. Completing the
     *     stream here would break the documented suspend/resume flow and
     *     work against the remaining server conformance items.
     *  3. Neither of the above — treat as a handler that failed without a
     *     response and synthesize a JSON-RPC -32603 so clients don't hang.
     */
    public function finalizeStreamingSse(HttpSession $session): HttpMessage
    {
        if (!$this->streamingActive
            || $this->streamingState === null
            || $this->streamingSession === null
            || $this->currentStreamId === null
        ) {
            // Defensive fallback: behave like a non-streaming 200.
            return $this->sentinelAlreadyEmittedResponse();
        }

        if ($this->streamingFinalEmitted) {
            // Case 1: handler completed. The final-response frame in
            // emitStreamingFrame already marked the stream completed.
        } elseif ($this->streamingSuspensionDetected) {
            // Case 2: handler is suspended (elicitation/sampling). Persist
            // the log updates but leave the registry entry as-is so the
            // follow-up POST can continue appending to this same stream.
            $this->streamingState->saveTo($this->streamingSession);
            $this->sessionStore->save($this->streamingSession);
        } else {
            // Case 3: genuine failure — synthesize an error frame and
            // mark the stream completed so clients stop waiting.
            if ($this->currentOriginatingRequestId !== null) {
                $errorMessage = new JsonRpcMessage(new JSONRPCError(
                    jsonrpc: '2.0',
                    id: new RequestId($this->currentOriginatingRequestId),
                    error: new JsonRpcErrorObject(
                        code: -32603,
                        message: 'Internal error: handler terminated without a response',
                        data: null,
                    ),
                ));
                $this->emitStreamingFrame($errorMessage);
            }
            $this->streamingState->getRegistry()->markCompleted($this->currentStreamId);
            $this->streamingState->saveTo($this->streamingSession);
            $this->sessionStore->save($this->streamingSession);
        }

        $this->streamingActive = false;
        $this->streamingEmitter = null;
        $this->streamingState = null;
        $this->streamingSession = null;

        // Clear the shutdown-guard latch so the next stream on this
        // transport registers a fresh handler on its own resolved IO.
        // Without this, long-running hosts (FrankenPHP, RoadRunner) and
        // any transport reused across requests would keep the first
        // request's guard and silently skip registration for every
        // subsequent stream — even though a per-request HttpIoInterface
        // passed via setIo() or $ioSink expects to own its own fatal-
        // safety-net. The latch still prevents double-registration
        // within a single stream cycle (begin→finalize).
        $this->streamingShutdownGuarded = false;

        return $this->sentinelAlreadyEmittedResponse();
    }

    /**
     * Declare that the transport is currently re-invoking a previously
     * suspended handler for the given original request id. While the
     * context is set, all outgoing messages in writeMessage() route to
     * the open stream that matches the id (see appendToResumeStream).
     *
     * HttpServerSession wraps its resumed handler call with a pair of
     * setResumeContext($id) / setResumeContext(null) invocations — a
     * finally block ensures the context is cleared even when the handler
     * throws ElicitationSuspendException (chained elicitation) or any
     * other exception.
     */
    public function setResumeContext(string|int|null $originatingRequestId): void
    {
        $this->resumeContextOriginatingId = $originatingRequestId;
    }

    /**
     * Resume-path routing for ANY outgoing message during a resumed
     * handler run (responses, progress notifications, chained server→client
     * requests). Appends the message as a frame to the stream that
     * originally carried the request; marks the stream completed only
     * when the frame is the final JSON-RPC response for that request.
     */
    private function appendToResumeStream(
        JsonRpcMessage $message,
        string|int $originatingId,
    ): bool {
        $session = $this->lastUsedSession;
        if ($session === null) {
            return false;
        }

        $state = SseSessionState::loadFrom($session, $this->config->getSseEventLogCapacity());
        $record = $state->getRegistry()->findOpenByOriginatingRequestId($originatingId);
        if ($record === null) {
            // No matching stream — typically a JSON-mode flow that never
            // opened one. Caller falls back to the outgoing queue.
            return false;
        }

        $inner = $message->message;
        try {
            $payload = \json_encode(
                $inner,
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES
            );
        } catch (\JsonException) {
            return false;
        }

        $streamId = $record['streamId'];
        $nextSeq = $record['lastSeq'] + 1;
        $frame = new SseFrame(
            id: StreamId::formatEventId($streamId, $nextSeq),
            event: null,
            retryMs: null,
            data: (string) $payload,
        );
        $state->getLog()->append($streamId, $nextSeq, $frame);
        $state->getRegistry()->setLastSeq($streamId, $nextSeq);

        // Only a final JSON-RPC response for the originating request
        // terminates the stream. Progress notifications and chained
        // server→client requests leave it OPEN so subsequent frames
        // (including the eventual final response) keep appending.
        if (($inner instanceof JSONRPCResponse || $inner instanceof JSONRPCError)
            && $inner->id->getValue() === $originatingId
        ) {
            $state->getRegistry()->markCompleted($streamId);
        }

        $state->saveTo($session);
        $this->sessionStore->save($session);
        return true;
    }

    /**
     * Response-only fallback routing used when no explicit resume context
     * is active but an outgoing response happens to match an open stream's
     * originatingRequestId. Kept as a safety net — the primary path is
     * appendToResumeStream invoked through setResumeContext.
     *
     * Returns true when the message was routed to a stream log; callers
     * then skip the ordinary outgoing queue. Returns false when no open
     * stream matches — e.g. pure JSON-mode flows that never opened a
     * stream, or tools/call flows whose stream already completed.
     */
    private function appendResponseToOpenStream(
        JsonRpcMessage $message,
        JSONRPCResponse|JSONRPCError $inner,
    ): bool {
        $session = $this->lastUsedSession;
        if ($session === null) {
            return false;
        }

        $idValue = $inner->id->getValue();

        $state = SseSessionState::loadFrom($session, $this->config->getSseEventLogCapacity());
        $record = $state->getRegistry()->findOpenByOriginatingRequestId($idValue);
        if ($record === null) {
            return false;
        }

        try {
            $payload = \json_encode(
                $inner,
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES
            );
        } catch (\JsonException) {
            return false;
        }

        $streamId = $record['streamId'];
        $nextSeq = $record['lastSeq'] + 1;
        $frame = new SseFrame(
            id: StreamId::formatEventId($streamId, $nextSeq),
            event: null,
            retryMs: null,
            data: (string) $payload,
        );
        $state->getLog()->append($streamId, $nextSeq, $frame);
        $state->getRegistry()->setLastSeq($streamId, $nextSeq);
        $state->getRegistry()->markCompleted($streamId);
        $state->saveTo($session);
        $this->sessionStore->save($session);

        return true;
    }

    /**
     * Build the zero-body StreamedHttpMessage the runner uses to signal
     * "response was already emitted directly to the wire — don't echo the
     * body again". The X-Mcp-Already-Emitted header is retained for one
     * release for backward compatibility with anything that inspected it
     * before the StreamedHttpMessage type existed.
     */
    private function sentinelAlreadyEmittedResponse(): HttpMessage
    {
        $response = new StreamedHttpMessage('');
        $response->setStatusCode(200);
        $response->setHeader('Content-Type', 'text/event-stream');
        $response->setHeader('Cache-Control', 'no-cache, no-transform');
        $response->setHeader('X-Accel-Buffering', 'no');
        $response->setHeader('X-Mcp-Already-Emitted', '1');
        return $response;
    }

    /**
     * Resolve which HttpIoInterface a streaming POST should use. Supports
     * the pre-refactor duck-typed sink injection (object with write/aborted
     * methods) by wrapping it in a shim so existing tests keep passing.
     */
    private function resolveStreamingIo(?object $ioSink): HttpIoInterface
    {
        if ($ioSink === null) {
            return $this->io;
        }
        if ($ioSink instanceof HttpIoInterface) {
            return $ioSink;
        }
        return new class ($ioSink) implements HttpIoInterface {
            public function __construct(private readonly object $sink)
            {
            }
            public function sendStatus(int $code): void
            {
            }
            public function sendHeader(string $name, string $value): void
            {
            }
            public function headersSent(): bool
            {
                return false;
            }
            public function drainOutputBuffers(): void
            {
            }
            public function disableAbortKills(): void
            {
            }
            public function write(string $bytes): void
            {
                if (\method_exists($this->sink, 'write')) {
                    $this->sink->write($bytes);
                }
            }
            public function flush(): void
            {
            }
            public function connectionAborted(): bool
            {
                if (\method_exists($this->sink, 'aborted')) {
                    return (bool) $this->sink->aborted();
                }
                return false;
            }
            public function registerShutdownHandler(callable $fn): void
            {
            }
        };
    }

    /**
     * Walk active output buffers, push status + headers through the adapter,
     * and disable user-abort short-circuits so a mid-handler disconnect does
     * not silently kill the PHP process before it persists final state.
     *
     * Only invoked from the production path (no-$ioSink branch) of
     * beginStreamingSseOutput. Tests inject a sink and bypass this path
     * entirely so header/status capture stays empty on the legacy path.
     *
     * @param array{contentEncoding: bool} $capabilities
     */
    private function sendStreamingHeaders(HttpSession $session, array $capabilities, HttpIoInterface $io): void
    {
        $io->drainOutputBuffers();
        $io->disableAbortKills();

        if (!$io->headersSent()) {
            $io->sendStatus(200);
            $io->sendHeader('Content-Type', 'text/event-stream');
            $io->sendHeader('Cache-Control', 'no-cache, no-transform');
            $io->sendHeader('X-Accel-Buffering', 'no');
            if ($capabilities['contentEncoding']) {
                // Suppress Apache mod_deflate / proxy gzip which would
                // re-chunk the body and break the client's SSE parser.
                $io->sendHeader('Content-Encoding', 'identity');
            }
            $io->sendHeader('Mcp-Session-Id', $session->getId());
        }
    }

    /**
     * @return array{contentEncoding: bool}
     */
    private function detectStreamingRuntime(): array
    {
        return [
            'contentEncoding' => true,
        ];
    }

    /**
     * Register a one-shot shutdown handler that finalizes the stream if the
     * handler terminates fatally (uncaught error, exit(), time limit). The
     * handler appends a synthesized error frame for the originating request
     * so clients never hang on a stream that died mid-flight.
     *
     * Registered on the IO resolved by beginStreamingSseOutput() — so an
     * embedder passing a per-request HttpIoInterface via $ioSink gets its
     * shutdown registered on that adapter, not on $this->io. The legacy
     * duck-typed shim's registerShutdownHandler is a no-op, matching the
     * pre-refactor behavior where tests did not register real PHP
     * shutdown callbacks.
     */
    private function registerStreamingShutdownGuard(HttpIoInterface $io): void
    {
        if ($this->streamingShutdownGuarded) {
            return;
        }
        $this->streamingShutdownGuarded = true;

        $io->registerShutdownHandler(function (): void {
            if (!$this->streamingActive
                || $this->streamingSession === null
                || $this->streamingState === null
                || $this->currentStreamId === null
            ) {
                return;
            }

            $error = \error_get_last();
            $isFatal = \is_array($error)
                && \in_array(
                    (int) $error['type'],
                    [\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR, \E_USER_ERROR],
                    true
                );

            // Suspension case: the handler emitted a server→client request
            // and legitimately left the stream open. A fatal at shutdown is
            // bad news for the in-memory pending state, but synthesizing a
            // response here would poison the log with a terminator the
            // resume path would then replay. Persist whatever we have and
            // let the follow-up POST surface the real failure.
            if ($this->streamingSuspensionDetected) {
                try {
                    $this->streamingState->saveTo($this->streamingSession);
                    $this->sessionStore->save($this->streamingSession);
                } catch (\Throwable) {
                    // Ignore — we're already shutting down.
                }
                return;
            }

            if (!$this->streamingFinalEmitted && $this->currentOriginatingRequestId !== null) {
                $reason = $isFatal
                    ? 'Internal error: fatal during handler execution'
                    : 'Internal error: handler terminated without a response';
                try {
                    $errorMessage = new JsonRpcMessage(new JSONRPCError(
                        jsonrpc: '2.0',
                        id: new RequestId($this->currentOriginatingRequestId),
                        error: new JsonRpcErrorObject(
                            code: -32603,
                            message: $reason,
                            data: null,
                        ),
                    ));
                    $this->emitStreamingFrame($errorMessage);
                } catch (\Throwable) {
                    // Best effort. The shutdown handler must never throw.
                }
            }

            try {
                $this->streamingState->getRegistry()->markCompleted($this->currentStreamId);
                $this->streamingState->saveTo($this->streamingSession);
                $this->sessionStore->save($this->streamingSession);
            } catch (\Throwable) {
                // Ignore — we're already shutting down.
            }
        });
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
                if ($this->io->connectionAborted()) {
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
     * Validate the MCP-Protocol-Version HTTP header when present.
     *
     * Spec 2025-11-25 §Transports: "If the server receives a request with an
     * invalid or unsupported MCP-Protocol-Version, it MUST respond with 400
     * Bad Request." The same section permits lenience on absence ("if the
     * server does not receive an MCP-Protocol-Version header, and has no
     * other way to identify the version ... the server SHOULD assume protocol
     * version 2025-03-26"). Because this SDK persists the negotiated version
     * on the session, absence is a no-op here — the session remains
     * authoritative for downstream feature gating. Only a *present* value
     * outside SUPPORTED_PROTOCOL_VERSIONS triggers the reject path.
     *
     * @return HttpMessage|null A 400 response on rejection, or null to allow
     */
    private function validateProtocolVersionHeader(HttpMessage $request): ?HttpMessage
    {
        $version = $request->getHeader('MCP-Protocol-Version');
        if ($version === null || $version === '') {
            return null;
        }
        if (in_array($version, Version::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            return null;
        }
        return HttpMessage::createJsonResponse(
            [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32600,
                    'message' => 'Unsupported MCP-Protocol-Version: ' . $version,
                ],
            ],
            400
        );
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
