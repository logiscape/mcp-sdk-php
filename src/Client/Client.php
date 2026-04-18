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
 * Filename: Client/Client.php
 */

declare(strict_types=1);

namespace Mcp\Client;

use Mcp\Client\Auth\Exception\AuthorizationRedirectException;
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Transport\StdioServerParameters;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Client\Transport\StreamableHttpTransport;
use Mcp\Client\Transport\HttpConfiguration;
use Mcp\Client\Transport\HttpSessionManager;
use Mcp\Client\ClientSession;
use Mcp\Shared\MemoryStream;
use Mcp\Types\InitializeResult;
use Mcp\Types\JsonRpcMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use InvalidArgumentException;

/**
 * Class Client
 *
 * Main client class for MCP communication.
 *
 * The client can connect to a server via STDIO or HTTP, initialize a session,
 * and start a receive loop to process incoming messages.
 */
class Client {
    /** @var ClientSession|null */
    private ?ClientSession $session = null;

    /** @var StdioTransport|StreamableHttpTransport|null */
    private $transport = null;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /** @var callable|null Pending elicitation handler to register before initialize(). */
    private $pendingElicitationHandler = null;

    /** @var bool Whether the pending elicitation handler opted into applyDefaults. */
    private bool $pendingElicitationApplyDefaults = false;

    /**
     * Client constructor.
     *
     * @param LoggerInterface|null $logger PSR-3 compliant logger.
     */
    public function __construct(?LoggerInterface $logger = null) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Register a handler for server-initiated `elicitation/create` requests.
     *
     * Must be called before {@see connect()} so the elicitation capability
     * (and optional `applyDefaults` flag, per SEP-1034) is advertised in the
     * initialization handshake. The handler is applied to the session that
     * connect() creates.
     *
     * @param callable(\Mcp\Types\ElicitationCreateRequest): \Mcp\Types\ElicitationCreateResult $handler
     */
    public function onElicit(callable $handler, bool $applyDefaults = false): void {
        if ($this->session !== null) {
            throw new RuntimeException('onElicit() must be called before connect()');
        }
        $this->pendingElicitationHandler = $handler;
        $this->pendingElicitationApplyDefaults = $applyDefaults;
    }

