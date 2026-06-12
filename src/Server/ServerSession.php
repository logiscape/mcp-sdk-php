<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2024 Logiscape LLC <https://logiscape.com>
 *
 * Based on the Python SDK for the Model Context Protocol
 * https://github.com/modelcontextprotocol/python-sdk
 *
 * PHP conversion developed by:
 * - Josh Abbott
 * - Claude 3.5 Sonnet (Anthropic AI model)
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
 * Filename: Server/ServerSession.php
 */

declare(strict_types=1);

namespace Mcp\Server;

use Mcp\Shared\BaseSession;
use Mcp\Shared\RequestResponder;
use Mcp\Shared\Version;
use Mcp\Types\Annotations;
use Mcp\Types\AudioContent;
use Mcp\Types\CacheableResult;
use Mcp\Types\CallToolResult;
use Mcp\Types\DiscoverResult;
use Mcp\Types\MetaKeys;
use Mcp\Types\RequestParams;
use Mcp\Types\ClientCapabilities;
use Mcp\Types\ClientNotification;
use Mcp\Types\ClientRequest;
use Mcp\Types\RequestWrapperInterface;
use Mcp\Types\ImageContent;
use Mcp\Types\Implementation;
use Mcp\Types\InitializeRequestParams;
use Mcp\Types\InitializeResult;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\LoggingLevel;
use Mcp\Types\Notification;
use Mcp\Types\NotificationParams;
use Mcp\Types\PromptMessage;
use Mcp\Types\Result;
use Mcp\Types\TextContent;
use Mcp\Types\Tool;
use Mcp\Server\InitializationOptions;
use Mcp\Types\CreateMessageRequest;
use Mcp\Types\CreateMessageResult;
use Mcp\Types\ElicitationCapability;
use Mcp\Types\ElicitationCreateRequest;
use Mcp\Types\ElicitationCreateResult;
use Mcp\Types\Meta;
use Mcp\Types\ModelPreferences;
use Mcp\Types\SamplingCapability;
use Mcp\Types\SamplingMessage;
use Mcp\Types\TaskRequestParams;
use Mcp\Types\ToolChoice;
use Mcp\Server\Transport\Transport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use InvalidArgumentException;

/**
 * ServerSession manages the MCP server-side session.
 * It sets up initialization and ensures that requests and notifications are
 * handled only after the client has initialized.
 *
 * Similar to Python's ServerSession, but synchronous and integrated with our PHP classes.
 */
class ServerSession extends BaseSession {
    protected InitializationState $initializationState = InitializationState::NotInitialized;
    protected ?InitializeRequestParams $clientParams = null;
    protected LoggerInterface $logger;
    protected string $negotiatedProtocolVersion = Version::LATEST_LEGACY_PROTOCOL_VERSION;
    /** @var array<string, callable> Method-keyed request handlers registered via registerHandlers() */
    protected array $methodRequestHandlers = [];
    /** @var array<string, callable> Method-keyed notification handlers registered via registerNotificationHandlers() */
    protected array $methodNotificationHandlers = [];
    /** Whether sendRequest() should be allowed to wait for client responses (used for elicitation in stdio mode). */
    protected bool $allowClientResponses = false;

    public function __construct(
        protected readonly Transport $transport,
        protected readonly InitializationOptions $initOptions,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        // The server receives ClientRequest and ClientNotification from the client
        parent::__construct(
            receiveRequestType: ClientRequest::class,
            receiveNotificationType: ClientNotification::class
        );

        // Register handlers for incoming requests and notifications
        $this->onRequest([$this, 'handleRequest']);
        $this->onNotification([$this, 'handleNotification']);
    }

    /**
     * Starts the server session.
     */
    public function start(): void {
        if ($this->isInitialized) {
            throw new RuntimeException('Session already initialized');
        }

        $this->transport->start();
        $this->initialize();
    }

    /**
     * Stops the server session.
     */
    public function stop(): void {
        if (!$this->isInitialized) {
            return;
        }

        $this->transport->stop();
        $this->close();
    }

    /**
     * Get the client's initialization parameters (including capabilities).
     */
    public function getClientParams(): ?InitializeRequestParams {
        return $this->clientParams;
    }

