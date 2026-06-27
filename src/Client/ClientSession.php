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
 * Filename: Client/ClientSession.php
 */

declare(strict_types=1);

namespace Mcp\Client;

use Mcp\Client\Auth\Exception\AuthorizationRedirectException;
use Mcp\Client\Transport\HttpAuthenticationException;
use Mcp\Client\Transport\HttpRequestTimeoutException;
use Mcp\Client\Transport\ReadTimeoutException;
use Mcp\Shared\BaseSession;
use Mcp\Shared\ErrorData;
use Mcp\Shared\McpHeaders;
use Mcp\Shared\RequestResponder;
use Mcp\Shared\Version;
use Mcp\Shared\MemoryStream;
use Mcp\Types\ClientRequest;
use Mcp\Types\ClientNotification;
use Mcp\Types\DiscoverRequest;
use Mcp\Types\DiscoverResult;
use Mcp\Types\Meta;
use Mcp\Types\MetaKeys;
use Mcp\Types\RequestParams;
use Mcp\Types\ElicitationCapability;
use Mcp\Types\ElicitationCreateRequest;
use Mcp\Types\ElicitationCreateResult;
use Mcp\Types\CreateMessageRequest;
use Mcp\Types\CreateMessageResult;
use Mcp\Types\InputRequiredResult;
use Mcp\Types\Request;
use Mcp\Types\Result;
use Mcp\Types\SamplingCapability;
use Mcp\Types\ServerRequest;
use Mcp\Types\ServerNotification;
use Mcp\Types\InitializeRequest;
use Mcp\Types\InitializeRequestParams;
use Mcp\Types\InitializeResult;
use Mcp\Types\EmptyResult;
use Mcp\Types\Implementation;
use Mcp\Types\ClientCapabilities;
use Mcp\Types\ClientRootsCapability;
use Mcp\Types\LoggingLevel;
use Mcp\Types\ProgressToken;
use Mcp\Types\ListResourcesResult;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\CallToolResult;
use Mcp\Types\ListPromptsResult;
use Mcp\Types\GetPromptResult;
use Mcp\Types\ListToolsResult;
use Mcp\Types\CompleteResult;
use Mcp\Types\ResourceReference;
use Mcp\Types\PromptReference;
use Mcp\Types\InitializedNotification;
use Mcp\Types\ProgressNotification;
use Mcp\Types\PingRequest;
use Mcp\Types\ListRootsRequest;
use Mcp\Types\ListRootsResult;
use Mcp\Types\RootsListChangedNotification;
use Mcp\Types\JsonRpcMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use InvalidArgumentException;

/**
 * Class ClientSession
 *
 * Client session for MCP communication.
 *
 * The client interacts with a server by sending requests and notifications, and receiving responses.
 */
class ClientSession extends BaseSession {
    /** @var InitializeResult|null */
    private ?InitializeResult $initResult = null;

    /** @var bool */
    private bool $initialized = false;

    /** @var bool True when the session was rehydrated via createRestored() — capabilities were negotiated in a prior PHP request and cannot be re-advertised. */
    private bool $isRestored = false;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /** @var MemoryStream|null */
    private ?MemoryStream $readStream = null;

    /** @var MemoryStream|null */
    private ?MemoryStream $writeStream = null;

    /** @var float|null */
    private ?float $readTimeout = null;

    /** @var string|null */
    private ?string $negotiatedProtocolVersion = null;

    /**
     * The wire identifier this session speaks on the modern (2026-07-28)
     * per-request path — either the dated revision or the RC-window draft
     * alias the server advertised. Null while the session is legacy-era
     * (or not yet negotiated). When set, every outgoing request and
     * notification is stamped with the SEP-2575 `_meta` envelope carrying
     * this identifier, and {@see $negotiatedProtocolVersion} holds its
     * canonical form for feature gating.
     */
    private ?string $modernWireVersion = null;

    /**
     * Default probe timeout (seconds) for {@see negotiate()} when neither
     * a probe timeout nor a session read timeout was configured. The spec
     * requires falling back to the legacy handshake when a server does
     * not answer `server/discover` "within a reasonable timeout" — legacy
     * servers may answer unknown pre-initialize requests with nothing at
     * all. Public so Client::connect() applies the same default when
     * bounding the HTTP transport's probe requests.
     */
    public const DEFAULT_PROBE_TIMEOUT = 10.0;

    /** @var callable|null User-registered elicitation handler (one per session). */
    private $elicitationHandler = null;

    /** @var bool Whether to auto-fill schema defaults on accept responses. */
    private bool $elicitationApplyDefaults = false;

    /** @var bool Whether the registered handler is prepared to handle URL-mode requests (2025-11-25). */
    private bool $elicitationSupportsUrlMode = false;

    /** @var callable|null User-registered roots/list handler (one per session). */
    private $rootsHandler = null;

    /** @var bool Whether the client will emit notifications/roots/list_changed. */
    private bool $rootsListChanged = true;

    /** @var callable|null User-registered sampling/createMessage handler (one per session). */
    private $samplingHandler = null;

    /**
     * Whether this session rides the Streamable HTTP transport. Gates the
     * SEP-2243 x-mcp-header annotation processing: HTTP clients MUST
     * validate annotations and exclude invalid tools, while stdio clients
     * MUST ignore annotations entirely (the stdio transport has no
     * headers), keeping stdio tools/list results unfiltered. Set by
     * Client::connect() for HTTP connections.
     */
    private bool $httpTransportMode = false;

    /**
     * Per-tool x-mcp-header annotation maps (property path =>
     * {annotation, type, segments} as collected by
     * McpHeaders::collectAnnotations() — annotations may sit at any
     * nesting depth), cached from the most recent tools/list on the
     * modern HTTP path and refreshed on every listTools() call.
     *
     * @var array<string, array<string, array{annotation: string, type: string, segments: list<string>}>>
     */
    private array $toolHeaderAnnotations = [];

    /**
     * Tools excluded from the most recent tools/list because their
     * x-mcp-header annotations are invalid (tool name => error list).
     * callTool() refuses these without touching the wire.
     *
     * @var array<string, list<string>>
     */
    private array $rejectedToolErrors = [];

    /**
     * Transient Mcp-Param-* header hints for the in-flight request: the
     * method they belong to plus the header map. Consulted by
     * writeMessage() so the hints attach to the matching outgoing request
     * only — never to interleaved responses or unrelated messages sent
     * while the call is in flight (see executeModernCall()).
     *
     * @var array{method: string, headers: array<string, string>}|null
     */
    private ?array $pendingHeaderHints = null;

    /**
     * Maximum SEP-2322 input_required rounds serviced per call before the
     * multi-round-trip loop gives up (guards against a server that never
     * completes).
     */
    public const MAX_MRTR_ROUNDS = 16;

    /**
     * ClientSession constructor.
     *
     * @param MemoryStream    $readStream   Stream to read incoming messages from.
     * @param MemoryStream    $writeStream  Stream to write outgoing messages to.
     * @param LoggerInterface|null $logger  PSR-3 compliant logger.
     * @param float|null      $readTimeout  Optional read timeout in seconds.
     *
     * @throws InvalidArgumentException If the provided streams are invalid.
     */
    public function __construct(
        MemoryStream $readStream,
        MemoryStream $writeStream,
        ?float $readTimeout = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct(
            receiveRequestType: ServerRequest::class,
            receiveNotificationType: ServerNotification::class
        );

        $this->readStream = $readStream;
        $this->writeStream = $writeStream;
        $this->readTimeout = $readTimeout;
        $this->logger = $logger ?? new NullLogger();

        $this->registerBuiltinPingResponder();
    }