    /**
     * Connect to an MCP server using either STDIO or HTTP/HTTPS.
     *
     * If commandOrUrl is an HTTP(S) URL, it uses the StreamableHttpTransport.
     * Otherwise, it assumes it's a command and uses STDIO transport.
     *
     * @param string                       $commandOrUrl The command to execute or the HTTP(S) URL.
     * @param array<int|string, string>    $args         Arguments for the command (if using STDIO transport)
     *                                                   or HTTP headers (if using HTTP transport).
     * @param array<string, mixed>|null    $env          Environment variables for the command (if using STDIO transport)
     *                                                   or HTTP configuration options (if using HTTP transport).
     * @param float|null                   $readTimeout  Timeout for reading messages.
     *
     * @throws InvalidArgumentException If the command or URL is invalid.
     * @throws RuntimeException         If the connection fails.
     *
     * @return ClientSession The initialized client session.
     */
    public function connect(
        string $commandOrUrl,
        array $args = [],
        ?array $env = null,
        ?float $readTimeout = null
    ): ClientSession {
        $urlParts = parse_url($commandOrUrl);

        try {
            if (isset($urlParts['scheme']) && in_array(strtolower($urlParts['scheme']), ['http', 'https'], true)) {
                // Use HTTP transport for HTTP(S) URLs
                $this->logger->info("Connecting to HTTP endpoint: {$commandOrUrl}");
                
                // Process HTTP-specific options
                $headers = $args; // For HTTP, args are used as headers
                $httpOptions = $env ?? []; // For HTTP, env is used for HTTP options
                
                // Extract OAuth configuration if provided
                $oauthConfig = null;
                if (isset($httpOptions['oauth']) && $httpOptions['oauth'] instanceof OAuthConfiguration) {
                    $oauthConfig = $httpOptions['oauth'];
                }

                // Create HTTP configuration
                $httpConfig = new HttpConfiguration(
                    endpoint: $commandOrUrl,
                    headers: $headers,
                    connectionTimeout: $httpOptions['connectionTimeout'] ?? 30.0,
                    readTimeout: $httpOptions['readTimeout'] ?? 60.0,
                    sseIdleTimeout: $httpOptions['sseIdleTimeout'] ?? 300.0,
                    enableSse: $httpOptions['enableSse'] ?? true,
                    maxRetries: $httpOptions['maxRetries'] ?? 3,
                    retryDelay: $httpOptions['retryDelay'] ?? 0.5,
                    verifyTls: $httpOptions['verifyTls'] ?? true,
                    caFile: $httpOptions['caFile'] ?? null,
                    curlOptions: $httpOptions['curlOptions'] ?? [],
                    oauthConfig: $oauthConfig,
                    sseDefaultRetryDelay: $httpOptions['sseDefaultRetryDelay'] ?? 1.0,
                    sseReconnectBudget: $httpOptions['sseReconnectBudget'] ?? 60.0
                );
                
                // Create the HTTP transport
                $transport = new StreamableHttpTransport(
                    config: $httpConfig,
                    autoSse: $httpOptions['autoSse'] ?? true,
                    logger: $this->logger
                );
                
                $this->transport = $transport;
            } else {
                // Use STDIO transport for commands
                $this->logger->info("Starting process: {$commandOrUrl}");
                $params = new StdioServerParameters($commandOrUrl, $args, $env);
                $transport = new StdioTransport($params, $this->logger);
                $this->transport = $transport;
            }

            // Establish connection and retrieve read/write streams
            [$readStream, $writeStream] = $transport->connect();

            // Initialize the client session with the obtained streams
            $this->session = new ClientSession(
                readStream: $readStream,
                writeStream: $writeStream,
                readTimeout: $readTimeout,
                logger: $this->logger
            );

            // Wire the HTTP transport to dispatch server-initiated requests
            // and notifications through the session synchronously, so that a
            // server interleaving sampling/createMessage or
            // elicitation/create on a POST SSE response stream can be
            // serviced before the server's own response arrives. Must be
            // set BEFORE initialize() so any handshake-time interleaving is
            // also handled.
            if ($this->transport instanceof StreamableHttpTransport) {
                $session = $this->session;
                $this->transport->setMessageDispatcher(
                    static function (JsonRpcMessage $msg) use ($session): void {
                        $session->dispatchIncomingMessage($msg);
                    }
                );
            }

            // Apply any elicitation handler registered via onElicit() before
            // connect(). Must happen before initialize() so the elicitation
            // capability is advertised in the handshake.
            if ($this->pendingElicitationHandler !== null) {
                $this->session->onElicit(
                    $this->pendingElicitationHandler,
                    $this->pendingElicitationApplyDefaults
                );
            }

            // Initialize the session (e.g., perform handshake if necessary)
            $this->session->initialize();
            $this->logger->info('Session initialized successfully');

            // For HTTP transports, feed the negotiated protocol version back to
            // the session manager so it's included in subsequent request headers
            if ($this->transport instanceof StreamableHttpTransport) {
                $this->transport->getSessionManager()->setProtocolVersion(
                    $this->session->getNegotiatedProtocolVersion()
                );

                // Open the standalone GET SSE stream described by the MCP
                // Streamable HTTP spec. Must happen after setProtocolVersion
                // so the GET carries the negotiated MCP-Protocol-Version
                // header alongside Mcp-Session-Id. The transport handles
                // the 405 case gracefully, so servers that decline the
                // stream do not cause connect() to fail.
                $this->transport->startStandaloneSseStream();
            }

            return $this->session;
        } catch (AuthorizationRedirectException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Connection failed: {$e->getMessage()}");
            throw new RuntimeException("Connection failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Close the client connection gracefully.
     *
     * @return void
     */
    public function close(): void {
        if ($this->session) {
            $this->session->close();
            $this->logger->info('Session closed successfully');
            $this->session = null;
        }
        if ($this->transport) {
            $this->transport->close();
            $this->logger->info('Transport closed successfully');
            $this->transport = null;
        }
    }

    /**
     * Resume an existing HTTP session without performing initialization handshake.
     *
     * Reconstructs the transport with restored session state and creates a
     * ClientSession that is immediately ready for operations.
     *
     * @param string                    $url                      The HTTP(S) URL of the MCP server
     * @param array<string, mixed>      $sessionManagerState      Session manager state from toArray()
     * @param array<string, mixed>      $initResultData           InitializeResult data (serialized)
     * @param string                    $negotiatedProtocolVersion The negotiated protocol version
     * @param int                       $nextRequestId            The next request ID counter value
     * @param array<string, string>     $headers                  HTTP headers
     * @param array<string, mixed>      $httpOptions              HTTP configuration options
     * @return ClientSession The restored client session ready for operations
     */
    public function resumeHttpSession(
        string $url,
        array $sessionManagerState,
        array $initResultData,
        string $negotiatedProtocolVersion,
        int $nextRequestId,
        array $headers = [],
        array $httpOptions = []
    ): ClientSession {
        try {
            // Restore session manager from persisted state
            $sessionManager = HttpSessionManager::fromArray($sessionManagerState, $this->logger);

            // Extract OAuth configuration if provided
            $oauthConfig = null;
            if (isset($httpOptions['oauth']) && $httpOptions['oauth'] instanceof OAuthConfiguration) {
                $oauthConfig = $httpOptions['oauth'];
            }

            // Create HTTP configuration
            $httpConfig = new HttpConfiguration(
                endpoint: $url,
                headers: $headers,
                connectionTimeout: $httpOptions['connectionTimeout'] ?? 30.0,
                readTimeout: $httpOptions['readTimeout'] ?? 60.0,
                sseIdleTimeout: $httpOptions['sseIdleTimeout'] ?? 300.0,
                enableSse: $httpOptions['enableSse'] ?? true,
                maxRetries: $httpOptions['maxRetries'] ?? 3,
                retryDelay: $httpOptions['retryDelay'] ?? 0.5,
                verifyTls: $httpOptions['verifyTls'] ?? true,
                caFile: $httpOptions['caFile'] ?? null,
                curlOptions: $httpOptions['curlOptions'] ?? [],
                oauthConfig: $oauthConfig,
                sseDefaultRetryDelay: $httpOptions['sseDefaultRetryDelay'] ?? 1.0,
                sseReconnectBudget: $httpOptions['sseReconnectBudget'] ?? 60.0
            );

            // Set the negotiated protocol version on the session manager
            $sessionManager->setProtocolVersion($negotiatedProtocolVersion);

            // Create transport with restored session manager
            $transport = new StreamableHttpTransport(
                config: $httpConfig,
                autoSse: $httpOptions['autoSse'] ?? true,
                logger: $this->logger,
                sessionManager: $sessionManager
            );
            $this->transport = $transport;

            // Connect transport to get read/write streams
            [$readStream, $writeStream] = $transport->connect();

            // Restore InitializeResult from serialized data
            $initResult = InitializeResult::fromResponseData($initResultData);

            // Create restored session (no handshake)
            $this->session = ClientSession::createRestored(
                readStream: $readStream,
                writeStream: $writeStream,
                initResult: $initResult,
                negotiatedProtocolVersion: $negotiatedProtocolVersion,
                nextRequestId: $nextRequestId,
                readTimeout: $httpOptions['readTimeout'] ?? null,
                logger: $this->logger
            );

            // Wire dispatch path so interleaved server-initiated messages on
            // subsequent POST SSE responses are serviced synchronously.
            $session = $this->session;
            $transport->setMessageDispatcher(
                static function (JsonRpcMessage $msg) use ($session): void {
                    $session->dispatchIncomingMessage($msg);
                }
            );

            // Apply any elicitation handler registered via onElicit() before
            // resumeHttpSession(). The original session advertised the
            // elicitation capability at its handshake, so the server may still
            // send elicitation/create on the resumed connection; without this,
            // those requests would arrive with no registered handler.
            if ($this->pendingElicitationHandler !== null) {
                $this->session->onElicit(
                    $this->pendingElicitationHandler,
                    $this->pendingElicitationApplyDefaults
                );
            }

            // Re-open the standalone GET SSE stream for the resumed session.
            // The persisted standaloneLastEventId (restored by
            // HttpSessionManager::fromArray) is sent as Last-Event-ID so the
            // server can replay anything that would have been delivered to
            // the previous process after it detached.
            $transport->startStandaloneSseStream();

            $this->logger->info('HTTP session resumed successfully', [
                'sessionId' => $sessionManager->getSessionId(),
            ]);

            return $this->session;
        } catch (\Exception $e) {
            $this->logger->error("Session resume failed: {$e->getMessage()}");
            throw new RuntimeException("Session resume failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Detach from the transport without terminating the server-side session.
     *
     * Unlike close(), this preserves the server-side session for later resumption.
     * Only works with HTTP transports.
     *
     * @return void
     */
    public function detach(): void {
        if ($this->session) {
            $this->session->close();
            $this->logger->info('Session detached');
            $this->session = null;
        }
        if ($this->transport instanceof StreamableHttpTransport) {
            $this->transport->detach();
            $this->logger->info('Transport detached (server session preserved)');
            $this->transport = null;
        } elseif ($this->transport) {
            // Non-HTTP transports fall back to close
            $this->transport->close();
            $this->logger->info('Transport closed (non-HTTP)');
            $this->transport = null;
        }
    }

    /**
     * Get the current transport instance.
     *
     * @return StdioTransport|StreamableHttpTransport|null
     */
    public function getTransport() {
        return $this->transport;
    }

    /**
     * Get the current session instance.
     *
     * @return ClientSession|null
     */
    public function getSession(): ?ClientSession {
        return $this->session;
    }
}
