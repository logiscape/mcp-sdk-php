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
use Mcp\Types\RequestId;
use Mcp\Types\Result;
use Mcp\Types\SubscriptionsListenResult;
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
use Mcp\Types\SubscriptionFilter;
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
    use \Mcp\Shared\EmitsDeprecationWarnings;

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

    /**
     * Whether the transport identified the current request as modern-era
     * from its own version metadata (the MCP-Protocol-Version header,
     * SEP-2575). Lets the session reject a header-declared modern request
     * whose body lacks the required `_meta` envelope with the spec's
     * -32602 instead of misrouting it down the legacy path. Set per
     * request by the HTTP runner; stdio has no out-of-band metadata, so
     * detection there rests on the envelope alone.
     */
    protected bool $transportDeclaredModern = false;

    /** Whether the request currently being handled selected the modern era. */
    protected bool $currentRequestModern = false;

    /**
     * Lower-cased HTTP header map of the modern request currently being
     * served, forwarded by the HTTP runner from the transport (SEP-2243).
     * Null on stdio (no headers) and on legacy HTTP requests. Lets
     * schema-aware layers (McpServer's Mcp-Param-* validation) see
     * transport metadata at dispatch time.
     *
     * @var array<string, string>|null
     */
    protected ?array $transportHttpHeaders = null;

    /**
     * Active subscriptions/listen filters keyed by subscription id (the
     * stringified JSON-RPC id of the listen request). Only populated on
     * stdio, where the subscription lives for the session; the HTTP
     * binding streams instead (see SubscriptionListenException).
     *
     * @var array<string, SubscriptionFilter>
     */
    protected array $activeSubscriptions = [];

    /**
     * The original JSON-RPC RequestIds of the active listen requests,
     * keyed like $activeSubscriptions. Kept so a server-initiated end of
     * the subscription can answer the original request with the graceful
     * SubscriptionsListenResult (spec PR #2953) using the id's original
     * wire type (int vs string).
     *
     * @var array<string, RequestId>
     */
    protected array $activeSubscriptionRequestIds = [];

    /**
     * The raw wire-level `_meta` of the message currently being processed,
     * captured before typed request/notification construction. Era
     * detection falls back to it because some typed families (e.g.
     * InitializedNotification) do not carry params at all — the envelope
     * must still select the era even when the typed object dropped it.
     */
    protected ?Meta $currentRawMeta = null;

    /**
     * Methods removed from the protocol surface by the 2026-07-28
     * revision (SEP-2575 "Deprecated and Removed RPCs"). On the modern
     * path they are answered with -32601 Method not found (HTTP 404),
     * never dispatched — even though legacy handlers for them remain
     * registered. `roots/list` is also removed but is a server→client
     * request and can never arrive here.
     */
    protected const MODERN_REMOVED_METHODS = [
        'initialize',
        'ping',
        'logging/setLevel',
        'resources/subscribe',
        'resources/unsubscribe',
    ];

    /**
     * Notifications removed by the 2026-07-28 revision. A notification
     * cannot be answered, so on the modern path these are ignored with a
     * log line instead of dispatched.
     */
    protected const MODERN_REMOVED_NOTIFICATIONS = [
        'notifications/initialized',
        'notifications/roots/list_changed',
    ];

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
        // Server-initiated shutdown ends any active stdio subscriptions:
        // answer their listen requests gracefully (spec PR #2953) while
        // the transport can still carry the responses. This runs before
        // the initialized guard — the guard exists to avoid double-stopping
        // the transport, while subscriptions track their own state (the
        // maps are cleared after responding, so a second stop() is a
        // no-op here too).
        $this->respondToActiveSubscriptions();

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
     * Intake override: capture the raw wire `_meta` for era detection
     * before typed construction can drop it, and convert typed-construction
     * failures into proper JSON-RPC answers instead of crashes.
     *
     * Typed request construction throws InvalidArgumentException for
     * unknown methods and for known methods missing required params. The
     * spec requires answering those — -32601 Method not found for unknown
     * (and, on the modern path, removed) methods; -32602 Invalid params
     * for malformed known methods — with the original request id, on both
     * eras. Letting the exception escape the read loop would tear down the
     * request with no JSON-RPC response at all.
     */
    protected function handleIncomingMessage(JsonRpcMessage $message): void {
        $inner = $message->message;

        if ($inner instanceof JSONRPCNotification) {
            $this->currentRawMeta = $inner->params?->_meta;
            try {
                parent::handleIncomingMessage($message);
            } finally {
                $this->currentRawMeta = null;
            }
            return;
        }

        if (!($inner instanceof \Mcp\Types\JSONRPCRequest)) {
            parent::handleIncomingMessage($message);
            return;
        }

        $this->currentRawMeta = $inner->params?->_meta;
        try {
            parent::handleIncomingMessage($message);
        } catch (InvalidArgumentException $e) {
            $this->answerMalformedRequest($inner, $e);
        } finally {
            $this->currentRawMeta = null;
        }
    }

    /**
     * Answer a request whose typed construction failed: -32601 for
     * unknown methods (and removed methods on the modern path, whatever
     * params they carried), -32602 for known methods with invalid params.
     * On the modern path the SEP-2575 pre-dispatch checks run FIRST, so a
     * broken envelope or unsupported version is rejected as malformed
     * (-32602/-32022, HTTP 400) before any method routing — the same
     * ordering handleModernRequest applies to well-formed requests. The
     * modern-era flag is set from the raw wire metadata so the HTTP
     * status hints (404/400) ride along on the stateless path.
     */
    private function answerMalformedRequest(\Mcp\Types\JSONRPCRequest $inner, InvalidArgumentException $e): void {
        $method = $inner->method;
        $isModern = $this->transportDeclaredModern
            || $method === 'server/discover'
            || self::metaCarriesModernEnvelope($inner->params?->_meta);
        $isUnknownMethod = $e instanceof \Mcp\Shared\UnknownMethodException;
        $isRemovedModern = $isModern && in_array($method, self::MODERN_REMOVED_METHODS, true);

        $this->currentRequestModern = $isModern;
        try {
            if ($isModern) {
                $preDispatchError = $this->modernEnvelopePreDispatchError($inner->params?->_meta, $method);
                if ($preDispatchError !== null) {
                    $this->sendResponse($inner->id, $preDispatchError);
                    return;
                }
            }
            if ($isUnknownMethod || $isRemovedModern) {
                $this->sendResponse($inner->id, new \Mcp\Shared\ErrorData(
                    code: -32601,
                    message: "Method not found: {$method}"
                        . ($isRemovedModern ? ' (removed in the 2026-07-28 revision)' : '')
                ));
            } else {
                $this->sendResponse($inner->id, new \Mcp\Shared\ErrorData(
                    code: -32602,
                    message: 'Invalid params: ' . $e->getMessage()
                ));
            }
        } finally {
            $this->currentRequestModern = false;
        }
    }

    /**
     * Handle incoming requests, selecting the protocol era per request
     * (SEP-2575 dual-era detection).
     *
     * A request carrying the modern per-request `_meta` envelope — or
     * arriving on a transport that declared the modern era from its own
     * version metadata (the MCP-Protocol-Version header) — is served
     * statelessly under the 2026-07-28 rules, even when its method names a
     * legacy construct like `initialize` (which is a removed method on
     * that path). An `initialize` request without modern metadata selects
     * legacy semantics. The session id is never part of this decision.
     *
     * @param RequestResponder $responder The request responder wrapping the client request.
     */
    public function handleRequest(RequestResponder $responder): void {
        $request = $responder->getRequest(); // a ClientRequest
        $actualRequest = $request->getRequest(); // the underlying typed Request
        $method = $actualRequest->method;
        $params = $actualRequest->params ?? null;

        if ($this->isModernEraRequest($method, $params)) {
            // The era a modern request adopts (negotiated version, client
            // info/capabilities, readiness) holds for exactly this
            // request's processing and is restored afterwards (SEP-2567:
            // nothing persists across requests). Without the restore, a
            // modern request would leave the session marked initialized,
            // letting a later bare (unenveloped, never-initialized) legacy
            // request through the handshake gate — and a modern request on
            // a legacy-initialized stdio session would clobber that
            // session's negotiated state.
            $savedState = $this->initializationState;
            $savedVersion = $this->negotiatedProtocolVersion;
            $savedClientParams = $this->clientParams;
            $this->currentRequestModern = true;
            try {
                $this->handleModernRequest($method, $params, $responder);
            } finally {
                $this->currentRequestModern = false;
                $this->initializationState = $savedState;
                $this->negotiatedProtocolVersion = $savedVersion;
                $this->clientParams = $savedClientParams;
            }
            return;
        }

        if ($method === 'initialize') {
            $respond = fn($result) => $responder->sendResponse($result);
            $this->handleInitialize($request, $respond);
            return;
        }

        if ($this->initializationState !== InitializationState::Initialized) {
            throw new \RuntimeException('Received request before initialization was complete');
        }

        $this->dispatchRegisteredHandler($method, $params, $responder);
    }

    /**
     * Dispatch a request to the method-keyed handlers registered via
     * registerHandlers(). Shared by the legacy and modern dispatch paths;
     * HttpServerSession extends it with the HTTP suspend/resume catches.
     */
    protected function dispatchRegisteredHandler(string $method, ?RequestParams $params, RequestResponder $responder): void {
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

        // SEP-2575 era detection mirrors handleRequest(): a notification
        // carrying the modern envelope (or arriving on a transport that
        // declared the modern era) is processed statelessly — no handshake
        // gate, and the notifications the revision removed are ignored
        // (a notification cannot be answered with an error).
        $isModern = $this->transportDeclaredModern
            || self::metaCarriesModernEnvelope($actualNotification->params?->_meta ?? null)
            || self::metaCarriesModernEnvelope($this->currentRawMeta);

        // SEP-2575: notifications/cancelled referencing a
        // subscriptions/listen request id is the stdio binding's way to
        // END that subscription — the server MUST stop forwarding its
        // notification types. Handled on both eras, before handler
        // dispatch, so registered cancellation handlers still run.
        if ($method === 'notifications/cancelled') {
            $this->cancelSubscriptionIfListenId($actualNotification);
        }

        if ($isModern) {
            if (in_array($method, self::MODERN_REMOVED_NOTIFICATIONS, true)) {
                $this->logger->warning("Ignoring notification removed in the 2026-07-28 revision: $method");
                return;
            }
            $this->dispatchRegisteredNotificationHandler($method, $actualNotification);
            return;
        }

        if ($method === 'notifications/initialized') {
            $this->initializationState = InitializationState::Initialized;
            $this->logger->info('Client has completed initialization.');
            return;
        }

        if ($this->initializationState !== InitializationState::Initialized) {
            throw new RuntimeException('Received notification before initialization was complete');
        }

        $this->dispatchRegisteredNotificationHandler($method, $actualNotification);
    }

    /**
     * Dispatch a notification to the method-keyed handlers registered via
     * registerNotificationHandlers(). Shared by the legacy and modern paths.
     */
    protected function dispatchRegisteredNotificationHandler(string $method, Notification $actualNotification): void {
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
     * Whether a request selects the 2026-07-28 modern era (SEP-2575).
     *
     * Modern iff: the transport declared the era from its version
     * metadata, the method is `server/discover` (a modern-only construct,
     * self-contained by design), or the params carry any of the modern
     * `_meta` envelope keys. Envelope detection is per-key rather than
     * requiring the full envelope so a *partial* envelope is routed to
     * modern validation (yielding the spec's precise -32602) instead of
     * the legacy path. The bare trace-context keys (SEP-414) are not
     * envelope keys and never trigger detection.
     */
    protected function isModernEraRequest(string $method, ?RequestParams $params): bool {
        if ($this->transportDeclaredModern || $method === 'server/discover') {
            return true;
        }
        return self::metaCarriesModernEnvelope($params?->_meta)
            || self::metaCarriesModernEnvelope($this->currentRawMeta);
    }

    /**
     * Whether a _meta payload carries any SEP-2575 envelope key.
     */
    protected static function metaCarriesModernEnvelope(?Meta $meta): bool {
        if ($meta === null) {
            return false;
        }
        $fields = $meta->getExtraFields();
        return array_key_exists(MetaKeys::PROTOCOL_VERSION, $fields)
            || array_key_exists(MetaKeys::CLIENT_INFO, $fields)
            || array_key_exists(MetaKeys::CLIENT_CAPABILITIES, $fields);
    }

    /**
     * Serve a request under the 2026-07-28 stateless rules (SEP-2575).
     *
     * Order of checks follows the spec: the `_meta` envelope must be
     * complete and well-formed (-32602), its protocol version must be one
     * the server can serve on this path (-32022 with data.supported/
     * requested), and only then is the method routed — removed-method and
     * unknown-method requests get -32601. The era, client info, and
     * client capabilities adopted from the envelope hold for exactly this
     * request's processing; nothing is persisted across requests
     * (SEP-2567).
     */
    protected function handleModernRequest(string $method, ?RequestParams $params, RequestResponder $responder): void {
        $respond = fn($result) => $responder->sendResponse($result);

        // Validate against the typed params' _meta, falling back to the
        // raw wire _meta for typed families that do not carry params.
        $meta = $params?->_meta ?? $this->currentRawMeta;

        $preDispatchError = $this->modernEnvelopePreDispatchError($meta, $method);
        if ($preDispatchError !== null) {
            $respond($preDispatchError);
            return;
        }

        /** @var Meta $meta */
        $requestedVersion = $meta->getExtraFields()[MetaKeys::PROTOCOL_VERSION];

        $this->adoptModernRequestState($requestedVersion, $meta);

        if ($method === 'server/discover') {
            $this->handleDiscover($respond);
            return;
        }

        if ($method === 'subscriptions/listen') {
            $this->handleSubscriptionsListen($responder, $params);
            return;
        }

        if (in_array($method, self::MODERN_REMOVED_METHODS, true)) {
            $respond(new \Mcp\Shared\ErrorData(
                code: -32601,
                message: "Method not found: {$method} (removed in the 2026-07-28 revision)"
            ));
            return;
        }

        $this->dispatchRegisteredHandler($method, $params, $responder);
    }

    /**
     * Parse and validate the `notifications` filter of a
     * subscriptions/listen request. Null when the required filter object
     * is missing or malformed (schema: `notifications` is required).
     */
    protected function parseListenFilter(?RequestParams $params): ?SubscriptionFilter {
        if ($params === null) {
            return null;
        }
        $serialized = $params->jsonSerialize();
        $fields = $serialized instanceof \stdClass ? (array) $serialized : (is_array($serialized) ? $serialized : []);
        $notifications = $fields['notifications'] ?? null;
        if ($notifications instanceof \stdClass) {
            $notifications = json_decode((string) json_encode($notifications), true);
        }
        if (!is_array($notifications)) {
            return null;
        }
        return SubscriptionFilter::fromArray($notifications);
    }

    /**
     * Serve `subscriptions/listen` on the stdio transport (SEP-2575).
     *
     * The acknowledgement notification is sent as the FIRST message of the
     * subscription and the request gets no JSON-RPC response while the
     * subscription is live — the subscription stays active for the life of
     * the session (the spec's stdio binding: the client cancels via
     * notifications/cancelled or by closing the transport; the server
     * holds no subscription state across reconnections). When the SERVER
     * ends the session on its own initiative, each active listen request
     * is answered with the graceful SubscriptionsListenResult (spec PR
     * #2953) before the transport closes. Change notifications published
     * through
     * deliverSubscriptionNotification() are forwarded to every active
     * subscription whose filter wants them, tagged with the subscription
     * id so the client can demultiplex.
     *
     * HttpServerSession overrides this: an HTTP listen stream needs the
     * runner's SAPI adapter, so the validated request is thrown upward as
     * a SubscriptionListenException instead.
     */
    protected function handleSubscriptionsListen(RequestResponder $responder, ?RequestParams $params): void {
        $filter = $this->parseListenFilter($params);
        if ($filter === null) {
            $responder->sendResponse(new \Mcp\Shared\ErrorData(
                code: -32602,
                message: 'Invalid params: subscriptions/listen requires a notifications filter object'
            ));
            return;
        }

        // stdio delivers resources/updated in-session, so the
        // resourceSubscriptions filter is deliverable whenever the server
        // serves resources at all.
        $agreed = $filter->intersectWithCapabilities(
            $this->initOptions->capabilities,
            resourceUpdatesDeliverable: $this->initOptions->capabilities->resources !== null
        );
        $subscriptionId = (string) $responder->getRequestId()->getValue();
        $this->activeSubscriptions[$subscriptionId] = $agreed;
        $this->activeSubscriptionRequestIds[$subscriptionId] = $responder->getRequestId();

        $this->writeSubscriptionNotification(
            'notifications/subscriptions/acknowledged',
            ['notifications' => $agreed->jsonSerialize()],
            $responder->getRequestId()->getValue()
        );
        // Deliberately no $responder->sendResponse() here: the stream of
        // notifications IS the response while the subscription is live.
        // The one JSON-RPC result the listen request can ever receive is
        // the graceful end-of-subscription SubscriptionsListenResult sent
        // when the SERVER ends the subscription on its own initiative
        // (spec PR #2953) — see respondToActiveSubscriptions().
    }

    /**
     * Forward a change notification to every active stdio subscription
     * whose filter opted in to it. No-op when nothing is subscribed.
     *
     * @param array<string, mixed> $params Notification params (without _meta)
     */
    public function deliverSubscriptionNotification(string $method, array $params = []): void {
        $uri = isset($params['uri']) && is_string($params['uri']) ? $params['uri'] : null;
        foreach ($this->activeSubscriptions as $subscriptionId => $filter) {
            if ($filter->wants($method, $uri)) {
                // PHP silently converts numeric-string array keys to ints,
                // so the wire value comes from the stored RequestId — the
                // schema types the _meta key as RequestId, preserving the
                // listen id's original int-vs-string wire type.
                $key = (string) $subscriptionId;
                $wireId = isset($this->activeSubscriptionRequestIds[$key])
                    ? $this->activeSubscriptionRequestIds[$key]->getValue()
                    : $key;
                $this->writeSubscriptionNotification($method, $params, $wireId);
            }
        }
    }

    /**
     * Active stdio subscription filters keyed by subscription id.
     *
     * @return array<string, SubscriptionFilter>
     */
    public function getActiveSubscriptions(): array {
        return $this->activeSubscriptions;
    }

    /**
     * Answer every active stdio listen request with the graceful
     * end-of-subscription SubscriptionsListenResult (spec PR #2953) and
     * drop the subscriptions. Called when the SERVER ends the
     * subscriptions on its own initiative — session stop/shutdown — so a
     * connected client can distinguish the clean end from an abrupt
     * transport drop (which carries no response and MAY trigger a
     * reconnect).
     *
     * The response is written directly to the transport: the graceful-end
     * result is modern-only wire shape by definition (the subscription
     * exists only on the 2026-07-28 path), while the session's era state
     * at shutdown may have been restored to whatever a legacy handshake
     * negotiated — adaptResponseForClient() must not strip it. Write
     * failures are swallowed: at shutdown the peer may already be gone,
     * and an unreachable client simply experiences the abrupt-drop case
     * the spec also allows.
     */
    protected function respondToActiveSubscriptions(): void {
        foreach (array_keys($this->activeSubscriptions) as $subscriptionId) {
            $subscriptionId = (string) $subscriptionId;
            $requestId = $this->activeSubscriptionRequestIds[$subscriptionId]
                ?? new RequestId($subscriptionId);
            try {
                // The _meta subscriptionId is typed RequestId and MUST
                // equal this response's own id — original wire type
                // preserved, never stringified.
                $this->transport->writeMessage(new JsonRpcMessage(new JSONRPCResponse(
                    jsonrpc: '2.0',
                    id: $requestId,
                    result: new SubscriptionsListenResult($requestId->getValue())
                )));
            } catch (\Throwable $e) {
                $this->logger->info(
                    "Could not deliver graceful end for subscription $subscriptionId: " . $e->getMessage()
                );
            }
        }
        $this->activeSubscriptions = [];
        $this->activeSubscriptionRequestIds = [];
    }

    /**
     * Drop the stdio subscription a notifications/cancelled refers to,
     * when its requestId matches an active listen request. No-op for
     * cancellations of ordinary requests.
     */
    protected function cancelSubscriptionIfListenId(Notification $notification): void {
        $params = $notification->params;
        if ($params === null) {
            return;
        }
        $serialized = $params->jsonSerialize();
        $fields = $serialized instanceof \stdClass
            ? (array) $serialized
            : (is_array($serialized) ? $serialized : []);
        $requestId = $fields['requestId'] ?? null;
        if (!is_string($requestId) && !is_int($requestId)) {
            return;
        }
        $key = (string) $requestId;
        if (isset($this->activeSubscriptions[$key])) {
            unset($this->activeSubscriptions[$key], $this->activeSubscriptionRequestIds[$key]);
            $this->logger->info("Subscription $key cancelled by client");
        }
    }

    /**
     * Write a notification onto the transport with the SEP-2575
     * subscription-correlation id stamped into `_meta`
     * (`io.modelcontextprotocol/subscriptionId` — required on every frame
     * of the channel, including the acknowledgement). The id is typed
     * RequestId in the schema: it carries the listen request's JSON-RPC id
     * in its original wire type (int stays a JSON number).
     *
     * @param array<string, mixed> $params Notification params (without _meta)
     */
    protected function writeSubscriptionNotification(string $method, array $params, string|int $subscriptionId): void {
        $meta = new Meta();
        $meta->setField(MetaKeys::SUBSCRIPTION_ID, $subscriptionId);

        $notificationParams = new NotificationParams($meta);
        foreach ($params as $key => $value) {
            if ($value !== null) {
                $notificationParams->$key = $value;
            }
        }

        $this->writeMessage(new JsonRpcMessage(new JSONRPCNotification(
            jsonrpc: '2.0',
            method: $method,
            params: $notificationParams
        )));
    }

    /**
     * Adopt the era a modern request declared in its envelope for the
     * duration of this request's processing: the canonical negotiated
     * version (the RC-window draft alias maps onto 2026-07-28 for all
     * internal feature gating), readiness (the stateless lifecycle has no
     * handshake to wait for), and the request's own client info and
     * capabilities — the spec forbids inferring capabilities from prior
     * requests, so each request's envelope fully replaces the previous
     * state.
     */
    protected function adoptModernRequestState(string $wireVersion, Meta $meta): void {
        if (!Version::isModernVersion($wireVersion)) {
            // server/discover may carry a legacy revision in its envelope;
            // there is no era to adopt from it.
            return;
        }
        $fields = $meta->getExtraFields();
        $this->negotiatedProtocolVersion = Version::canonicalizeVersion($wireVersion);
        $this->initializationState = InitializationState::Initialized;
        $this->clientParams = new InitializeRequestParams(
            protocolVersion: $this->negotiatedProtocolVersion,
            capabilities: self::clientCapabilitiesFromEnvelopeValue($fields[MetaKeys::CLIENT_CAPABILITIES]),
            clientInfo: self::implementationFromEnvelopeValue($fields[MetaKeys::CLIENT_INFO]),
        );
    }

    /**
     * Coerce a validated _meta clientInfo value into an Implementation.
     */
    private static function implementationFromEnvelopeValue(mixed $value): Implementation {
        if ($value instanceof Implementation) {
            return $value;
        }
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        return new Implementation(
            name: (string) ($value['name'] ?? ''),
            version: (string) ($value['version'] ?? '')
        );
    }

    /**
     * Coerce a validated _meta clientCapabilities value into a
     * ClientCapabilities instance.
     */
    private static function clientCapabilitiesFromEnvelopeValue(mixed $value): ClientCapabilities {
        if ($value instanceof ClientCapabilities) {
            return $value;
        }
        if ($value instanceof \stdClass) {
            // Normalize nested stdClass (in-process callers) to arrays.
            $value = json_decode(json_encode($value), true);
        }
        if (!is_array($value)) {
            return new ClientCapabilities();
        }
        return ClientCapabilities::fromArray($value);
    }

    /**
     * Handle the `server/discover` request (SEP-2575, revision 2026-07-28).
     *
     * Reached only through handleModernRequest(), which has already
     * validated the _meta envelope and the requested version. The
     * capabilities advertised here are the same object the legacy
     * initialize result advertises (InitializationOptions::capabilities,
     * built from Server::getCapabilities()), so the two discovery surfaces
     * can never disagree.
     *
     * @param callable $respond Responder receiving a DiscoverResult or ErrorData
     */
    protected function handleDiscover(callable $respond): void {
        $result = new DiscoverResult(
            supportedVersions: Version::advertisedSupportedVersions(),
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
     * The SEP-2575 checks that precede method routing on the modern path:
     * the `_meta` envelope must be complete and well-formed (-32602), and
     * its protocol version must be one the server can serve on this path
     * (-32022 with data.supported/requested). Shared by the typed dispatch
     * (handleModernRequest) and the malformed-request answer path
     * (answerMalformedRequest), so an unknown or removed method with a
     * broken envelope is still rejected as malformed (400) rather than
     * answered -32601 (404) — envelope validation comes first for every
     * method.
     *
     * server/discover answers for every advertised revision — it is the
     * discovery surface for both eras. Every other modern request is
     * servable only under a modern wire identifier (including the
     * RC-window draft alias).
     */
    protected function modernEnvelopePreDispatchError(?Meta $meta, string $method): ?\Mcp\Shared\ErrorData {
        $envelopeError = $this->validateModernRequestMeta($meta);
        if ($envelopeError !== null) {
            return $envelopeError;
        }

        $requestedVersion = $meta->getExtraFields()[MetaKeys::PROTOCOL_VERSION];
        $acceptedVersions = $method === 'server/discover'
            ? Version::advertisedSupportedVersions()
            : Version::MODERN_PROTOCOL_VERSIONS;
        if (!in_array($requestedVersion, $acceptedVersions, true)) {
            return new \Mcp\Shared\ErrorData(
                code: \Mcp\Shared\McpError::UNSUPPORTED_PROTOCOL_VERSION,
                message: 'Unsupported protocol version',
                data: [
                    'supported' => $acceptedVersions,
                    'requested' => $requestedVersion,
                ],
            );
        }

        return null;
    }

    /**
     * Validate the SEP-2575 per-request `_meta` envelope.
     *
     * @return \Mcp\Shared\ErrorData|null An InvalidParams (-32602) error
     *         naming the missing/invalid field, or null when the envelope
     *         is valid.
     */
    protected function validateModernRequestMeta(?Meta $meta): ?\Mcp\Shared\ErrorData {
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
        $fields = $meta->getExtraFields();
        foreach ($required as $key) {
            if (!array_key_exists($key, $fields)) {
                return new \Mcp\Shared\ErrorData(
                    code: -32602,
                    message: "Invalid params: missing required _meta field: {$key}"
                );
            }
            if ($fields[$key] === null) {
                return new \Mcp\Shared\ErrorData(
                    code: -32602,
                    message: "Invalid params: _meta field {$key} must not be null"
                );
            }
        }

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
     *
     * @deprecated The Logging feature is deprecated as of protocol revision
     *             2026-07-28 (SEP-2577); it keeps working for at least the
     *             twelve-month deprecation window. Migration: log to stderr
     *             for stdio transports; use OpenTelemetry for observability.
     *             See the deprecated features registry.
     */
    public function sendLogMessage(
        LoggingLevel $level,
        mixed $data,
        ?string $logger = null
    ): void {
        $this->warnDeprecatedFeature(\Mcp\Shared\FeatureLifecycle::LOGGING);
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
            $this->raiseMissingClientCapabilityIfModern(['elicitation']);
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
     *
     * @deprecated The Sampling feature is deprecated as of protocol revision
     *             2026-07-28 (SEP-2577); it keeps working for at least the
     *             twelve-month deprecation window. Migration: integrate
     *             directly with LLM provider APIs. The `includeContext`
     *             values `"thisServer"`/`"allServers"` were already
     *             deprecated at 2025-11-25 (SEP-2596): omit the field or use
     *             `"none"`, and only send the deprecated values to a client
     *             declaring the `sampling.context` capability.
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
        $this->warnDeprecatedFeature(\Mcp\Shared\FeatureLifecycle::SAMPLING);
        if ($includeContext === 'thisServer' || $includeContext === 'allServers') {
            $this->warnDeprecatedFeature(\Mcp\Shared\FeatureLifecycle::SAMPLING_INCLUDE_CONTEXT);
        }
        // Client must declare the sampling capability.
        $requiredCap = new ClientCapabilities(sampling: new SamplingCapability());
        if (!$this->checkClientCapability($requiredCap)) {
            $this->raiseMissingClientCapabilityIfModern(['sampling']);
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

    /** @see EmitsDeprecationWarnings — gate on this session's negotiated revision. */
    protected function deprecationProtocolVersion(): ?string {
        return $this->initializationState === InitializationState::Initialized
            ? $this->negotiatedProtocolVersion
            : null;
    }

    /** @see EmitsDeprecationWarnings */
    protected function deprecationLogger(): \Psr\Log\LoggerInterface {
        return $this->logger;
    }

    /**
     * Enforce SEP-2575's missing-capability rule for the modern path: a
     * server MUST NOT rely on capabilities the request's `_meta` envelope
     * did not declare — when processing requires one, the request fails
     * with MissingRequiredClientCapabilityError (-32021, listing the
     * capabilities in data.requiredCapabilities; HTTP 400) rather than
     * degrading silently. On legacy revisions this is a no-op: the
     * pre-2026 contract is "MUST NOT send without capability", which the
     * callers honor by returning null so handlers can fall back.
     *
     * Wire shape: `data.requiredCapabilities` is a ClientCapabilities
     * OBJECT (e.g. `{"sampling": {}}`), per the SEP-2575 final text, the
     * draft schema's canonical example
     * (`MissingRequiredClientCapabilityError/missing-elicitation-capability.json`),
     * and the TypeScript SDK v2 types. Note: the pinned draft conformance
     * tool (0.2.0-alpha.7) asserts a string array instead — a known
     * upstream tool bug (now contradicting the tool's own vendored draft
     * schema and its SEP-2663 Tasks scenario), documented in
     * conformance/conformance-draft-baseline.yml; the official text wins.
     *
     * @param string[] $requiredCapabilities Capability names (e.g. ['sampling'])
     * @throws \Mcp\Shared\McpError When the current request selected the modern era.
     */
    public function raiseMissingClientCapabilityIfModern(array $requiredCapabilities): void {
        if (!$this->currentRequestModern) {
            return;
        }
        // Each required capability becomes an empty capability object,
        // mirroring how a client would have declared it (stdClass so empty
        // objects serialize as {} rather than []).
        $capabilitiesObject = [];
        foreach ($requiredCapabilities as $capability) {
            $capabilitiesObject[$capability] = new \stdClass();
        }
        throw new \Mcp\Shared\McpError(new \Mcp\Shared\ErrorData(
            code: \Mcp\Shared\McpError::MISSING_REQUIRED_CLIENT_CAPABILITY,
            message: 'Missing required client capability: ' . implode(', ', $requiredCapabilities),
            data: ['requiredCapabilities' => (object) $capabilitiesObject],
        ));
    }

    /**
     * Whether the client declared support for a SEP-2133 extension (by
     * reverse-DNS id) in this request's `_meta` clientCapabilities envelope.
     * Always false outside the modern path, where there is no extensions
     * map to consult.
     */
    public function clientDeclaresExtension(string $extensionId): bool {
        $capabilities = $this->clientParams?->capabilities;
        if (!$capabilities instanceof ClientCapabilities) {
            return false;
        }
        return is_array($capabilities->extensions)
            && array_key_exists($extensionId, $capabilities->extensions);
    }

    /**
     * Enforce a required SEP-2133 extension on the modern path: a server that
     * can only serve a request as part of an extension (e.g. a task-required
     * tool, or any `tasks/*` method) rejects a client that did not declare
     * the extension with -32021 (MissingRequiredClientCapability), carrying
     * `data.requiredCapabilities.extensions[<id>] = {}` per the SEP-2575
     * object shape. No-op on legacy revisions, where extensions do not exist.
     *
     * @throws \Mcp\Shared\McpError When the current request selected the modern era.
     */
    public function raiseMissingExtensionIfModern(string $extensionId): void {
        if (!$this->currentRequestModern) {
            return;
        }
        $this->raiseMissingExtension($extensionId);
    }

    /**
     * Unconditionally reject a request that requires a SEP-2133 extension the
     * client did not declare, with -32021 (MissingRequiredClientCapability)
     * carrying `data.requiredCapabilities.extensions[<id>] = {}`.
     *
     * Unlike {@see raiseMissingExtensionIfModern()}, this is NOT gated on the
     * modern era: it guards methods that exist ONLY as part of an extension
     * (e.g. `tasks/get`/`tasks/update`/`tasks/cancel`), which must never be
     * served to a caller — legacy or modern — that did not opt in by
     * declaring the extension. Extensions are a 2026-07-28 construct, so a
     * legacy caller can never have declared one and is always rejected here.
     *
     * @throws \Mcp\Shared\McpError Always.
     */
    public function raiseMissingExtension(string $extensionId): void {
        throw new \Mcp\Shared\McpError(new \Mcp\Shared\ErrorData(
            code: \Mcp\Shared\McpError::MISSING_REQUIRED_CLIENT_CAPABILITY,
            message: 'Missing required client capability: extensions.' . $extensionId,
            data: ['requiredCapabilities' => (object) [
                'extensions' => (object) [$extensionId => new \stdClass()],
            ]],
        ));
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
        $version = Version::canonicalizeVersion($version);
        if (!in_array($version, Version::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            throw new InvalidArgumentException("Unsupported protocol version: {$version}");
        }
        $this->negotiatedProtocolVersion = $version;
        if (Version::supportsFeature($version, 'stateless_lifecycle')) {
            $this->initializationState = InitializationState::Initialized;
        }
    }

    /**
     * Declare that the transport identified the current request as
     * modern-era from its own version metadata (SEP-2575) — for HTTP, an
     * MCP-Protocol-Version header carrying a modern wire identifier. Set
     * per request by the HTTP runner so a modern request whose body lacks
     * the `_meta` envelope still reaches modern validation (-32602)
     * instead of the legacy path.
     *
     * @internal Intended for the SDK's own runners and for tests.
     */
    public function declareTransportModernEra(bool $modern = true): void {
        $this->transportDeclaredModern = $modern;
    }

    /**
     * Forward the lower-cased HTTP header map of the modern request being
     * served (SEP-2243). Set per request by the HTTP runner; null on stdio
     * and on legacy HTTP requests.
     *
     * @param array<string, string>|null $headers
     * @internal Intended for the SDK's own runners and for tests.
     */
    public function setTransportHttpHeaders(?array $headers): void {
        $this->transportHttpHeaders = $headers;
    }

    /**
     * Look up a header of the current request by name (case-insensitive).
     * Null when the header is absent or the request has no HTTP headers
     * (stdio / legacy).
     */
    public function getTransportHttpHeader(string $name): ?string {
        if ($this->transportHttpHeaders === null) {
            return null;
        }
        return $this->transportHttpHeaders[strtolower($name)] ?? null;
    }

    /**
     * The full lower-cased header map of the current request, or null when
     * none was provided (stdio / legacy HTTP).
     *
     * @return array<string, string>|null
     */
    public function getTransportHttpHeaders(): ?array {
        return $this->transportHttpHeaders;
    }

    /**
     * Authenticated principal of the current request (the OAuth token's
     * `sub` claim), forwarded by the HTTP runner when token validation is
     * enabled. Null for anonymous and stdio requests. SEP-2322 binds
     * `requestState` to it so one authenticated user cannot replay
     * another user's captured multi-round-trip state.
     */
    protected ?string $authenticatedPrincipal = null;

    /**
     * @internal Intended for the SDK's own runners and for tests.
     */
    public function setAuthenticatedPrincipal(?string $principal): void {
        $this->authenticatedPrincipal = $principal;
    }

    public function getAuthenticatedPrincipal(): ?string {
        return $this->authenticatedPrincipal;
    }

    /**
     * The per-request log level the current request carried in
     * `_meta["io.modelcontextprotocol/logLevel"]` (SEP-2577 — the
     * 2026-07-28 replacement for the removed logging/setLevel; the key is
     * deprecated-at-birth upstream but remains the only way modern
     * requests opt in to notifications/message). Null when absent: the
     * server MUST NOT emit log notifications for that request.
     */
    public function getCurrentRequestLogLevel(): ?string {
        $fields = $this->currentRawMeta?->getExtraFields() ?? [];
        $level = $fields[MetaKeys::LOG_LEVEL] ?? null;
        if (is_string($level)) {
            // A client opting in to notifications/message is negotiating
            // the deprecated Logging feature (SEP-2577).
            $this->warnDeprecatedFeature(\Mcp\Shared\FeatureLifecycle::LOGGING);
            return $level;
        }
        return null;
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

        // DiscoverResult is a modern-only construct: its resultType and
        // cache hints are required wire fields regardless of what legacy
        // revision this session happens to have negotiated (e.g. a probe
        // arriving on an already-initialized stdio session). Never strip it.
        if ($response instanceof DiscoverResult) {
            return $response;
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

        // SEP-2575 HTTP status mapping: on the modern stateless path,
        // certain JSON-RPC errors must ride specific HTTP statuses. Stamp
        // the structured hint here — the single choke point every modern
        // error response passes through — and let the HTTP transport apply
        // it when building the response. Non-HTTP transports ignore it.
        if ($this->currentRequestModern && $innerMessage instanceof JSONRPCError) {
            $message->httpStatusHint = self::modernErrorHttpStatus($innerMessage->error->code);
        }

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

    /**
     * The HTTP status SEP-2575 mandates for a JSON-RPC error code on the
     * modern stateless path: malformed envelope (-32602), header mismatch
     * (-32020), missing required client capability (-32021), and
     * unsupported protocol version (-32022) are 400 Bad Request; an
     * unknown or removed method (-32601) is 404 Not Found. Every other
     * error rides the default 200.
     */
    protected static function modernErrorHttpStatus(int $code): ?int {
        return match ($code) {
            -32601 => 404,
            -32602,
            \Mcp\Shared\McpError::HEADER_MISMATCH,
            \Mcp\Shared\McpError::MISSING_REQUIRED_CLIENT_CAPABILITY,
            \Mcp\Shared\McpError::UNSUPPORTED_PROTOCOL_VERSION => 400,
            default => null,
        };
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