    /**
     * Register a built-in handler that auto-responds to server-initiated
     * `ping` requests with an empty result.
     *
     * Per the MCP spec (https://modelcontextprotocol.io/specification/2025-11-25/basic/utilities/ping),
     * either side can initiate a ping at any time and the receiver MUST respond
     * promptly with `{}`. Without this, a server probing client liveness would
     * see its ping time out and might consider the connection stale and tear it
     * down, even when the PHP process is healthy and processing other work.
     *
     * The handler is registered first, so user-registered handlers run after it
     * and may inspect the request — but it short-circuits via hasResponded() if
     * a user handler beat it to the response, leaving room for advanced callers
     * to override the default behavior without colliding with the built-in.
     */
    private function registerBuiltinPingResponder(): void {
        $this->onRequest(function (RequestResponder $responder): void {
            $wrapper = $responder->getRequest();
            if (!($wrapper instanceof ServerRequest)) {
                return;
            }
            if (!($wrapper->getRequest() instanceof PingRequest)) {
                return;
            }
            if ($responder->hasResponded()) {
                // A user-registered onRequest handler already replied; do nothing.
                return;
            }
            $responder->sendResponse(new EmptyResult());
        });
    }

    /**
     * Create a restored ClientSession that skips the initialization handshake.
     *
     * Used to resume a previously established MCP session (e.g., across PHP requests).
     * The session is immediately ready for operations without sending initialize/initialized.
     *
     * @param MemoryStream $readStream Stream to read incoming messages from
     * @param MemoryStream $writeStream Stream to write outgoing messages to
     * @param InitializeResult $initResult The initialization result from the original session
     * @param string $negotiatedProtocolVersion The protocol version negotiated in the original session
     * @param int $nextRequestId The next request ID to use (to avoid collisions)
     * @param float|null $readTimeout Optional read timeout in seconds
     * @param LoggerInterface|null $logger PSR-3 compliant logger
     * @return self A session ready for operations
     */
    public static function createRestored(
        MemoryStream $readStream,
        MemoryStream $writeStream,
        InitializeResult $initResult,
        string $negotiatedProtocolVersion,
        int $nextRequestId,
        ?float $readTimeout = null,
        ?LoggerInterface $logger = null
    ): self {
        $session = new self($readStream, $writeStream, $readTimeout, $logger);
        $session->initResult = $initResult;
        $session->negotiatedProtocolVersion = $negotiatedProtocolVersion;
        $session->initialized = true;
        $session->isInitialized = true;
        $session->isRestored = true;
        $session->setNextRequestId($nextRequestId);
        return $session;
    }

    /**
     * Get the current request ID counter value.
     *
     * Used to persist the counter across PHP requests for session resumption.
     *
     * @return int The next request ID that will be used
     */
    public function getNextRequestId(): int {
        return parent::getNextRequestId();
    }

