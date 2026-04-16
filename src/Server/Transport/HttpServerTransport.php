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
                // SSE support is enabled and client prefers it
                // This is a placeholder for future SSE implementation
                // For now, fall back to JSON response
                return $this->createJsonResponse($session);
            } else {
                // Just return a temporary response for now.
                // The real JSON RPC output will be built AFTER the session processes the queue.
                return HttpMessage::createEmptyResponse(200);
            }
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
        
        if ($wantsSse && $this->config->isSseEnabled() && Environment::canSupportSse()) {
            // SSE support is enabled and client wants it
            // This is a placeholder for future SSE implementation
            // For now, return 405 Method Not Allowed
            return HttpMessage::createJsonResponse(
                ['error' => 'SSE not implemented yet'],
                405
            );
        } else {
            // SSE not supported or not requested
            return HttpMessage::createJsonResponse(
                ['error' => 'Method not allowed'],
                405
            );
        }
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
     * '127.0.0.1', '[::1]']). McpServer auto-enables this for localhost servers.
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
        $hostname = strtolower(trim($host, '[]'));

        if (!in_array($hostname, $allowedOrigins, true)) {
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