    /**
     * Check if the client supports a specific capability.
     */
    public function checkClientCapability(ClientCapabilities $capability): bool {
        if ($this->clientParams === null) {
            return false;
        }

        $clientCaps = $this->clientParams->capabilities;

        if ($capability->roots !== null) {
            if ($clientCaps->roots === null) {
                return false;
            }
            if ($capability->roots->listChanged && !$clientCaps->roots->listChanged) {
                return false;
            }
        }

        if ($capability->sampling !== null) {
            if ($clientCaps->sampling === null) {
                return false;
            }
        }

        if ($capability->elicitation !== null) {
            if ($clientCaps->elicitation === null) {
                return false;
            }
        }

        if ($capability->tasks !== null) {
            if ($clientCaps->tasks === null) {
                return false;
            }
        }

        if ($capability->experimental !== null) {
            if ($clientCaps->experimental === null) {
                return false;
            }
            foreach ($capability->experimental as $key => $value) {
                if (!isset($clientCaps->experimental[$key]) ||
                    $clientCaps->experimental[$key] !== $value) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, callable> $handlers */
    public function registerHandlers(array $handlers): void {
        foreach ($handlers as $method => $callable) {
            $this->methodRequestHandlers[$method] = $callable;
        }
    }

    /** @param array<string, callable> $handlers */
    public function registerNotificationHandlers(array $handlers): void {
        foreach ($handlers as $method => $callable) {
            $this->methodNotificationHandlers[$method] = $callable;
        }
    }

    /**
     * Handle incoming requests. If it's the initialize request, handle it specially.
     * Otherwise, ensure initialization is complete before handling other requests.
     *
     * @param RequestResponder $responder The request responder wrapping the client request.
     */
    public function handleRequest(RequestResponder $responder): void {
        $request = $responder->getRequest(); // a ClientRequest
        $actualRequest = $request->getRequest(); // the underlying typed Request
        $method = $actualRequest->method;
        $params = $actualRequest->params ?? null;

        if ($method === 'initialize') {
            $respond = fn($result) => $responder->sendResponse($result);
            $this->handleInitialize($request, $respond);
            return;
        }

        // server/discover (SEP-2575) is self-contained: it carries its own
        // _meta envelope and is answered regardless of legacy initialization
        // state — the 2026-07-28 lifecycle has no handshake to wait for.
        if ($method === 'server/discover') {
            $this->handleDiscover($params, fn($result) => $responder->sendResponse($result));
            return;
        }

        if ($this->initializationState !== InitializationState::Initialized) {
            throw new \RuntimeException('Received request before initialization was complete');
        }

        // Now we integrate the method-specific handlers:
        if (isset($this->methodRequestHandlers[$method])) {
            $this->logger->info("Calling handler for method: $method");
            $handler = $this->methodRequestHandlers[$method];
            try {
                $result = $handler($params); // call the user-defined handler
                $responder->sendResponse($result);
            } catch (\Mcp\Shared\McpError $e) {
                $this->logger->error("Handler error for method '$method': " . $e->getMessage());
                $responder->sendResponse($e->error);
            } catch (\Throwable $e) {
                $this->logger->error("Handler error for method '$method': " . $e->getMessage());
                $responder->sendResponse(new \Mcp\Shared\ErrorData(
                    code: -32603, // Internal error (JSON-RPC standard)
                    message: $e->getMessage()
                ));
            }
        } else {
            $this->logger->warning("No registered handler for method: $method");
            $responder->sendResponse(new \Mcp\Shared\ErrorData(
                code: -32601, // Method not found (JSON-RPC standard)
                message: "Method not found: $method"
            ));
        }
    }

    /**
     * Handle incoming notifications. If it's the "initialized" notification, mark state as Initialized.
     *
     * @param ClientNotification $notification The incoming client notification.
     */
    public function handleNotification(ClientNotification $notification): void {
        // 1) Extract the actual typed Notification (e.g., InitializedNotification)
        $actualNotification = $notification->getNotification();

        // 2) Retrieve the method from the typed notification
        $method = $actualNotification->method;

        if ($method === 'notifications/initialized') {
            $this->initializationState = InitializationState::Initialized;
            $this->logger->info('Client has completed initialization.');
            return;
        }

        if ($this->initializationState !== InitializationState::Initialized) {
            throw new RuntimeException('Received notification before initialization was complete');
        }

        // Dispatch to registered notification handlers
        if (isset($this->methodNotificationHandlers[$method])) {
            $this->logger->info("Calling notification handler for method: $method");
            $handler = $this->methodNotificationHandlers[$method];
            try {
                $params = $this->getHandlerNotificationParams($actualNotification);
                $handler($params);
            } catch (\Throwable $e) {
                $this->logger->error("Notification handler error: $e");
            }
        } else {
            $this->logger->info('Received notification: ' . $method);
        }
    }

    private function getHandlerNotificationParams(Notification $notification): ?NotificationParams
    {
        // Every notification type the SDK builds populates Notification::$params
        // with the wire-form params (CancelledNotification's constructor mirrors
        // its direct requestId/reason properties into $params for serialization).
        // Returning that slot directly gives handlers the unmodified inbound payload,
        // including any spec-extension fields the factory forwarded onto it.
        return $notification->params;
    }

    /**
     * Handle the initialize request from the client.
     *
     * @param RequestWrapperInterface $request The initialize request.
     * @param callable $respond The responder callable.
     */
    protected function handleInitialize(RequestWrapperInterface $request, callable $respond): void {
        $this->initializationState = InitializationState::Initializing;
        /** @var InitializeRequestParams $params */
        $params = $request->getRequest()->params;
        $this->clientParams = $params;
    
        // Get the client's requested protocol version
        $clientProtocolVersion = $params->protocolVersion;
        
        // Negotiate the protocol version
        $this->negotiatedProtocolVersion = $this->negotiateProtocolVersion($clientProtocolVersion);
        
        $result = new InitializeResult(
            protocolVersion: $this->negotiatedProtocolVersion,
            capabilities: $this->initOptions->capabilities,
            serverInfo: new Implementation(
                name: $this->initOptions->serverName,
                version: $this->initOptions->serverVersion
            )
        );
    
        $respond($result);
    
        $this->initializationState = InitializationState::Initialized;
        $this->logger->info('Initialization complete with protocol version: ' . $this->negotiatedProtocolVersion);
    }
    
    /**
     * Negotiate the protocol version based on the client's requested version.
     *
     * Only legacy revisions are negotiable here: the 2026-07-28 revision
     * removes the initialize handshake itself (SEP-2575), so a client that
     * sends `initialize` is by definition speaking a legacy revision. The
     * stateless revision is selected per-request via the _meta envelope
     * instead (see handleDiscover; the full per-request era detection is
     * the WS2 milestone).
     */
    private function negotiateProtocolVersion(string $clientRequestedVersion): string {
        // If the client requests a legacy version we support, return it
        if (in_array($clientRequestedVersion, Version::SUPPORTED_PROTOCOL_VERSIONS, true)
            && version_compare($clientRequestedVersion, Version::LATEST_LEGACY_PROTOCOL_VERSION, '<=')
        ) {
            return $clientRequestedVersion;
        }

        // Unsupported (or non-legacy) version: fall back to the newest
        // revision the legacy handshake can negotiate.
        $this->logger->info('Client requested protocol version not negotiable via initialize: '
                            . $clientRequestedVersion
                            . '. Using latest legacy version: ' . Version::LATEST_LEGACY_PROTOCOL_VERSION);
        return Version::LATEST_LEGACY_PROTOCOL_VERSION;
    }

    /**
     * Handle the `server/discover` request (SEP-2575, revision 2026-07-28).
     *
     * The capabilities advertised here are the same object the legacy
     * initialize result advertises (InitializationOptions::capabilities,
     * built from Server::getCapabilities()), so the two discovery surfaces
     * can never disagree.
     *
     * @param RequestParams|null $params The request params (carrying the _meta envelope)
     * @param callable $respond Responder receiving a DiscoverResult or ErrorData
     */
    protected function handleDiscover(?RequestParams $params, callable $respond): void {
        $envelopeError = $this->validateModernRequestMeta($params);
        if ($envelopeError !== null) {
            $respond($envelopeError);
            return;
        }

        /** @var Meta $meta */
        $meta = $params->_meta;
        $requestedVersion = $meta->getExtraFields()[MetaKeys::PROTOCOL_VERSION];

        if (!in_array($requestedVersion, Version::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            $respond(new \Mcp\Shared\ErrorData(
                code: \Mcp\Shared\McpError::UNSUPPORTED_PROTOCOL_VERSION,
                message: 'Unsupported protocol version',
                data: [
                    'supported' => Version::SUPPORTED_PROTOCOL_VERSIONS,
                    'requested' => $requestedVersion,
                ],
            ));
            return;
        }

        $result = new DiscoverResult(
            supportedVersions: Version::SUPPORTED_PROTOCOL_VERSIONS,
            capabilities: $this->initOptions->capabilities,
            serverInfo: new Implementation(
                name: $this->initOptions->serverName,
                version: $this->initOptions->serverVersion
            ),
        );
        // Required wire fields under 2026-07-28. The discover result is
        // identical for every client of this server (capabilities are
        // derived from registered handlers, not from the caller), so
        // "public" is accurate; ttlMs 0 marks it immediately stale, the
        // conservative default for an SDK that cannot know the server's
        // deployment cadence.
        $result->resultType = Result::RESULT_TYPE_COMPLETE;
        $result->setCacheHints(0, CacheableResult::CACHE_SCOPE_PUBLIC);

        $respond($result);
    }

    /**
     * Validate the SEP-2575 per-request `_meta` envelope.
     *
     * @return \Mcp\Shared\ErrorData|null An InvalidParams (-32602) error
     *         naming the missing/invalid field, or null when the envelope
     *         is valid.
     */
    protected function validateModernRequestMeta(?RequestParams $params): ?\Mcp\Shared\ErrorData {
        $meta = $params?->_meta;
        if ($meta === null) {
            return new \Mcp\Shared\ErrorData(
                code: -32602,
                message: 'Invalid params: missing required _meta envelope (SEP-2575)'
            );
        }

        $required = [
            MetaKeys::PROTOCOL_VERSION,
            MetaKeys::CLIENT_INFO,
            MetaKeys::CLIENT_CAPABILITIES,
        ];
        foreach ($required as $key) {
            if (!isset($meta->{$key})) {
                return new \Mcp\Shared\ErrorData(
                    code: -32602,
                    message: "Invalid params: missing required _meta field: {$key}"
                );
            }
        }

        $fields = $meta->getExtraFields();

        $version = $fields[MetaKeys::PROTOCOL_VERSION];
        if (!is_string($version) || $version === '') {
            return new \Mcp\Shared\ErrorData(
                code: -32602,
                message: 'Invalid params: ' . MetaKeys::PROTOCOL_VERSION . ' must be a non-empty string'
            );
        }

        if (!self::isValidImplementationValue($fields[MetaKeys::CLIENT_INFO])) {
            return new \Mcp\Shared\ErrorData(
                code: -32602,
                message: 'Invalid params: ' . MetaKeys::CLIENT_INFO
                    . ' must be an object with non-empty string "name" and "version"'
            );
        }

        if (!self::isValidCapabilitiesValue($fields[MetaKeys::CLIENT_CAPABILITIES])) {
            return new \Mcp\Shared\ErrorData(
                code: -32602,
                message: 'Invalid params: ' . MetaKeys::CLIENT_CAPABILITIES . ' must be an object'
            );
        }

        return null;
    }

    /**
     * Whether a _meta clientInfo value is a valid Implementation: either the
     * typed object (in-process callers) or the wire shape — an object with
     * non-empty string name and version.
     */
    private static function isValidImplementationValue(mixed $value): bool {
        if ($value instanceof Implementation) {
            return true;
        }
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        return is_array($value)
            && isset($value['name'], $value['version'])
            && is_string($value['name']) && $value['name'] !== ''
            && is_string($value['version']) && $value['version'] !== '';
    }

    /**
     * Whether a _meta clientCapabilities value is a JSON object (an empty
     * object — no optional capabilities — is valid; a JSON array or any
     * scalar is not).
     */
    private static function isValidCapabilitiesValue(mixed $value): bool {
        if ($value instanceof ClientCapabilities || $value instanceof \stdClass) {
            return true;
        }
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * Sends a log message as a notification to the client.
     *
     * @param LoggingLevel $level The logging level.
     * @param mixed $data The data to log.
     * @param string|null $logger The logger name.
     */
    public function sendLogMessage(
        LoggingLevel $level,
        mixed $data,
        ?string $logger = null
    ): void {
        $params = [
            'level' => $level->value,
            'data' => $data,
            'logger' => $logger
        ];

        $notificationParams = new \Mcp\Types\NotificationParams();
        foreach ($params as $key => $value) {
            if ($value !== null) {
                $notificationParams->$key = $value;
            }
        }

        $jsonRpcNotification = new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/message',
            params: $notificationParams
        );

        $notification = new JsonRpcMessage($jsonRpcNotification);

        $this->writeMessage($notification);
    }

    /**
     * Send an elicitation request to the client.
     *
     * In stdio mode, this blocks until the client responds. In HTTP mode,
     * this is not called directly — the ElicitationContext uses the
     * suspend/resume pattern instead.
     *
     * @param string $message Message describing what information is needed
     * @param array<string, mixed>|null $requestedSchema JSON Schema for form mode
     * @param string|null $url URL for URL-mode elicitation
     * @param string|null $elicitationId Unique ID for URL-mode elicitation
     * @return ElicitationCreateResult|null The client's response, or null if not supported
     */
    public function sendElicitationRequest(
        string $message,
        ?array $requestedSchema = null,
        ?string $url = null,
        ?string $elicitationId = null,
        ?Meta $_meta = null,
        ?TaskRequestParams $task = null,
    ): ?ElicitationCreateResult {
        // Check that the client supports elicitation at all
        $requiredCap = new ClientCapabilities(elicitation: new ElicitationCapability());
        if (!$this->checkClientCapability($requiredCap)) {
            $this->logger->info('Client does not support elicitation');
            return null;
        }

        $isUrlMode = ($url !== null);

        // Check protocol version support
        if (!$this->clientSupportsFeature('elicitation')) {
            $this->logger->info('Negotiated protocol version does not support elicitation');
            return null;
        }
        if ($isUrlMode && !$this->clientSupportsFeature('url_elicitation')) {
            $this->logger->info('Negotiated protocol version does not support URL elicitation');
            return null;
        }

        // Check sub-capabilities (form vs url)
        // Per spec: servers MUST NOT send elicitation requests with modes
        // not supported by the client. An empty "elicitation": {} object
        // is equivalent to form-only support.
        $elicitCap = $this->clientParams->capabilities->elicitation ?? null;
        if ($isUrlMode && ($elicitCap === null || $elicitCap->url === null)) {
            $this->logger->info('Client does not support URL-mode elicitation');
            return null;
        }
        if (!$isUrlMode) {
            // Form is supported when: form is explicitly declared, OR both
            // form and url are null (empty object = form-only per spec).
            $formSupported = $elicitCap !== null
                && ($elicitCap->form !== null || ($elicitCap->form === null && $elicitCap->url === null));
            if (!$formSupported) {
                $this->logger->info('Client does not support form-mode elicitation');
                return null;
            }
        }

        // Task-augmented elicitation is not yet implemented end-to-end;
        // strip the task param to avoid sending a request the SDK cannot
        // correctly complete (the response handler only supports
        // ElicitationCreateResult, not CreateTaskResult).
        if ($task !== null) {
            $this->logger->warning(
                'Task-augmented elicitation is not yet supported; '
                . 'the task parameter has been stripped from this request'
            );
            $task = null;
        }

        // Build the request
        $request = new ElicitationCreateRequest(
            message: $message,
            mode: $isUrlMode ? 'url' : 'form',
            requestedSchema: $requestedSchema,
            url: $url,
            elicitationId: $elicitationId,
            _meta: $_meta,
            task: $task,
        );

        // Enable client response waiting temporarily, then send
        $this->allowClientResponses = true;
        try {
            /** @var ElicitationCreateResult */
            return $this->sendRequest($request, ElicitationCreateResult::class);
        } catch (\Mcp\Shared\McpError $e) {
            $this->logger->error('Elicitation request failed: ' . $e->getMessage());
            return null;
        } finally {
            $this->allowClientResponses = false;
        }
    }

    /**
     * Send a `sampling/createMessage` request to the client and block for the response.
     *
     * In stdio mode, this blocks until the client responds. In HTTP mode, tool
     * handlers should go through `SamplingContext`, which uses the suspend/resume
     * pattern instead of calling this method directly.
     *
     * Returns null when the client doesn't declare the `sampling` capability,
     * the negotiated protocol version doesn't cover sampling, or the client
     * returns an error response. The caller decides how to surface that in the
     * tool result.
     *
     * Per spec, servers MUST only send `sampling/createMessage` while processing
     * an originating client request (tools/call, resources/read, prompts/get).
     * Enforcement of that constraint is the caller's responsibility; the
     * {@see \Mcp\Server\Sampling\SamplingContext} is only instantiated inside a
     * tool handler closure and so satisfies it structurally.
     *
     * @param SamplingMessage[] $messages
     * @param string[]|null $stopSequences
     * @param array<int, \Mcp\Types\Tool>|null $tools Requires both the `sampling_with_tools` protocol
     *        feature and the client's `sampling.tools` sub-capability. When either is missing, this
     *        method returns null without writing to the transport; callers should retry without tools
     *        or choose a different fallback.
     */
    public function sendSamplingRequest(
        array $messages,
        int $maxTokens,
        ?array $stopSequences = null,
        ?string $systemPrompt = null,
        ?float $temperature = null,
        ?Meta $metadata = null,
        ?ModelPreferences $modelPreferences = null,
        ?string $includeContext = null,
        ?array $tools = null,
        ?ToolChoice $toolChoice = null,
        ?Meta $_meta = null,
    ): ?CreateMessageResult {
        // Client must declare the sampling capability.
        $requiredCap = new ClientCapabilities(sampling: new SamplingCapability());
        if (!$this->checkClientCapability($requiredCap)) {
            $this->logger->info('Client does not support sampling');
            return null;
        }

        // Negotiated protocol version must cover sampling.
        if (!$this->clientSupportsFeature('sampling')) {
            $this->logger->info('Negotiated protocol version does not support sampling');
            return null;
        }

        // Tools-in-sampling requires both the `sampling_with_tools` protocol
        // feature and the client's sampling.tools sub-capability. When either
        // is missing, refuse the call so callers can fall back explicitly
        // instead of sending a plain sampling request that drops the tools.
        if ($tools !== null || $toolChoice !== null) {
            if (!$this->clientSupportsFeature('sampling_with_tools')) {
                $this->logger->info('Sampling request with tools refused: negotiated protocol version predates sampling_with_tools');
                return null;
            }
            $samplingCap = $this->clientParams->capabilities->sampling ?? null;
            $toolsDeclared = false;
            if ($samplingCap !== null) {
                $extras = $samplingCap->jsonSerialize();
                $toolsDeclared = is_array($extras) && array_key_exists('tools', $extras);
            }
            if (!$toolsDeclared) {
                $this->logger->info('Sampling request with tools refused: client did not advertise sampling.tools capability');
                return null;
            }
        }

        $request = new CreateMessageRequest(
            messages: $messages,
            maxTokens: $maxTokens,
            stopSequences: $stopSequences,
            systemPrompt: $systemPrompt,
            temperature: $temperature,
            metadata: $metadata,
            modelPreferences: $modelPreferences,
            includeContext: $includeContext,
            tools: $tools,
            toolChoice: $toolChoice,
            _meta: $_meta,
        );
        // Validate cross-message invariants before anything hits the wire, so a
        // malformed transcript surfaces as an InvalidArgumentException at the
        // call site rather than a -32602 from the client.
        $request->validate();

        $this->allowClientResponses = true;
        try {
            /** @var CreateMessageResult */
            return $this->sendRequest($request, CreateMessageResult::class);
        } catch (\Mcp\Shared\McpError $e) {
            $this->logger->error('Sampling request failed: ' . $e->getMessage());
            return null;
        } finally {
            $this->allowClientResponses = false;
        }
    }

    /**
     * Send a notifications/elicitation/complete notification to the client.
     *
     * Indicates that an out-of-band URL-mode elicitation interaction has completed.
     *
     * @param string $elicitationId The ID of the completed elicitation
     */
    public function sendElicitationCompleteNotification(string $elicitationId): void
    {
        $notificationParams = new NotificationParams();
        $notificationParams->elicitationId = $elicitationId;

        $jsonRpcNotification = new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/elicitation/complete',
            params: $notificationParams
        );

        $notification = new JsonRpcMessage($jsonRpcNotification);
        $this->writeMessage($notification);
    }

    /**
     * Sends a resource updated notification to the client.
     *
     * @param string $uri The URI of the updated resource.
     */
    public function sendResourceUpdated(string $uri): void {
        $params = ['uri' => $uri];

        $notificationParams = new \Mcp\Types\NotificationParams();
        foreach ($params as $key => $value) {
            if ($value !== null) {
                $notificationParams->$key = $value;
            }
        }

        $jsonRpcNotification = new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/resources/updated',
            params: $notificationParams
        );

        $notification = new JsonRpcMessage($jsonRpcNotification);

        $this->writeMessage($notification);
    }

    /**
     * Sends a progress notification for a request currently in progress.
     *
     * @param string|int $progressToken The progress token.
     * @param float $progress The current progress.
     * @param float|null $total The total progress value.
     */
    public function writeProgressNotification(
        string|int $progressToken,
        float $progress,
        ?float $total = null
    ): void {
        $params = [
            'progressToken' => $progressToken,
            'progress' => $progress,
            'total' => $total
        ];

        $notificationParams = new \Mcp\Types\NotificationParams();
        foreach ($params as $key => $value) {
            if ($value !== null) {
                $notificationParams->$key = $value;
            }
        }

        $jsonRpcNotification = new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/progress',
            params: $notificationParams
        );

        $notification = new JsonRpcMessage($jsonRpcNotification);

        $this->writeMessage($notification);
    }

    /**
     * Sends a resource list changed notification to the client.
     */
    public function sendResourceListChanged(): void {
        $this->writeNotification('notifications/resources/list_changed');
    }

    /**
     * Sends a tool list changed notification to the client.
     */
    public function sendToolListChanged(): void {
        $this->writeNotification('notifications/tools/list_changed');
    }

    /**
     * Sends a prompt list changed notification to the client.
     */
    public function sendPromptListChanged(): void {
        $this->writeNotification('notifications/prompts/list_changed');
    }

    /**
     * Get the negotiated protocol version.
     *
     * @throws RuntimeException If the session has not been initialized yet.
     *
     * @return string The negotiated protocol version.
     */
    public function getNegotiatedProtocolVersion(): string {
        if ($this->initializationState !== InitializationState::Initialized) {
            throw new RuntimeException('Session not yet initialized');
        }
        return $this->negotiatedProtocolVersion;
    }

    /**
     * Check if the client supports a specific feature based on the negotiated protocol version.
     *
     * @param string $feature The feature to check for.
     *
     * @return bool True if the client supports the feature.
     */
    public function clientSupportsFeature(string $feature): bool {
        if ($this->initializationState !== InitializationState::Initialized) {
            return false;
        }
        return Version::supportsFeature($this->negotiatedProtocolVersion, $feature);
    }

    /**
     * Set the negotiated protocol version directly, without a handshake.
     *
     * This is the seam for the 2026-07-28 stateless path, where there is no
     * initialize exchange and the effective protocol version arrives
     * per-request in the _meta envelope (the per-request era detection that
     * drives this lands with WS2). When the version selects the stateless
     * revision the session is also marked ready, because that lifecycle has
     * no handshake to wait for (SEP-2575).
     *
     * @internal Intended for the SDK's own era detection and for tests.
     */
    public function setNegotiatedProtocolVersion(string $version): void {
        if (!in_array($version, Version::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            throw new InvalidArgumentException("Unsupported protocol version: {$version}");
        }
        $this->negotiatedProtocolVersion = $version;
        if (Version::supportsFeature($version, 'stateless_lifecycle')) {
            $this->initializationState = InitializationState::Initialized;
        }
    }

    /**
     * Adapts an outgoing response to be compatible with the client's protocol version.
     *
     * @param mixed $response The response object to adapt
     * @return mixed The adapted response
     */
    public function adaptResponseForClient($response): mixed {
        // Modern path (2026-07-28): stamp the fields the stateless revision
        // requires on every result instead of stripping anything.
        if (Version::supportsFeature($this->negotiatedProtocolVersion, 'stateless_lifecycle')) {
            return $this->adaptResultForModernClient($response);
        }

        // Legacy path: remove fields that did not exist before 2026-07-28.
        if ($response instanceof Result) {
            $response->resultType = null;
            if ($response instanceof CacheableResult) {
                $response->clearCacheHints();
            }
        }

        // Apply adaptations based on the response type
        if ($response instanceof CallToolResult) {
            return $this->adaptCallToolResult($response);
        } else if ($response instanceof PromptMessage) {
            return $this->adaptPromptMessage($response);
        } else if ($response instanceof Tool) {
            return $this->adaptTool($response);
        }

        return $response;
    }

    /**
     * Ensure a result carries the fields the 2026-07-28 schema requires:
     * the `resultType` discriminator on every result, and the SEP-2549
     * `ttlMs` / `cacheScope` caching hints on cacheable results. Handlers
     * that already set them are left untouched; bare results get the most
     * conservative defaults (ttlMs 0 = immediately stale, scope "private").
     */
    private function adaptResultForModernClient(mixed $response): mixed {
        if ($response instanceof Result) {
            if ($response->resultType === null) {
                $response->resultType = Result::RESULT_TYPE_COMPLETE;
            }
            if ($response instanceof CacheableResult
                && ($response->getTtlMs() === null || $response->getCacheScope() === null)
            ) {
                $response->setCacheHints(
                    $response->getTtlMs() ?? 0,
                    $response->getCacheScope() ?? CacheableResult::CACHE_SCOPE_PRIVATE
                );
            }
        }
        return $response;
    }

    /**
     * Adapts a CallToolResult to be compatible with older protocol versions.
     */
    private function adaptCallToolResult(CallToolResult $result): CallToolResult {
        $needsAdaptation = false;
        $adaptedContent = $result->content;
        $structuredContent = $result->structuredContent;

        // Non-object structuredContent (any JSON value) is a 2026-07-28
        // capability (SEP-2106); legacy clients expect an object, so strip
        // everything else for them: scalars, explicit null, and PHP list
        // arrays (which serialize as JSON arrays). An empty PHP array is
        // kept — it is how handlers have always expressed an empty JSON
        // object. McpServer always emits the serialized JSON as a
        // TextContent block alongside, so no information is lost.
        if (!Version::supportsFeature($this->negotiatedProtocolVersion, 'json_schema_2020_12')) {
            $isJsonObject = is_array($structuredContent)
                && ($structuredContent === [] || !array_is_list($structuredContent));
            if ($structuredContent !== null && !$isJsonObject) {
                $structuredContent = null;
                $needsAdaptation = true;
            }
            if ($result->hasExplicitNullStructuredContent()) {
                // The rebuilt result below carries no explicit-null marker.
                $needsAdaptation = true;
            }
        }

        // Strip structuredContent and ResourceLinkContent for clients older than 2025-06-18
        if (version_compare($this->negotiatedProtocolVersion, '2025-06-18', '<')) {
            if ($structuredContent !== null) {
                $structuredContent = null;
                $needsAdaptation = true;
            }

            $filtered = [];
            foreach ($result->content as $content) {
                if ($content instanceof \Mcp\Types\ResourceLinkContent) {
                    $needsAdaptation = true;
                    continue;
                }
                $filtered[] = $content;
            }
            $adaptedContent = $filtered;
        }

        if (version_compare($this->negotiatedProtocolVersion, '2025-03-26', '<')) {
            // Filter out AudioContent and strip annotations for pre-2025-03-26 clients
            $filtered = [];
            foreach ($adaptedContent as $content) {
                if ($content instanceof AudioContent) {
                    $needsAdaptation = true;
                    continue;
                }
                if (($content instanceof TextContent || $content instanceof ImageContent) && $content->annotations !== null) {
                    $needsAdaptation = true;
                    if ($content instanceof TextContent) {
                        $content = new TextContent($content->text);
                    } else {
                        $content = new ImageContent($content->data, $content->mimeType);
                    }
                }
                $filtered[] = $content;
            }
            $adaptedContent = $filtered;
        }

        if (!$needsAdaptation) {
            return $result;
        }

        return new CallToolResult(
            content: $adaptedContent,
            isError: $result->isError,
            _meta: $result->_meta,
            structuredContent: $structuredContent,
        );
    }

    /**
     * Adapts a PromptMessage to be compatible with older protocol versions.
     */
    private function adaptPromptMessage(PromptMessage $message): PromptMessage {
        if (version_compare($this->negotiatedProtocolVersion, '2025-03-26', '<')) {
            $content = $message->content;
            
            // If content is AudioContent, replace it with a text notice
            if ($content instanceof AudioContent) {
                $content = new TextContent(
                    "Audio content was available but couldn't be displayed due to client protocol version limitations."
                );
            }
            
            // Strip annotations from content if present
            if (($content instanceof TextContent || $content instanceof ImageContent) && $content->annotations !== null) {
                if ($content instanceof TextContent) {
                    $content = new TextContent($content->text);
                } else if ($content instanceof ImageContent) {
                    $content = new ImageContent($content->data, $content->mimeType);
                }
            }
            
            return new PromptMessage($message->role, $content);
        }
        
        return $message;
    }

    /**
     * Adapts a Tool to be compatible with older protocol versions.
     */
    private function adaptTool(Tool $tool): Tool {
        if (version_compare($this->negotiatedProtocolVersion, '2025-03-26', '<') && $tool->annotations !== null) {
            // Create a new tool without annotations
            return new Tool(
                name: $tool->name,
                inputSchema: $tool->inputSchema,
                description: $tool->description
            );
        }
        
        return $tool;
    }

    /**
     * Writes a generic notification to the client.
     *
     * @param string $method The method name of the notification.
     * @param array<string, mixed>|null $params The parameters of the notification.
     */
    private function writeNotification(string $method, ?array $params = null): void {
        $notificationParams = null;
        if ($params !== null) {
            $notificationParams = new \Mcp\Types\NotificationParams();
            foreach ($params as $key => $value) {
                if ($value !== null) {
                    $notificationParams->$key = $value;
                }
            }
        }

        $jsonRpcNotification = new JSONRPCNotification(
            jsonrpc: '2.0',
            method: $method,
            params: $notificationParams
        );

        $notification = new JsonRpcMessage($jsonRpcNotification);

        $this->writeMessage($notification);
    }

    /**
     * Implementing abstract methods from BaseSession
     */

    protected function startMessageProcessing(): void {
        // Start reading messages from the transport
        // This could be a loop or a separate thread in a real implementation
        // For demonstration, we'll use a simple loop
        while ($this->isInitialized) {
            $message = $this->readNextMessage();
            $this->handleIncomingMessage($message);
        }
    }

    protected function stopMessageProcessing(): void {
        //$this->stop();
    }

    protected function writeMessage(JsonRpcMessage $message): void {
        $innerMessage = $message->message;
        
        // Apply adapters for responses based on client protocol version
        if ($innerMessage instanceof JSONRPCResponse && $this->initializationState === InitializationState::Initialized) {
            $responseResult = $innerMessage->result;
            $adaptedResult = $this->adaptResponseForClient($responseResult);
            
            if ($adaptedResult !== $responseResult) {
                // Create a new response with the adapted result
                $innerMessage = new JSONRPCResponse(
                    jsonrpc: $innerMessage->jsonrpc,
                    id: $innerMessage->id,
                    result: $adaptedResult
                );
                $message = new JsonRpcMessage($innerMessage);
            }
        }
        
        $this->transport->writeMessage($message);
    }

    protected function waitForResponse(int $requestIdValue, string $resultType, ?\Mcp\Types\Result &$futureResult): \Mcp\Types\Result {
        if (!$this->allowClientResponses) {
            throw new RuntimeException('Server does not support waiting for responses from the client.');
        }

        // Block and read messages until the response for this request arrives.
        // This is the same logic as BaseSession::waitForResponse() — works in stdio
        // where the process stays alive and can read from stdin.
        while ($futureResult === null) {
            $message = $this->readNextMessage();
            $this->handleIncomingMessage($message);
        }

        return $futureResult;
    }

    protected function readNextMessage(): JsonRpcMessage {
        while (true) {
            $message = $this->transport->readMessage();
            if ($message !== null) {
                return $message;
            }
            // Sleep briefly to avoid busy-waiting when no messages are available
            usleep(10000);
        }
    }
}