    /**
     * Initialize the client session by sending an InitializeRequest and then an InitializedNotification.
     *
     * @throws RuntimeException If initialization fails due to unsupported protocol version or other issues.
     *
     * @return void
     */
    public function initialize(): void {
        $this->logger->info('Initializing client session');

        // Create and send InitializeRequest. The handshake itself is a
        // legacy-era construct: the 2026-07-28 revision removes initialize
        // (SEP-2575), so the highest version it makes sense to request here
        // is the latest legacy revision. The modern per-request path is
        // selected via server/discover instead (see discover()).
        $initRequest = new InitializeRequest(
            new InitializeRequestParams(
                protocolVersion: Version::LATEST_LEGACY_PROTOCOL_VERSION,
                capabilities: $this->buildClientCapabilities(),
                clientInfo: $this->clientIdentity()
            )
        );

        /** @var InitializeResult $result */
        $result = $this->sendRequest($initRequest, InitializeResult::class);

        // Validate protocol version
        if (!in_array($result->protocolVersion, Version::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            throw new RuntimeException(
                "Unsupported protocol version from server: {$result->protocolVersion}"
            );
        }

        $this->negotiatedProtocolVersion = $result->protocolVersion;

        // Send InitializedNotification
        $initializedNotification = new InitializedNotification();
        $this->sendNotification($initializedNotification);

        $this->initResult = $result;
        $this->initialized = true;

        $this->logger->info('Client session initialized successfully');

        // Start message processing if necessary
        $this->startMessageProcessing();
    }

    /**
     * Send a `server/discover` request (SEP-2575, revision 2026-07-28).
     *
     * Discover is self-contained: it carries the per-request `_meta` envelope
     * (protocol version, client info, client capabilities) and may be sent
     * without — or before — the legacy initialize handshake. Calling it does
     * not change this session's negotiated protocol version; the dual-era
     * probe/fallback logic that acts on its result is the WS2 milestone.
     *
     * @param string|null $protocolVersion Protocol revision to advertise in
     *        the envelope (defaults to the latest supported revision)
     * @throws \Mcp\Shared\McpError If the server rejects the request (e.g.
     *         -32601 from a legacy server, -32022 for an unsupported version)
     */
    public function discover(?string $protocolVersion = null): DiscoverResult {
        $meta = new Meta();
        $meta->setField(MetaKeys::PROTOCOL_VERSION, $protocolVersion ?? Version::LATEST_PROTOCOL_VERSION);
        $meta->setField(MetaKeys::CLIENT_INFO, $this->clientIdentity());
        $meta->setField(MetaKeys::CLIENT_CAPABILITIES, $this->buildClientCapabilities());

        $request = new DiscoverRequest(new RequestParams($meta));

        /** @var DiscoverResult */
        return $this->sendRequest($request, DiscoverResult::class);
    }

    /**
     * Negotiate the protocol era with the server (SEP-2575 dual-era
     * client detection), establishing this session as either modern
     * (2026-07-28 per-request lifecycle) or legacy (initialize handshake).
     *
     * Sequencing follows the spec's normative detection rules:
     *
     * 1. Probe with `server/discover`, preferring the latest modern
     *    revision. Success → modern; the session is immediately ready and
     *    every subsequent request carries the per-request `_meta` envelope.
     * 2. On UnsupportedProtocolVersionError (-32022), the server is
     *    modern: retry with a version from its advertised
     *    `data.supported` list. Never fall back to `initialize` — when no
     *    advertised version is mutually supported, fail.
     * 3. On the other recognized modern errors (-32020 HeaderMismatch,
     *    -32021 MissingRequiredClientCapability), the server is modern;
     *    the error is re-thrown rather than treated as an era signal.
     * 4. On any *other* error — a legacy server's implementation-defined
     *    rejection (commonly -32601 or -32602, or an HTTP 400 whose body
     *    is not a recognized modern JSON-RPC error) — or on probe
     *    timeout, fall back to the legacy `initialize` handshake. Per
     *    spec, the fallback is deliberately not keyed to one specific
     *    error code.
     *
     * Authentication failures propagate unchanged: they are not era
     * signals, and both eras require the same credentials.
     *
     * @param string $mode 'auto' (probe, fall back to legacy — the
     *        default), 'legacy' (skip the probe, initialize directly), or
     *        'modern' (skip the probe and enter modern mode directly with
     *        the preferred wire version — for servers known to speak
     *        2026-07-28, including ones that answer -32601 to BOTH
     *        server/discover and initialize; the client just starts
     *        sending stateless enveloped requests).
     * @param float|null $probeTimeout Seconds to wait for the probe
     *        response before concluding the server is a silent legacy
     *        server. Defaults to the session read timeout, or 10s when
     *        none is set. Ignored for modes 'legacy' and 'modern'.
     * @param string|null $preferredVersion Modern wire identifier to
     *        prefer: the first probe version for 'auto', the session's
     *        wire version for 'modern'. Defaults to the latest supported
     *        revision.
     * @return string The negotiated era: 'modern' or 'legacy'.
     * @throws \Mcp\Shared\McpError When a modern server rejects the probe
     *         with a recognized modern error that retrying cannot fix.
     * @throws RuntimeException When the server advertises no mutually
     *         supported modern version.
     */
    public function negotiate(string $mode = 'auto', ?float $probeTimeout = null, ?string $preferredVersion = null): string {
        if (!in_array($mode, ['auto', 'legacy', 'modern'], true)) {
            throw new InvalidArgumentException("Invalid protocol mode: {$mode} (expected 'auto', 'legacy', or 'modern')");
        }

        if ($mode === 'legacy') {
            $this->initialize();
            return 'legacy';
        }

        if ($mode === 'modern') {
            // Forced modern: no discover probe at all. Some 2026-07-28
            // servers (notably conformance mocks) answer -32601 to both
            // server/discover and initialize; the spec's stateless model
            // does not require any pre-flight, so the session simply
            // starts sending enveloped requests. A later -32022 carrying
            // an advertised supported list is handled per-request by
            // sendRequest()'s adopt-and-retry.
            $version = $preferredVersion ?? Version::LATEST_PROTOCOL_VERSION;
            $this->enterModernMode($version, null);
            $this->logger->info("Entered forced modern era without probing (wire version: {$version})");
            return 'modern';
        }

        $fallbackReason = null;
        $attempted = [];
        $version = $preferredVersion ?? Version::LATEST_PROTOCOL_VERSION;

        while (true) {
            $attempted[] = $version;
            try {
                $discovery = $this->probeDiscover($version, $probeTimeout);
                $this->enterModernMode($version, $discovery);
                $this->logger->info("Negotiated modern era (wire version: {$version})");
                return 'modern';
            } catch (HttpAuthenticationException | AuthorizationRedirectException $e) {
                // Not an era signal: both eras need the same credentials.
                throw $e;
            } catch (\Mcp\Shared\McpError $e) {
                $code = $e->error->code;
                if ($code === \Mcp\Shared\McpError::UNSUPPORTED_PROTOCOL_VERSION) {
                    $retry = $this->pickAdvertisedModernVersion($e->error->data, $attempted);
                    if ($retry !== null) {
                        $this->logger->info("Server rejected version {$version} (-32022); retrying with advertised version {$retry}");
                        $version = $retry;
                        continue;
                    }
                    // A modern server with no mutually supported version:
                    // the spec forbids falling back to initialize here.
                    throw new RuntimeException(
                        'Server speaks a modern MCP revision but supports none of this client\'s versions ('
                        . implode(', ', Version::MODERN_PROTOCOL_VERSIONS) . '): ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
                if ($code === \Mcp\Shared\McpError::HEADER_MISMATCH
                    || $code === \Mcp\Shared\McpError::MISSING_REQUIRED_CLIENT_CAPABILITY
                ) {
                    // Recognized modern errors: the server is modern, the
                    // probe failed for a non-era reason. Never fall back.
                    throw $e;
                }
                // Any other JSON-RPC error (commonly -32601 / -32602) is a
                // legacy server's implementation-defined rejection.
                $fallbackReason = "JSON-RPC error {$code}";
                break;
            } catch (InvalidArgumentException | \TypeError $e) {
                // The server answered the probe with something that does not
                // parse as a DiscoverResult — e.g. a legacy server that
                // returns a generic 200 result for unknown methods. Not a
                // modern server; fall back ("any other error" per spec).
                $fallbackReason = 'malformed discover result: ' . $e->getMessage();
                break;
            } catch (RuntimeException $e) {
                $status = (int) $e->getCode();
                $isTimeout = $e instanceof HttpRequestTimeoutException
                    || $e instanceof ReadTimeoutException;
                if (($status >= 400 && $status < 500) || $isTimeout) {
                    // HTTP 4xx without a recognized modern error body, or a
                    // silent server: legacy per the spec's fallback rules.
                    $fallbackReason = $isTimeout ? 'probe timeout' : "HTTP {$status} without a modern error body";
                    break;
                }
                // Transport-level failures (connection refused, TLS, 5xx)
                // are not era signals; surface them to the caller.
                throw $e;
            }
        }

        $this->logger->info("Modern probe fell back to legacy ({$fallbackReason}); initializing");
        $this->initialize();
        return 'legacy';
    }

    /**
     * Send the discover probe with a bounded wait. The session read
     * timeout is temporarily replaced so a legacy stdio server that never
     * answers an unknown pre-initialize request cannot hang connect().
     */
    private function probeDiscover(string $version, ?float $probeTimeout): DiscoverResult {
        $savedTimeout = $this->readTimeout;
        $this->readTimeout = $probeTimeout ?? $savedTimeout ?? self::DEFAULT_PROBE_TIMEOUT;
        try {
            return $this->discover($version);
        } finally {
            $this->readTimeout = $savedTimeout;
        }
    }

    /**
     * Pick the retry version after a -32022: the first identifier from
     * this client's modern list (in preference order) that the server
     * advertised in data.supported and that has not been attempted yet.
     *
     * @param string[] $attempted Wire identifiers already probed
     */
    private function pickAdvertisedModernVersion(mixed $errorData, array $attempted): ?string {
        if ($errorData instanceof \stdClass) {
            $errorData = (array) $errorData;
        }
        $supported = is_array($errorData) ? ($errorData['supported'] ?? null) : null;
        if (!is_array($supported)) {
            return null;
        }
        foreach (Version::MODERN_PROTOCOL_VERSIONS as $candidate) {
            if (in_array($candidate, $supported, true) && !in_array($candidate, $attempted, true)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Establish this session as modern-era (2026-07-28): no handshake, no
     * initialized notification — the session is ready immediately, and
     * every subsequent outgoing request and notification is stamped with
     * the SEP-2575 `_meta` envelope (see writeMessage()). The discover
     * result doubles as the initialization result so existing
     * capability-inspection code keeps working; on the forced-modern path
     * (no probe, $discovery null) a placeholder initialization result is
     * fabricated — server capabilities are simply unknown until queried.
     */
    private function enterModernMode(string $wireVersion, ?DiscoverResult $discovery): void {
        $this->modernWireVersion = $wireVersion;
        $this->negotiatedProtocolVersion = Version::canonicalizeVersion($wireVersion);
        $this->initResult = new InitializeResult(
            capabilities: $discovery?->capabilities ?? new \Mcp\Types\ServerCapabilities(),
            serverInfo: $discovery?->serverInfo ?? new Implementation(name: 'unknown', version: '0.0.0'),
            protocolVersion: $this->negotiatedProtocolVersion,
            instructions: $discovery?->instructions,
        );
        $this->initialized = true;
        $this->startMessageProcessing();
    }

    /**
     * Whether this session negotiated the modern (2026-07-28) per-request
     * era.
     */
    public function isModernMode(): bool {
        return $this->modernWireVersion !== null;
    }

    /**
     * The wire identifier carried in this modern session's per-request
     * envelopes (the dated revision or the RC-window draft alias), or
     * null for legacy sessions.
     */
    public function getModernWireVersion(): ?string {
        return $this->modernWireVersion;
    }

    /**
     * Mark this session as riding the Streamable HTTP transport (SEP-2243
     * header rules apply) or not (stdio — annotations are ignored and
     * tools/list results stay unfiltered). Called by Client::connect().
     */
    public function setHttpTransportMode(bool $httpTransportMode): void {
        $this->httpTransportMode = $httpTransportMode;
    }

    /**
     * Send a typed request, transparently adopting an advertised modern
     * wire version on -32022.
     *
     * In modern mode (auto-negotiated or forced) a server may reject any
     * request with UnsupportedProtocolVersionError (-32022) carrying a
     * `data.supported` list — most notably the FIRST real request of a
     * forced-modern session, which never probed. When the list contains a
     * mutually supported version, the session adopts it (every subsequent
     * envelope and mirrored MCP-Protocol-Version header switches) and the
     * request is retried exactly once; a second -32022 propagates. Errors
     * without a usable advertised list propagate unchanged, as does
     * everything on the legacy path.
     *
     * @template T of Result
     * @param class-string<T> $resultType
     * @return T
     */
    public function sendRequest(Request $request, string $resultType): Result {
        try {
            return parent::sendRequest($request, $resultType);
        } catch (\Mcp\Shared\McpError $e) {
            if ($this->modernWireVersion === null
                || $e->error->code !== \Mcp\Shared\McpError::UNSUPPORTED_PROTOCOL_VERSION
            ) {
                throw $e;
            }
            $retry = $this->pickAdvertisedModernVersion($e->error->data, [$this->modernWireVersion]);
            if ($retry === null) {
                throw $e;
            }
            $this->logger->info(
                "Server rejected wire version {$this->modernWireVersion} (-32022) on {$request->method}; "
                . "adopting advertised version {$retry} and retrying once"
            );
            $this->adoptModernWireVersion($retry, $request);
            return parent::sendRequest($request, $resultType);
        }
    }

    /**
     * Adopt an advertised modern wire version for the rest of the session
     * and refresh the stale envelope version already stamped onto the
     * request being retried (stampModernEnvelope() never overwrites an
     * existing field, and the transport mirrors the header from the
     * envelope — both must switch together).
     */
    private function adoptModernWireVersion(string $version, Request $request): void {
        $this->modernWireVersion = $version;
        $this->negotiatedProtocolVersion = Version::canonicalizeVersion($version);
        $meta = $request->params?->_meta;
        if ($meta !== null && $meta->getField(MetaKeys::PROTOCOL_VERSION) !== null) {
            $meta->setField(MetaKeys::PROTOCOL_VERSION, $version);
        }
    }

    /**
     * The client identity advertised in the legacy initialize handshake
     * and in every modern `_meta` envelope — one definition, so the two
     * surfaces can never disagree.
     */
    private function clientIdentity(): Implementation {
        return new Implementation(
            name: 'mcp-client',
            version: '1.0.0'
        );
    }

    /**
     * Get the InitializeResult after successful initialization.
     *
     * @throws RuntimeException If the session has not been initialized yet.
     *
     * @return InitializeResult The result of the initialization.
     */
    public function getInitializeResult(): InitializeResult {
        if ($this->initResult === null) {
            throw new RuntimeException('Session not yet initialized');
        }
        return $this->initResult;
    }

    /**
     * Get the negotiated protocol version.
     *
     * @throws RuntimeException If the session has not been initialized yet.
     *
     * @return string The negotiated protocol version.
     */
    public function getNegotiatedProtocolVersion(): string {
        if ($this->negotiatedProtocolVersion === null) {
            throw new RuntimeException('Session not yet initialized');
        }
        return $this->negotiatedProtocolVersion;
    }

    /**
     * Check if the negotiated protocol version supports a specific feature.
     *
     * @param string $feature The feature to check for.
     *
     * @return bool True if the feature is supported.
     */
    public function supportsFeature(string $feature): bool {
        if ($this->negotiatedProtocolVersion === null) {
            return false;
        }
        return Version::supportsFeature($this->negotiatedProtocolVersion, $feature);
    }

    /**
     * Register a handler for server-initiated `elicitation/create` requests.
     *
     * For a fresh session, this must be called before {@see initialize()} so
     * the elicitation capability (and optional `applyDefaults` flag, per
     * SEP-1034) is advertised in the initialization handshake.
     *
     * For a session rehydrated via {@see createRestored()}, registration is
     * allowed post-init: capabilities were already negotiated in the original
     * PHP request and cannot be re-advertised, but the server can still send
     * elicitation/create based on that negotiated state, so the dispatch path
     * must still be wired up.
     *
     * When $applyDefaults is true and the handler returns an `accept` result,
     * missing fields in the returned content are populated from the schema's
     * per-property `default` values before the response is sent back.
     *
     * When $supportsUrlMode is true the client advertises the `url` sub-capability
     * (MCP 2025-11-25) alongside `form`, so spec-compliant servers may send
     * URL-mode elicitation/create requests. The handler is responsible for
     * inspecting `$request->mode` and presenting the URL flow appropriately.
     * Default is false: only form-mode requests will be sent by compliant servers.
     *
     * @param callable(ElicitationCreateRequest): ElicitationCreateResult $handler
     */
    public function onElicit(callable $handler, bool $applyDefaults = false, bool $supportsUrlMode = false): void {
        if ($this->initialized && !$this->isRestored) {
            throw new RuntimeException('onElicit() must be called before initialize()');
        }
        if ($this->elicitationHandler !== null) {
            throw new RuntimeException('Elicitation handler already registered');
        }
        $this->elicitationHandler = $handler;
        $this->elicitationApplyDefaults = $applyDefaults;
        $this->elicitationSupportsUrlMode = $supportsUrlMode;

        $this->onRequest(function (RequestResponder $responder) use ($applyDefaults): void {
            $wrapper = $responder->getRequest();
            if (!($wrapper instanceof ServerRequest)) {
                return;
            }
            $inner = $wrapper->getRequest();
            if (!($inner instanceof ElicitationCreateRequest)) {
                return;
            }
            try {
                $result = ($this->elicitationHandler)($inner);
                if (!($result instanceof ElicitationCreateResult)) {
                    throw new RuntimeException(
                        'Elicitation handler must return an ElicitationCreateResult'
                    );
                }
                if ($applyDefaults) {
                    $result = $this->applyElicitationDefaults($result, $inner->requestedSchema);
                }
                $responder->sendResponse($result);
            } catch (\Throwable $e) {
                $this->logger->error('Elicitation handler failed: ' . $e->getMessage());
                $responder->sendResponse(new ErrorData(
                    code: -32603,
                    message: 'Elicitation handler failed: ' . $e->getMessage(),
                ));
            }
        });
    }

    /**
     * Register a handler for server-initiated `roots/list` requests.
     *
     * For a fresh session, this must be called before {@see initialize()} so
     * the `roots` capability is advertised in the initialization handshake.
     * Per the MCP spec a client that supports roots MUST declare the
     * capability; without this registration the SDK advertises no roots
     * capability and a spec-compliant server will never call `roots/list`.
     *
     * For a session rehydrated via {@see createRestored()}, registration is
     * allowed post-init: the capability was already negotiated in the original
     * PHP request and cannot be re-advertised, but the server can still send
     * `roots/list` based on that negotiated state, so the dispatch path must
     * still be wired up.
     *
     * When $listChanged is true (the default) the advertised capability is
     * `{ "listChanged": true }`, signalling that the client may emit
     * {@see sendRootsListChanged()} notifications when its root list changes.
     * Pass false to advertise `{ "listChanged": false }` for a static root set.
     *
     * @param callable(): ListRootsResult $handler
     */
    public function onListRoots(callable $handler, bool $listChanged = true): void {
        if ($this->initialized && !$this->isRestored) {
            throw new RuntimeException('onListRoots() must be called before initialize()');
        }
        if ($this->rootsHandler !== null) {
            throw new RuntimeException('Roots handler already registered');
        }
        $this->rootsHandler = $handler;
        $this->rootsListChanged = $listChanged;

        $this->onRequest(function (RequestResponder $responder) use ($handler): void {
            $wrapper = $responder->getRequest();
            if (!($wrapper instanceof ServerRequest)) {
                return;
            }
            if (!($wrapper->getRequest() instanceof ListRootsRequest)) {
                return;
            }
            if ($responder->hasResponded()) {
                // A handler registered earlier already replied; do nothing.
                return;
            }
            try {
                $result = $handler();
                if (!($result instanceof ListRootsResult)) {
                    throw new RuntimeException(
                        'Roots handler must return a ListRootsResult'
                    );
                }
                $responder->sendResponse($result);
            } catch (\Throwable $e) {
                $this->logger->error('Roots handler failed: ' . $e->getMessage());
                $responder->sendResponse(new ErrorData(
                    code: -32603,
                    message: 'Roots handler failed: ' . $e->getMessage(),
                ));
            }
        });
    }

    /**
     * Register a handler for server-initiated `sampling/createMessage`
     * requests, mirroring {@see onElicit()}.
     *
     * For a fresh session, this must be called before {@see initialize()}
     * so the `sampling` capability is advertised in the initialization
     * handshake (per the MCP spec, a client that supports sampling MUST
     * declare the capability). For a session rehydrated via
     * {@see createRestored()}, registration is allowed post-init.
     *
     * The handler also services `sampling/createMessage` entries in
     * SEP-2322 `input_required` results on the modern path (see the
     * multi-round-trip loop in executeModernCall()).
     *
     * @param callable(CreateMessageRequest): CreateMessageResult $handler
     */
    public function onSampling(callable $handler): void {
        if ($this->initialized && !$this->isRestored) {
            throw new RuntimeException('onSampling() must be called before initialize()');
        }
        if ($this->samplingHandler !== null) {
            throw new RuntimeException('Sampling handler already registered');
        }
        $this->samplingHandler = $handler;

        $this->onRequest(function (RequestResponder $responder) use ($handler): void {
            $wrapper = $responder->getRequest();
            if (!($wrapper instanceof ServerRequest)) {
                return;
            }
            if (!($wrapper->getRequest() instanceof CreateMessageRequest)) {
                return;
            }
            if ($responder->hasResponded()) {
                // A handler registered earlier already replied; do nothing.
                return;
            }
            try {
                $result = $handler($wrapper->getRequest());
                if (!($result instanceof CreateMessageResult)) {
                    throw new RuntimeException(
                        'Sampling handler must return a CreateMessageResult'
                    );
                }
                $responder->sendResponse($result);
            } catch (\Throwable $e) {
                $this->logger->error('Sampling handler failed: ' . $e->getMessage());
                $responder->sendResponse(new ErrorData(
                    code: -32603,
                    message: 'Sampling handler failed: ' . $e->getMessage(),
                ));
            }
        });
    }

    /**
     * Build the ClientCapabilities advertised at initialization time.
     *
     * Advertises elicitation support only when a handler is registered;
     * `form` is always advertised when registering a handler (today's SDK
     * always supports inline form responses), and `url` is added only when
     * the caller opted in via $supportsUrlMode. The `applyDefaults` flag is
     * included only when the caller opted in.
     *
     * Advertises the `roots` capability only when a roots/list handler is
     * registered via {@see onListRoots()}; `listChanged` reflects whether the
     * caller intends to emit notifications/roots/list_changed.
     *
     * Advertises the `sampling` capability only when a sampling handler is
     * registered via {@see onSampling()}.
     */
    private function buildClientCapabilities(): ClientCapabilities {
        $elicitation = null;
        if ($this->elicitationHandler !== null) {
            $elicitation = new ElicitationCapability(
                form: true,
                url: $this->elicitationSupportsUrlMode ? true : null,
                applyDefaults: $this->elicitationApplyDefaults ? true : null,
            );
        }
        $roots = null;
        if ($this->rootsHandler !== null) {
            $roots = new ClientRootsCapability(listChanged: $this->rootsListChanged);
        }
        $sampling = null;
        if ($this->samplingHandler !== null) {
            $sampling = new SamplingCapability();
        }
        return new ClientCapabilities(roots: $roots, sampling: $sampling, elicitation: $elicitation);
    }

    /**
     * Fill missing `content` fields from per-property `default` values in
     * the request's requestedSchema. Mirrors the reference TypeScript SDK
     * behavior for SEP-1034: only runs on `accept`, never overwrites, and
     * swallows errors.
     *
     * @param array<string, mixed>|null $requestedSchema
     */
    private function applyElicitationDefaults(
        ElicitationCreateResult $result,
        ?array $requestedSchema
    ): ElicitationCreateResult {
        if ($result->action !== 'accept' || $result->content === null) {
            return $result;
        }
        if (
            $requestedSchema === null
            || !isset($requestedSchema['properties'])
            || !is_array($requestedSchema['properties'])
        ) {
            return $result;
        }

        $content = $result->content;
        try {
            foreach ($requestedSchema['properties'] as $key => $propSchema) {
                if (!is_string($key) || !is_array($propSchema)) {
                    continue;
                }
                if (array_key_exists($key, $content)) {
                    continue;
                }
                if (!array_key_exists('default', $propSchema)) {
                    continue;
                }
                $content[$key] = $propSchema['default'];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed applying elicitation defaults: ' . $e->getMessage());
            return $result;
        }

        return new ElicitationCreateResult(
            action: $result->action,
            content: $content,
            _meta: $result->_meta,
        );
    }

    /**
     * Send a PingRequest to the server.
     *
     * @throws RuntimeException If the session is not initialized or if sending the request fails.
     *
     * @return EmptyResult The result of the ping request.
     */
    public function sendPing(): EmptyResult {
        $this->ensureInitialized();
        $pingRequest = new PingRequest();
        $this->logger->info('Sending PingRequest to server');
        return $this->sendRequest($pingRequest, EmptyResult::class);
    }

    /**
     * Send a progress notification to the server.
     *
     * @param ProgressToken $progressToken The progress token.
     * @param float $progress The progress value.
     * @param float|null $total The total value.
     * @param string|null $message The message to send.
     *
     * @throws RuntimeException If the session is not initialized or if sending the notification fails.
     *
     * @return void
     */
    public function sendProgressNotification(
        ProgressToken $progressToken,
        float $progress,
        ?float $total = null,
        ?string $message = null
    ): void {
        $this->ensureInitialized();
        
        $params = [
            'progressToken' => $progressToken,
            'progress' => $progress
        ];
        
        if ($total !== null) {
            $params['total'] = $total;
        }
        
        // Only include message field for servers that support it
        if ($message !== null && $this->supportsFeature('progress_message')) {
            $params['message'] = $message;
        }
        
        $notificationParams = new \Mcp\Types\ProgressNotificationParams(
            progressToken: $progressToken,
            progress: $progress,
            total: $total,
            message: $this->supportsFeature('progress_message') ? $message : null
        );
        
        $notification = new \Mcp\Types\ProgressNotification($notificationParams);
        $this->sendNotification($notification);
    }

    /**
     * Set the logging level on the server.
     *
     * @param LoggingLevel $level The desired logging level.
     *
     * @throws RuntimeException If the session is not initialized or if sending the request fails.
     *
     * @return EmptyResult The result of the setLoggingLevel request.
     */
    public function setLoggingLevel(LoggingLevel $level): EmptyResult {
        $this->ensureInitialized();
        $setLevelRequest = new \Mcp\Types\SetLevelRequest($level);
        $this->logger->info('Setting logging level on server');
        return $this->sendRequest($setLevelRequest, EmptyResult::class);
    }

    /**
     * List available resources on the server.
     *
     * @throws RuntimeException If the session is not initialized or if sending the request fails.
     *
     * @return ListResourcesResult The list of resources.
     */
    public function listResources(): ListResourcesResult {
        $this->ensureInitialized();
        $listResourcesRequest = new \Mcp\Types\ListResourcesRequest();
        $this->logger->info('Requesting list of resources from server');
        return $this->sendRequest($listResourcesRequest, ListResourcesResult::class);
    }

    /**
     * Read a specific resource from the server.
     *
     * @param string $uri The URI of the resource to read.
     *
     * @throws RuntimeException If the session is not initialized or if sending the request fails.
     *
     * @return ReadResourceResult The content of the resource.
     */
    public function readResource(string $uri): ReadResourceResult {
        $this->ensureInitialized();
        $readResourceRequest = new \Mcp\Types\ReadResourceRequest($uri);
        $this->logger->info("Requesting to read resource: $uri");
        /** @var ReadResourceResult */
        return $this->executeModernCall($readResourceRequest, ReadResourceResult::class);
    }

    /**
     * Subscribe to updates for a specific resource.
     *
     * @param string $uri The URI of the resource to subscribe to.
     *
     * @throws RuntimeException If the session is not initialized or if sending the request fails.
     *
     * @return EmptyResult The result of the subscribe request.
     */
    public function subscribeResource(string $uri): EmptyResult {
        $this->ensureInitialized();
        $subscribeRequest = new \Mcp\Types\SubscribeRequest($uri);
        $this->logger->info("Subscribing to resource: $uri");
        return $this->sendRequest($subscribeRequest, EmptyResult::class);
    }

    /**
     * Unsubscribe from updates for a specific resource.
     *
     * @param string $uri The URI of the resource to unsubscribe from.
     *
     * @throws RuntimeException If the session is not initialized or if sending the request fails.
     *
     * @return EmptyResult The result of the unsubscribe request.
     */
    public function unsubscribeResource(string $uri): EmptyResult {
        $this->ensureInitialized();
        $unsubscribeRequest = new \Mcp\Types\UnsubscribeRequest($uri);
        $this->logger->info("Unsubscribing from resource: $uri");
        return $this->sendRequest($unsubscribeRequest, EmptyResult::class);
    }

    /**
     * Call a tool on the server with optional arguments.
     *
     * @param string     $name      The name of the tool to call.
     * @param array<string, mixed>|null $arguments Optional arguments for the tool.
     *
     * @throws RuntimeException If the session is not initialized or if sending the request fails.
     * @throws InvalidArgumentException If the tool was excluded from the latest
     *         tools/list for invalid x-mcp-header annotations (SEP-2243):
     *         rejected tools are never called on the wire.
     *
     * @return CallToolResult The result of the tool call.
     */
    public function callTool(string $name, ?array $arguments = null): CallToolResult {
        $this->ensureInitialized();
        if (isset($this->rejectedToolErrors[$name])) {
            throw new InvalidArgumentException(
                "Tool '{$name}' was excluded from tools/list because its x-mcp-header annotations are invalid ("
                . implode('; ', $this->rejectedToolErrors[$name])
                . '); SEP-2243 forbids calling it'
            );
        }
        $callToolRequest = new \Mcp\Types\CallToolRequest($name, $arguments);
        $this->logger->info("Calling tool: $name with arguments: " . json_encode($arguments));
        /** @var CallToolResult */
        return $this->executeModernCall(
            $callToolRequest,
            CallToolResult::class,
            $this->buildParamHeaderHints($name, $arguments)
        );
    }

    /**
     * List available prompts on the server.
     *
     * @throws RuntimeException If the session is not initialized or if sending the request fails.
     *
     * @return ListPromptsResult The list of prompts.
     */
    public function listPrompts(): ListPromptsResult {
        $this->ensureInitialized();
        $listPromptsRequest = new \Mcp\Types\ListPromptsRequest();
        $this->logger->info('Requesting list of prompts from server');
        return $this->sendRequest($listPromptsRequest, ListPromptsResult::class);
    }

    /**
     * Get a specific prompt from the server.
     *
     * @param string     $name      The name of the prompt to retrieve.
     * @param array<string, string>|null $arguments Optional arguments for the prompt.
     *
     * @throws RuntimeException If the session is not initialized or if sending the request fails.
     * @throws InvalidArgumentException If any argument values are not strings.
     *
     * @return GetPromptResult The retrieved prompt.
     */
    public function getPrompt(string $name, ?array $arguments = null): GetPromptResult {
        $this->ensureInitialized();

        // Create PromptArguments object if arguments provided
        $promptArgs = null;
        if ($arguments !== null) {
            try {
                $promptArgs = new \Mcp\Types\PromptArguments($arguments);
            } catch (\InvalidArgumentException $e) {
                $this->logger->error('Invalid prompt arguments', [
                    'name' => $name,
                    'arguments' => $arguments,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }

        // Create the request parameters
        $params = new \Mcp\Types\GetPromptRequestParams($name, $promptArgs);
        $getPromptRequest = new \Mcp\Types\GetPromptRequest($params);

        $this->logger->info("Requesting prompt: $name with arguments: " . json_encode($arguments));
        /** @var GetPromptResult */
        return $this->executeModernCall($getPromptRequest, GetPromptResult::class);
    }

    /**
     * Complete an action based on a resource or prompt reference.
     *
     * @param ResourceReference|PromptReference $ref       The reference to complete.
     * @param array<string, mixed> $argument  The arguments for completion (must contain 'name' and 'value').
     *
     * @throws InvalidArgumentException If 'name' is empty or 'value' is missing.
     * @throws RuntimeException If the session is not initialized or if sending the request fails.
     *
     * @return CompleteResult The result of the completion.
     */
    public function complete(
        ResourceReference|PromptReference $ref,
        array $argument
    ): CompleteResult {
        $this->ensureInitialized();

        // Construct the CompletionArgument object
        if (empty($argument['name']) || !isset($argument['value'])) {
            throw new \InvalidArgumentException('CompleteRequest argument must have "name" and "value"');
        }
        /** @var array{name: non-empty-string, value: string} $argument */
        $completionArg = new \Mcp\Types\CompletionArgument($argument['name'], $argument['value']);

        // Construct the params object
        $params = new \Mcp\Types\CompleteRequestParams($completionArg, $ref);

        // Construct the request
        $completeRequest = new \Mcp\Types\CompleteRequest($params);

        $this->logger->info("Completing reference: " . json_encode($ref) . " with argument: " . json_encode($argument));
        return $this->sendRequest($completeRequest, CompleteResult::class);
    }

    /**
     * List available tools on the server.
     *
     * @throws RuntimeException If the session is not initialized or if sending the request fails.
     *
     * @return ListToolsResult The list of tools.
     */
    public function listTools(): ListToolsResult {
        $this->ensureInitialized();
        $listToolsRequest = new \Mcp\Types\ListToolsRequest();
        $this->logger->info('Requesting list of tools from server');
        $result = $this->sendRequest($listToolsRequest, ListToolsResult::class);
        if ($this->modernWireVersion !== null && $this->httpTransportMode) {
            $result = $this->applyToolHeaderAnnotationPolicy($result);
        }
        return $result;
    }

    /**
     * Enforce the SEP-2243 x-mcp-header rules on a tools/list result
     * (modern HTTP path only — stdio MUST ignore annotations and keeps
     * results unfiltered).
     *
     * Tools whose inputSchema carries invalid annotations (non-token name,
     * non-primitive type, case-insensitive duplicates, …) are excluded
     * from the returned list — the spec's MUST for HTTP clients — logged,
     * and cached as rejected so callTool() refuses them. One invalid tool
     * never affects valid siblings. Valid annotation maps are cached per
     * tool name for Mcp-Param-* mirroring; both caches are refreshed on
     * every listTools() call.
     */
    private function applyToolHeaderAnnotationPolicy(ListToolsResult $result): ListToolsResult {
        $this->toolHeaderAnnotations = [];
        $this->rejectedToolErrors = [];

        $validTools = [];
        $rejectedAny = false;
        foreach ($result->tools as $tool) {
            $schema = json_decode((string) json_encode($tool->inputSchema), true);
            $collected = McpHeaders::collectAnnotations(is_array($schema) ? $schema : null);
            if ($collected['errors'] !== []) {
                $rejectedAny = true;
                $this->rejectedToolErrors[$tool->name] = $collected['errors'];
                $this->logger->warning(
                    "Excluding tool '{$tool->name}' from tools/list: invalid x-mcp-header annotations: "
                    . implode('; ', $collected['errors'])
                );
                continue;
            }
            $this->toolHeaderAnnotations[$tool->name] = $collected['map'];
            $validTools[] = $tool;
        }

        if (!$rejectedAny) {
            return $result;
        }

        $filtered = new ListToolsResult($validTools, $result->nextCursor, $result->_meta);
        $filtered->resultType = $result->resultType;
        $filtered->ttlMs = $result->ttlMs;
        $filtered->cacheScope = $result->cacheScope;
        return $filtered;
    }

    /**
     * Build the Mcp-Param-* header hints for a tools/call (SEP-2243):
     * for each argument whose inputSchema property carries a (cached,
     * valid) x-mcp-header annotation, mirror the value via
     * {@see McpHeaders::encodeParamValue()}. Null or absent arguments are
     * omitted entirely (spec: omit the header); an empty string mirrors as
     * a present, empty header. Returns null when nothing applies — legacy
     * sessions, stdio, unannotated tools, or no mirrorable arguments.
     *
     * @param array<string, mixed>|null $arguments
     * @return array<string, string>|null
     */
    private function buildParamHeaderHints(string $name, ?array $arguments): ?array {
        if ($this->modernWireVersion === null || !$this->httpTransportMode) {
            return null;
        }
        $map = $this->toolHeaderAnnotations[$name] ?? null;
        if ($map === null || $map === [] || $arguments === null) {
            return null;
        }

        $headers = [];
        foreach ($map as $path => $info) {
            [$found, $value] = McpHeaders::argumentAtPath($arguments, $info['segments']);
            if (!$found || $value === null) {
                continue;
            }
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException(
                    "Argument '$path' of tool '$name' is not a finite number and cannot be "
                    . 'mirrored into an Mcp-Param-* header'
                );
            }
            if ((is_int($value) || is_float($value))
                && ($info['type'] === 'integer' || is_int($value))
                && !McpHeaders::isSafeIntegerValue($value)
            ) {
                // SEP-2243: designated integer values MUST be within
                // ±(2^53 - 1) — large JSON integers can decode as floats,
                // so integral floats are held to the same bound. Fail
                // before any wire traffic rather than ship a header the
                // server must reject.
                throw new InvalidArgumentException(
                    "Argument '$path' of tool '$name' exceeds the JavaScript-safe integer range "
                    . 'required for x-mcp-header designated parameters'
                );
            }
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                // Annotated properties are string/integer/boolean by
                // validation (floats still pass through because JSON
                // decoding can surface an integer-typed argument as an
                // integral float — the server compares numerically); any
                // other argument shape is a caller error the server will
                // reject — never guess at a header encoding for it.
                continue;
            }
            $headers[McpHeaders::paramHeaderName($info['annotation'])] = McpHeaders::encodeParamValue($value);
        }

        return $headers === [] ? null : $headers;
    }

    /**
     * Execute a request with SEP-2322 multi-round-trip support (revision
     * 2026-07-28) — the send path shared by callTool(), getPrompt(), and
     * readResource(), the only three methods allowed to answer with an
     * `input_required` result.
     *
     * On the legacy path this is a plain typed sendRequest. On the modern
     * path the raw result is inspected BEFORE typed parsing: a result
     * whose `resultType` is `input_required` terminates the original
     * JSON-RPC request — each `inputRequests` entry is serviced through
     * the locally registered handlers, and the SAME method is re-sent with
     * the SAME original params plus `inputResponses` (keyed identically)
     * and the verbatim `requestState` (echoed ONLY when the result carried
     * one), under a fresh request id. An absent `resultType` is treated as
     * 'complete' (legacy peer) — never retried. The loop is capped at
     * {@see MAX_MRTR_ROUNDS} rounds.
     *
     * All loop state is method-local, so parallel or interleaved requests
     * can never pick up another call's inputResponses or requestState; the
     * retry params are built on a CLONE of the original params, leaving
     * the caller's request untouched. The SEP-2575 envelope and SEP-2243
     * headers apply to every retry automatically via the normal
     * writeMessage() stamping.
     *
     * @template T of Result
     * @param class-string<T> $resultType
     * @param array<string, string>|null $headerHints Mcp-Param-* mirrors to
     *        attach to each outgoing attempt of this request (tools/call
     *        on the modern HTTP path only)
     * @return T
     */
    private function executeModernCall(Request $request, string $resultType, ?array $headerHints = null): Result {
        if ($this->modernWireVersion === null) {
            return $this->sendRequest($request, $resultType);
        }

        $rounds = 0;
        $retryFields = null;
        while (true) {
            $effective = $retryFields === null
                ? $request
                : $this->buildMrtrRetryRequest($request, $retryFields);

            if ($headerHints !== null) {
                $this->pendingHeaderHints = ['method' => $request->method, 'headers' => $headerHints];
            }
            try {
                /** @var RawResult $raw */
                $raw = $this->sendRequest($effective, RawResult::class);
            } finally {
                $this->pendingHeaderHints = null;
            }

            $data = $raw->data;
            if (($data['resultType'] ?? null) !== InputRequiredResult::RESULT_TYPE_INPUT_REQUIRED) {
                // Anything else — including an absent resultType — is a
                // complete result; hand the raw data to the typed parser.
                /** @var T */
                return $resultType::fromResponseData($data);
            }

            if (++$rounds > self::MAX_MRTR_ROUNDS) {
                throw new RuntimeException(
                    "SEP-2322 multi-round-trip for {$request->method} exceeded "
                    . self::MAX_MRTR_ROUNDS . ' input_required rounds'
                );
            }

            $retryFields = [];
            $inputRequests = $data['inputRequests'] ?? null;
            if ($inputRequests instanceof \stdClass) {
                $inputRequests = (array) $inputRequests;
            }
            if (is_array($inputRequests) && $inputRequests !== []) {
                $retryFields['inputResponses'] = $this->serviceInputRequests($inputRequests);
            }
            if (array_key_exists('requestState', $data)) {
                // Echo VERBATIM — and only when the result carried one.
                $retryFields['requestState'] = $data['requestState'];
            }
            if ($retryFields === []) {
                throw new RuntimeException(
                    "input_required result for {$request->method} carried neither inputRequests nor requestState"
                );
            }
        }
    }

    /**
     * Build the retry request for an MRTR round: the same method, a clone
     * of the original params (so the caller's request object stays
     * untouched), plus the `inputResponses` / `requestState` fields riding
     * as dynamic params fields.
     *
     * @param array<string, mixed> $fields
     */
    private function buildMrtrRetryRequest(Request $request, array $fields): Request {
        $params = $request->params !== null ? clone $request->params : new RequestParams();
        foreach ($fields as $key => $value) {
            $params->$key = $value;
        }
        return new class($request->method, $params) extends Request {
        };
    }

    /**
     * Service the `inputRequests` of an input_required result through the
     * locally registered handlers, producing the `inputResponses` map
     * keyed identically. Only `elicitation/create`, `sampling/createMessage`,
     * and `roots/list` may appear (SEP-2322); anything else fails the call.
     *
     * @param array<string, mixed> $inputRequests
     * @return array<string, array<string, mixed>>
     */
    private function serviceInputRequests(array $inputRequests): array {
        $responses = [];
        foreach ($inputRequests as $key => $entry) {
            if ($entry instanceof \stdClass) {
                $entry = (array) $entry;
            }
            if (!is_array($entry) || !isset($entry['method']) || !is_string($entry['method'])) {
                throw new RuntimeException(
                    "Malformed inputRequests entry '{$key}' in input_required result (missing method)"
                );
            }
            $params = $entry['params'] ?? [];
            if ($params instanceof \stdClass) {
                $params = (array) $params;
            }
            if (!is_array($params)) {
                $params = [];
            }

            $responses[$key] = match ($entry['method']) {
                'elicitation/create' => $this->serviceElicitationInputRequest($params),
                'sampling/createMessage' => $this->serviceSamplingInputRequest($params),
                'roots/list' => $this->serviceRootsInputRequest(),
                default => throw new RuntimeException(
                    "Unsupported inputRequests method '{$entry['method']}' (key '{$key}'): only "
                    . 'elicitation/create, sampling/createMessage, and roots/list can be serviced'
                ),
            };
        }
        return $responses;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function serviceElicitationInputRequest(array $params): array {
        if ($this->elicitationHandler === null) {
            throw new RuntimeException(
                'input_required requested elicitation/create but no elicitation handler is registered (see onElicit())'
            );
        }
        $wrapper = ServerRequest::fromMethodAndParams('elicitation/create', $params);
        /** @var ElicitationCreateRequest $inner */
        $inner = $wrapper->getRequest();
        $result = ($this->elicitationHandler)($inner);
        if (!($result instanceof ElicitationCreateResult)) {
            throw new RuntimeException('Elicitation handler must return an ElicitationCreateResult');
        }
        if ($this->elicitationApplyDefaults) {
            $result = $this->applyElicitationDefaults($result, $inner->requestedSchema);
        }
        return $this->resultToArray($result);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function serviceSamplingInputRequest(array $params): array {
        if ($this->samplingHandler === null) {
            throw new RuntimeException(
                'input_required requested sampling/createMessage but no sampling handler is registered (see onSampling())'
            );
        }
        $wrapper = ServerRequest::fromMethodAndParams('sampling/createMessage', $params);
        /** @var CreateMessageRequest $inner */
        $inner = $wrapper->getRequest();
        $result = ($this->samplingHandler)($inner);
        if (!($result instanceof CreateMessageResult)) {
            throw new RuntimeException('Sampling handler must return a CreateMessageResult');
        }
        return $this->resultToArray($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceRootsInputRequest(): array {
        if ($this->rootsHandler === null) {
            throw new RuntimeException(
                'input_required requested roots/list but no roots handler is registered (see onListRoots())'
            );
        }
        $result = ($this->rootsHandler)();
        if (!($result instanceof ListRootsResult)) {
            throw new RuntimeException('Roots handler must return a ListRootsResult');
        }
        return $this->resultToArray($result);
    }

    /**
     * Serialize a typed result into the plain array shape an
     * inputResponses entry carries on the wire.
     *
     * @return array<string, mixed>
     */
    private function resultToArray(Result $result): array {
        $decoded = json_decode((string) json_encode($result), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get a task's current status (experimental).
     *
     * @param string $taskId The task ID to query
     * @return \Mcp\Types\TaskGetResult The task status
     */
    public function getTask(string $taskId): \Mcp\Types\TaskGetResult {
        $this->ensureInitialized();
        $request = new \Mcp\Types\TaskGetRequest($taskId);
        $this->logger->info("Getting task: $taskId");
        return $this->sendRequest($request, \Mcp\Types\TaskGetResult::class);
    }

    /**
     * Get a completed task's result (experimental).
     *
     * @param string $taskId The task ID to query
     * @return CallToolResult The task result
     */
    public function getTaskResult(string $taskId): CallToolResult {
        $this->ensureInitialized();
        $request = new \Mcp\Types\TaskResultRequest($taskId);
        $this->logger->info("Getting task result: $taskId");
        return $this->sendRequest($request, CallToolResult::class);
    }

    /**
     * List all tasks (experimental).
     *
     * @return \Mcp\Types\TaskListResult The list of tasks
     */
    public function listTasks(): \Mcp\Types\TaskListResult {
        $this->ensureInitialized();
        $request = new \Mcp\Types\TaskListRequest();
        $this->logger->info('Listing tasks');
        return $this->sendRequest($request, \Mcp\Types\TaskListResult::class);
    }

    /**
     * Cancel a task (experimental).
     *
     * @param string $taskId The task ID to cancel
     * @return \Mcp\Types\TaskGetResult The updated task
     */
    public function cancelTask(string $taskId): \Mcp\Types\TaskGetResult {
        $this->ensureInitialized();
        $request = new \Mcp\Types\TaskCancelRequest($taskId);
        $this->logger->info("Cancelling task: $taskId");
        return $this->sendRequest($request, \Mcp\Types\TaskGetResult::class);
    }

    /**
     * Notify the server that the list of roots has changed.
     *
     * @throws RuntimeException If the session is not initialized or if sending the notification fails.
     *
     * @return void
     */
    public function sendRootsListChanged(): void {
        $this->ensureInitialized();
        $rootsListChangedNotification = new \Mcp\Types\RootsListChangedNotification();
        $this->logger->info('Sending RootsListChangedNotification to server');
        $this->sendNotification($rootsListChangedNotification);
    }

    /**
     * Receive the next incoming message.
     *
     * @return JsonRpcMessage|\Exception|null The received message, an exception, or null if no message is available.
     */
    public function receiveMessage(): JsonRpcMessage|\Exception|null {
        $msg = $this->readStream->receive();
        return $msg; // The transport already returns JsonRpcMessage or Exception or null
    }
    
    protected function getReadTimeout(): ?float {
        return $this->readTimeout;
    }

    /**
     * Ensure that the session has been initialized.
     *
     * @throws RuntimeException If the session is not initialized.
     *
     * @return void
     */
    private function ensureInitialized(): void {
        if (!$this->initialized) {
            throw new RuntimeException('Session not initialized. Call initialize() first.');
        }
    }

    /**
     * Start any additional message processing mechanisms if necessary.
     *
     * @return void
     */
    protected function startMessageProcessing(): void {
        // Implement any background processing if required
        // Currently, messages are processed in the receive loop
    }

    /**
     * Stop any additional message processing mechanisms if necessary.
     *
     * @return void
     */
    protected function stopMessageProcessing(): void {
        // Implement any cleanup for background processing if required
    }

    /**
     * Write a JsonRpcMessage to the write stream.
     *
     * @param JsonRpcMessage $message The JSON-RPC message to send.
     *
     * @throws RuntimeException If writing to the stream fails.
     *
     * @return void
     */
    protected function writeMessage(JsonRpcMessage $message): void {
        // Modern era (SEP-2575): every request and notification carries the
        // per-request _meta envelope — protocol version, client info, and
        // client capabilities are all required on every message; servers
        // MUST NOT infer them from prior requests.
        if ($this->modernWireVersion !== null) {
            $this->stampModernEnvelope($message);

            // SEP-2243: attach the in-flight call's Mcp-Param-* header
            // hints to the matching outgoing request only. The method
            // check keeps interleaved traffic (responses to
            // server-initiated requests serviced mid-call, notifications)
            // from picking up another call's hints.
            $inner = $message->message;
            if ($this->pendingHeaderHints !== null
                && $inner instanceof \Mcp\Types\JSONRPCRequest
                && $inner->method === $this->pendingHeaderHints['method']
            ) {
                $message->httpHeaderHints = $this->pendingHeaderHints['headers'];
            }
        }
        $this->logger->debug('Sending message to server: ' . json_encode($message, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $this->writeStream->send($message);
    }

    /**
     * Stamp the SEP-2575 `_meta` envelope onto an outgoing request or
     * notification. Fields already present (e.g. on a hand-built discover
     * request) are left untouched; trace-context and other _meta entries
     * are preserved.
     */
    private function stampModernEnvelope(JsonRpcMessage $message): void {
        $inner = $message->message;
        if (!($inner instanceof \Mcp\Types\JSONRPCRequest || $inner instanceof \Mcp\Types\JSONRPCNotification)) {
            return;
        }

        if ($inner->params === null) {
            $inner->params = $inner instanceof \Mcp\Types\JSONRPCRequest
                ? new RequestParams()
                : new \Mcp\Types\NotificationParams();
        }
        if ($inner->params->_meta === null) {
            $inner->params->_meta = new Meta();
        }

        $meta = $inner->params->_meta;
        $fields = $meta->getExtraFields();
        if (!array_key_exists(MetaKeys::PROTOCOL_VERSION, $fields)) {
            $meta->setField(MetaKeys::PROTOCOL_VERSION, $this->modernWireVersion);
        }
        if (!array_key_exists(MetaKeys::CLIENT_INFO, $fields)) {
            $meta->setField(MetaKeys::CLIENT_INFO, $this->clientIdentity());
        }
        if (!array_key_exists(MetaKeys::CLIENT_CAPABILITIES, $fields)) {
            $meta->setField(MetaKeys::CLIENT_CAPABILITIES, $this->buildClientCapabilities());
        }
    }

    /**
     * Wait for a specific response to a request.
     *
     * @param int    $requestIdValue The ID of the request to wait for.
     * @param string $resultType     The expected result type class name.
     * @param \Mcp\Types\Result|null $futureResult Reference to store the received result.
     *
     * @throws RuntimeException If the wait times out or if an unexpected response is received.
     *
     * @return \Mcp\Types\Result The received result.
     */
    protected function waitForResponse(int $requestIdValue, string $resultType, ?\Mcp\Types\Result &$futureResult): \Mcp\Types\Result {
        $timeout = $this->getReadTimeout();
        $startTime = microtime(true);

        $this->logger->info("Waiting for response to request ID: $requestIdValue");

        while ($futureResult === null) {
            if ($timeout !== null && (microtime(true) - $startTime) >= $timeout) {
                $this->logger->error("Timed out waiting for response to request ID: $requestIdValue");
                throw new ReadTimeoutException("Timed out waiting for response to request ID: $requestIdValue");
            }

            $message = $this->readNextMessage();
            $this->handleIncomingMessage($message);
        }

        $this->logger->info("Received response for request ID: $requestIdValue");

        return $futureResult;
    }

    /**
     * Read the next message from the read stream.
     *
     * Blocks until a valid JsonRpcMessage is received or an exception occurs.
     * When a read timeout is configured, an idle stream aborts the read —
     * without this, the timeout in waitForResponse() could never fire
     * against a peer that sends nothing at all (its check only runs
     * between messages), and the negotiate() probe could not detect a
     * silent legacy server.
     *
     * @throws RuntimeException If an invalid message type is received, or
     *         the configured read timeout elapses with no message.
     *
     * @return JsonRpcMessage The received JSON-RPC message.
     */
    protected function readNextMessage(): JsonRpcMessage {
        $timeout = $this->getReadTimeout();
        $startTime = microtime(true);
        while (true) {
            $msg = $this->readStream->receive();

            if ($msg === null) {
                if ($timeout !== null && (microtime(true) - $startTime) >= $timeout) {
                    throw new ReadTimeoutException(
                        "Timed out waiting for response: no message received within {$timeout}s"
                    );
                }
                // No message available, wait briefly to prevent busy waiting
                usleep(10000);
                continue;
            }

            if ($msg instanceof \Exception) {
                $this->logger->error("Exception received from readStream: {$msg->getMessage()}");
                throw $msg;
            }

            if (!$msg instanceof JsonRpcMessage) {
                $this->logger->error("Invalid message type received from readStream");
                throw new RuntimeException("Invalid message type received from readStream");
            }

            $this->logger->debug('Received message from server: ' . json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return $msg;
        }
    }
}