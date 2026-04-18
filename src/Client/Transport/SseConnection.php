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
 * Filename: Client/Transport/SseConnection.php
 */

 declare(strict_types=1);

 namespace Mcp\Client\Transport;
 
 use Mcp\Types\JsonRpcMessage;
 use Mcp\Types\JSONRPCRequest;
 use Mcp\Types\JSONRPCNotification;
 use Mcp\Types\JSONRPCResponse;
 use Mcp\Types\JSONRPCError;
 use Mcp\Types\RequestId;
use Mcp\Types\JsonRpcErrorObject;
use Mcp\Types\NotificationParams;
use Mcp\Types\RequestParams;
use Mcp\Types\Meta;
 use Psr\Log\LoggerInterface;
 use Psr\Log\NullLogger;
 use RuntimeException;
 
 /**
  * Manages a Server-Sent Events (SSE) connection to an MCP server.
  * 
  * This class handles establishing the connection, parsing SSE events,
  * and converting them into JsonRpcMessage objects.
  */
 class SseConnection {
     /**
      * Maximum buffer size for incomplete SSE events
      */
     private const MAX_BUFFER_SIZE = 1048576; // 1MB
     
     /**
      * The HTTP configuration
      */
     private HttpConfiguration $config;
     
     /**
      * The session manager
      */
     private HttpSessionManager $sessionManager;
     
     /**
      * The logger instance
      */
     private LoggerInterface $logger;
     
     /**
      * The cURL handle for the SSE connection
      */
     private ?\CurlHandle $curlHandle = null;
     
     /**
      * Buffer for incomplete SSE events
      */
     private string $buffer = '';
     
     /**
      * Queue of parsed JsonRpcMessage objects
      *
      * @var list<JsonRpcMessage>
      */
     private array $messageQueue = [];
     
     /**
      * The last event ID received
      */
     private ?string $lastEventId = null;
     
     /**
      * Whether the connection is active
      */
     private bool $active = false;
     
     /**
      * Whether we're using a background process
      */
     private bool $usingBackground = false;
     
     /**
      * The cURL multi handle for non-blocking operation
      */
     private ?\CurlMultiHandle $multiHandle = null;

     /**
      * PID of the background process if used
      */
     private ?int $backgroundPid = null;
     
     /**
      * File handle for IPC with background process
      *
      * @var resource|null
      */
     private $ipcHandle = null;
     
     /**
      * Path to the IPC file
      */
     private ?string $ipcPath = null;

     /**
      * Extra HTTP headers to include on the SSE GET request (e.g.
      * Mcp-Session-Id, MCP-Protocol-Version). Merged *after* the default
      * header bag so callers can override Accept or other defaults if
      * absolutely necessary.
      *
      * @var array<string, string>
      */
     private array $extraHeaders = [];

     /**
      * Optional synchronous dispatcher for parsed JsonRpcMessages.
      *
      * When set, messages arriving on this connection are handed directly
      * to the dispatcher instead of being enqueued in {@see $messageQueue}.
      * This is how the standalone GET SSE stream delivers server-initiated
      * requests/notifications into the session without waiting for the
      * next readStream drain — critical for interleaved request/response
      * patterns (e.g. a server-initiated elicitation/create fired while a
      * client POST is in flight).
      *
      * Only applied in foreground mode (dispatcher lives in the parent
      * process). The forked child in background mode continues to queue
      * and serialize over IPC; the parent's pumpOnce() reads the IPC and
      * dispatches from there.
      *
      * @var \Closure|null Closure(JsonRpcMessage): void
      */
     private ?\Closure $messageDispatcher = null;

     /**
      * Cursor-update strategy for incoming event ids.
      *
      * - 'post'  — update the POST-stream cursor on the shared session
      *             manager (legacy behaviour for pre-init probe).
      * - 'standalone' — update the standalone-GET cursor only. Used by the
      *             post-init standalone stream so the POST cursor does not
      *             collide with the standalone one; the two streams have
      *             independent event-id namespaces per the MCP spec.
      */
     private string $cursorScope = 'post';

     /**
      * Whether the server returned a non-SSE response (e.g. 405 Method Not
      * Allowed). When true, the stream is permanently unavailable — callers
      * should stop attempting to use it and fall back to per-request SSE
      * only.
      */
     private bool $serverDeclinedStream = false;

     /**
      * HTTP status captured from the GET response headers. Used for 405 /
      * 2xx discrimination in foreground mode.
      */
     private ?int $responseStatus = null;

     /**
      * Content-Type captured from the GET response headers. Only an
      * `text/event-stream` response is treated as a live SSE stream.
      */
     private ?string $responseContentType = null;

     /**
      * Creates a new SSE connection manager.
      *
      * @param HttpConfiguration $config Configuration for the HTTP connection
      * @param HttpSessionManager $sessionManager Session manager for tracking state
      * @param LoggerInterface|null $logger PSR-3 compatible logger
      * @param array<string, string> $extraHeaders Additional request headers
      *        (e.g. Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID) to
      *        send on the GET. Required for the post-init standalone stream.
      * @param \Closure|null $messageDispatcher When provided, messages parsed
      *        in the parent process are dispatched immediately via this
      *        callable instead of being enqueued. See {@see $messageDispatcher}.
      * @param string $cursorScope 'post' (default, legacy) or 'standalone'.
      */
     public function __construct(
         HttpConfiguration $config,
         HttpSessionManager $sessionManager,
         ?LoggerInterface $logger = null,
         array $extraHeaders = [],
         ?\Closure $messageDispatcher = null,
         string $cursorScope = 'post'
     ) {
         $this->config = $config;
         $this->sessionManager = $sessionManager;
         $this->logger = $logger ?? new NullLogger();
         $this->extraHeaders = $extraHeaders;
         $this->messageDispatcher = $messageDispatcher;
         $this->cursorScope = $cursorScope === 'standalone' ? 'standalone' : 'post';
         // Seed the connection-local cursor from any restored session state
         // so the first SSE GET can resume via Last-Event-ID. After this
         // point the cursor is tracked locally and is not re-read from the
         // shared session manager.
         $this->lastEventId = $this->cursorScope === 'standalone'
             ? $sessionManager->getStandaloneLastEventId()
             : $sessionManager->getLastEventId();
     }
     
     /**
      * Starts the SSE connection.
      * 
      * This method establishes a connection to the MCP server for
      * receiving Server-Sent Events.
      * 
      * @throws RuntimeException If the connection fails
      */
     public function start(): void {
         $this->logger->info("Starting SSE connection to {$this->config->getEndpoint()}");
         
         // Check if we can use a background process
         if (function_exists('pcntl_fork') && function_exists('posix_setsid')) {
             $this->startBackgroundConnection();
         } else {
             $this->startForegroundConnection();
         }
     }
     
     /**
      * Starts an SSE connection in a background process using pcntl_fork.
      * 
      * This allows the connection to run independently and process events
      * without blocking the main process.
      * 
      * @throws RuntimeException If forking fails
      */
     private function startBackgroundConnection(): void {
         // Create a temporary file for IPC
         $this->ipcPath = sys_get_temp_dir() . '/mcp_sse_' . uniqid() . '.fifo';
         
         // Create a named pipe for IPC
         if (!posix_mkfifo($this->ipcPath, 0600)) {
             throw new RuntimeException("Failed to create named pipe at {$this->ipcPath}");
         }
         
         // Fork a child process
         $pid = pcntl_fork();
         
         if ($pid === -1) {
             // Fork failed
             @unlink($this->ipcPath);
             throw new RuntimeException('Failed to fork process for SSE connection');
         }
         
         if ($pid === 0) {
             // Child process
             try {
                 // Detach from parent
                 posix_setsid();
                 
                 // Open the IPC pipe for writing
                 $pipe = fopen($this->ipcPath, 'w');
                 if ($pipe === false) {
                     exit(1);
                 }
                 
                 // Establish SSE connection
                 $this->establishSseConnection($pipe);
                 
                 // Close pipe
                 fclose($pipe);
             } catch (\Exception $e) {
                 // Log error and exit
                 error_log("SSE connection error: {$e->getMessage()}");
                 exit(1);
             }
             
             // Clean up and exit
             @unlink($this->ipcPath);
             exit(0);
         } else {
             // Parent process
             $this->logger->info("Started SSE connection in background process (PID: {$pid})");
             $this->backgroundPid = $pid;
             $this->usingBackground = true;
             $this->active = true;
             
             // Open the IPC pipe for reading (non-blocking)
             $this->ipcHandle = fopen($this->ipcPath, 'r');
             if ($this->ipcHandle === false) {
                 $this->killBackgroundProcess();
                 throw new RuntimeException('Failed to open IPC pipe for reading');
             }
             
             stream_set_blocking($this->ipcHandle, false);
         }
     }
     
     /**
      * Starts an SSE connection in the foreground (same process).
      * 
      * This is used when background processing is not available.
      * It sets up a non-blocking connection that can be polled.
      * 
      * @throws RuntimeException If the connection fails
      */
     private function startForegroundConnection(): void {
         $this->logger->info("Starting SSE connection in foreground (no background process available)");

         $this->curlHandle = $this->createCurlHandle();

         // Configure cURL for non-blocking operation
         curl_setopt($this->curlHandle, CURLOPT_WRITEFUNCTION, function($ch, $data) {
             // If we've already seen a non-SSE response, short-write so curl
             // aborts quickly instead of buffering a body we do not care
             // about (e.g. an HTML 405 body).
             if ($this->serverDeclinedStream) {
                 return 0;
             }
             // If status/content-type headers have been seen and declined
             // the stream, mark it and stop consuming.
             if ($this->responseStatus !== null && !$this->responseStatusIndicatesLiveStream()) {
                 $this->serverDeclinedStream = true;
                 return 0;
             }
             return $this->processSseData($data);
         });

         // Start the connection (but don't wait for it to complete)
         $mh = curl_multi_init();
         curl_multi_add_handle($mh, $this->curlHandle);

         // Execute once to start the request
         curl_multi_exec($mh, $running);

         // Don't remove the handle - we'll use curl_multi_exec in receiveMessage
         $this->multiHandle = $mh;
         $this->active = true;
     }

     /**
      * Was the response status + content-type consistent with a live SSE
      * stream? Anything else (most commonly 405 Method Not Allowed for the
      * standalone GET) signals the server does not offer the stream and the
      * caller should stop trying to use it.
      */
     private function responseStatusIndicatesLiveStream(): bool
     {
         if ($this->responseStatus === null) {
             // Headers not yet parsed; give the transfer the benefit of the
             // doubt and let the WRITEFUNCTION decide on the next chunk.
             return true;
         }
         if ($this->responseStatus < 200 || $this->responseStatus >= 300) {
             return false;
         }
         if ($this->responseContentType === null) {
             return true; // 2xx with no Content-Type — treat as live, parser will decide
         }
         return stripos($this->responseContentType, 'text/event-stream') !== false;
     }

     /**
      * Drive one tick of I/O on this connection non-blockingly.
      *
      * Called by the transport from inside blocking send paths (POST
      * WRITEFUNCTION / XFERINFOFUNCTION) so server-initiated messages on
      * the standalone stream are dispatched concurrently with the POST —
      * the scenario the MCP Streamable HTTP spec exercises when it says
      * the server MAY send server→client requests on a GET SSE stream
      * "unrelated to any concurrently-running JSON-RPC request from the
      * client".
      *
      * In foreground mode this runs one curl_multi_exec tick; any parsed
      * messages are dispatched synchronously inside that call via the
      * WRITEFUNCTION chain (see {@see processMessageData()}).
      *
      * In background mode this drains any complete messages already
      * serialized to IPC by the forked child and dispatches them.
      */
     public function pumpOnce(): void
     {
         if (!$this->active) {
             return;
         }

         if ($this->serverDeclinedStream) {
             $this->active = false;
             return;
         }

         if ($this->usingBackground) {
             // Drain all available messages from IPC in one go so we do not
             // leave server-initiated requests sitting in the pipe while the
             // outer POST is still blocked. receiveMessageFromBackground()
             // already returns null when no complete message is ready.
             while (($msg = $this->receiveMessageFromBackground()) !== null) {
                 if ($this->messageDispatcher !== null) {
                     try {
                         ($this->messageDispatcher)($msg);
                         continue;
                     } catch (\Throwable $e) {
                         $this->logger->error(
                             'Standalone SSE dispatch failed, falling back to queue: '
                             . $e->getMessage()
                         );
                     }
                 }
                 $this->messageQueue[] = $msg;
             }
             return;
         }

         if ($this->multiHandle !== null && $this->curlHandle !== null) {
             curl_multi_exec($this->multiHandle, $running);
             // If the transfer completed with a non-SSE status, surface it
             // so the transport can tear the stream down.
             $info = curl_multi_info_read($this->multiHandle);
             if ($info !== false && $info['handle'] === $this->curlHandle) {
                 if (!$this->responseStatusIndicatesLiveStream()) {
                     $this->serverDeclinedStream = true;
                     $this->active = false;
                 }
             }
         }
     }

     /**
      * Did the server decline to open a standalone stream? True when the
      * GET response was something other than 2xx + text/event-stream (most
      * commonly a 405 Method Not Allowed).
      */
     public function wasDeclinedByServer(): bool
     {
         return $this->serverDeclinedStream;
     }

     /**
      * Return the captured HTTP response status (null until headers arrive).
      */
     public function getResponseStatus(): ?int
     {
         return $this->responseStatus;
     }
     
     /**
      * Establishes an SSE connection and processes events.
      * 
      * This method is called in the background process and runs
      * continuously, forwarding parsed messages to the parent process.
      * 
      * @param resource $pipe The IPC pipe for sending messages to the parent
      */
     private function establishSseConnection($pipe): void {
         $ch = $this->createCurlHandle();
         
         // Configure cURL for blocking operation in the child process
         curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use ($pipe) {
             $processed = $this->processSseData($data, true);
             
             // Send any complete messages back to the parent process
             while (!empty($this->messageQueue)) {
                 $message = array_shift($this->messageQueue);
                 // Serialize and send through the pipe
                 $serialized = serialize($message);
                 $header = pack('N', strlen($serialized));
                 fwrite($pipe, $header . $serialized);
                 fflush($pipe);
             }
             
             return $processed;
         });
         
         // Execute the request (this will block until completion or error)
         curl_exec($ch);
         
         // Clean up
         curl_close($ch);
     }
     
     /**
      * Creates and configures a cURL handle for the SSE connection.
      *
      * @return \CurlHandle The configured cURL handle
      * @throws RuntimeException If cURL initialization fails
      */
     private function createCurlHandle(): \CurlHandle {
         $endpoint = $this->config->getEndpoint();
         $headers = $this->prepareRequestHeaders();

         $ch = curl_init($endpoint);
         if ($ch === false) {
             throw new RuntimeException('Failed to initialize cURL for SSE connection');
         }

         // Capture response status and content-type so the caller can tell a
         // live SSE stream from a 405 Method Not Allowed / 4xx error. The MCP
         // spec specifically allows the server to return 405 to decline a
         // standalone GET stream.
         curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) {
             $length = strlen($header);
             // First "header" line is the status line (e.g. "HTTP/1.1 200 OK").
             if (preg_match('#^HTTP/[0-9.]+\s+(\d{3})#', $header, $m) === 1) {
                 $this->responseStatus = (int) $m[1];
                 return $length;
             }
             $parts = explode(':', $header, 2);
             if (count($parts) === 2) {
                 $name = strtolower(trim($parts[0]));
                 $value = trim($parts[1]);
                 if ($name === 'content-type') {
                     $this->responseContentType = $value;
                 }
             }
             return $length;
         });

         // Configure the cURL handle for SSE
         curl_setopt($ch, CURLOPT_HTTPGET, true);
         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
         curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
         curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
         curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, (int)($this->config->getConnectionTimeout() * 1000));
         curl_setopt($ch, CURLOPT_TIMEOUT_MS, (int)($this->config->getSseIdleTimeout() * 1000));
         
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
         
         return $ch;
     }
     
     /**
      * Prepares HTTP headers for the SSE request.
      *
      * @return list<string> HTTP headers in cURL format
      */
     private function prepareRequestHeaders(): array {
         // Start with headers from configuration
         $headers = $this->config->getHeaders();

         // Add SSE-specific headers
         $headers['Accept'] = 'text/event-stream';

         // Add session headers if available
         if ($this->sessionManager->isInitialized()) {
             $headers = array_merge($headers, $this->sessionManager->getRequestHeaders());
         }

         // Add Last-Event-ID if available for reconnection
         if ($this->lastEventId !== null) {
             $headers['Last-Event-ID'] = $this->lastEventId;
         }

         // Merge caller-supplied extras last so they can set Mcp-Session-Id
         // or MCP-Protocol-Version explicitly at stream-open time (useful when
         // the shared session manager is not yet marked initialized but the
         // caller knows the ids from a different context).
         $headers = array_merge($headers, $this->extraHeaders);

         // Convert to cURL format (array of "Name: Value" strings)
         $curlHeaders = [];
         foreach ($headers as $name => $value) {
             $curlHeaders[] = "{$name}: {$value}";
         }

         return $curlHeaders;
     }
     
     /**
      * Processes raw SSE data and parses it into JsonRpcMessage objects.
      * 
      * @param string $data The raw SSE data chunk
      * @param bool $isBackground Whether this is running in a background process
      * @return int The number of bytes processed (for cURL write callback)
      */
     private function processSseData(string $data, bool $isBackground = false): int {
         // Add the new data to our buffer
         $this->buffer .= $data;
         
         // Limit buffer size to prevent memory issues
         if (strlen($this->buffer) > self::MAX_BUFFER_SIZE) {
             $this->logger->warning("SSE buffer exceeded maximum size, truncating");
             $this->buffer = substr($this->buffer, -self::MAX_BUFFER_SIZE);
         }
         
         // Parse any complete events from the buffer
         $this->parseEvents($isBackground);
         
         // Return length for cURL write callback
         return strlen($data);
     }
     
     /**
      * Parses SSE events from the buffer.
      * 
      * @param bool $isBackground Whether this is running in a background process
      */
     private function parseEvents(bool $isBackground = false): void {
         // Split the buffer by double newlines (end of an event)
         $parts = preg_split('/\r\n\r\n|\n\n|\r\r/', $this->buffer);
         
         // If we have more than one part, we have at least one complete event
         if (count($parts) > 1) {
             // The last part might be incomplete, keep it in the buffer
             $this->buffer = array_pop($parts);
             
             // Process each complete event
             foreach ($parts as $eventData) {
                 $this->processEvent($eventData, $isBackground);
             }
         }
     }
     
     /**
      * Processes a single SSE event.
      * 
      * @param string $eventData The raw event data
      * @param bool $isBackground Whether this is running in a background process
      */
     private function processEvent(string $eventData, bool $isBackground = false): void {
         $lines = preg_split('/\r\n|\n|\r/', $eventData);
         $event = [
             'id' => null,
             'event' => 'message', // Default event type
             'data' => '',
             'retry' => null
         ];
         
         foreach ($lines as $line) {
             // Skip comments and empty lines
             if (empty($line) || $line[0] === ':') {
                 continue;
             }
             
             // Parse field: value
             if (strpos($line, ':') !== false) {
                 list($field, $value) = explode(':', $line, 2);
                 // Remove leading space from value if present
                 if (isset($value[0]) && $value[0] === ' ') {
                     $value = substr($value, 1);
                 }
                 
                 switch ($field) {
                     case 'id':
                         $event['id'] = $value;
                         break;
                     case 'event':
                         $event['event'] = $value;
                         break;
                     case 'data':
                         $event['data'] .= $value . "\n";
                         break;
                     case 'retry':
                         $event['retry'] = (int)$value;
                         break;
                 }
             }
         }
         
         // Remove trailing newline from data
         if (!empty($event['data'])) {
             $event['data'] = rtrim($event['data'], "\n");
         }
         
         // Update last event ID if present. The cursor is tracked both in
         // this connection's local state (for the next SSE reconnect GET
         // header) and in the shared session manager (so toArray() /
         // fromArray() session restore can resume from the latest position).
         // This does NOT leak onto unrelated POSTs or DELETEs because
         // HttpSessionManager::getRequestHeaders() deliberately excludes
         // Last-Event-ID from the session-wide header bag.
         //
         // Note: in background mode this update happens in the forked child
         // process and does not propagate back to the parent's session
         // manager. That is a pre-existing limitation of the background
         // transport path — only foreground mode is fully compatible with
         // toArray()-based session restore.
         if ($event['id'] !== null) {
             $this->lastEventId = $event['id'];
             if ($this->cursorScope === 'standalone') {
                 $this->sessionManager->updateStandaloneLastEventId($event['id']);
             } else {
                 $this->sessionManager->updateLastEventId($event['id']);
             }
         }
         
         // Only process data events
         if ($event['event'] === 'message' && !empty($event['data'])) {
             $this->processMessageData($event['data'], $isBackground);
         }
     }
     
     /**
      * Processes JSON-RPC message data from an SSE event.
      * 
      * @param string $data The raw message data
      * @param bool $isBackground Whether this is running in a background process
      */
     private function processMessageData(string $data, bool $isBackground = false): void {
         try {
             // Decode the JSON data
             $jsonData = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

             // Convert to JsonRpcMessage
             $message = $this->convertToJsonRpcMessage($jsonData);

             if ($message !== null) {
                 // In foreground mode with a dispatcher, deliver directly to
                 // the session so server-initiated requests can be serviced
                 // synchronously (e.g. elicitation/create arriving on the
                 // standalone stream while a POST is in flight). Without
                 // dispatch, the message would only be picked up by the
                 // session's read loop on the next idle tick, which may
                 // never come if the read loop is blocked on a POST that is
                 // itself waiting for the server to consume this request's
                 // response.
                 //
                 // In background mode the dispatcher is unset in the forked
                 // child (it was a closure captured by value in the parent),
                 // so we always queue-then-serialize here; the parent's
                 // pumpOnce() drains IPC and dispatches from the parent
                 // process side.
                 if (!$isBackground && $this->messageDispatcher !== null) {
                     try {
                         ($this->messageDispatcher)($message);
                         return;
                     } catch (\Throwable $e) {
                         $this->logger->error(
                             'SSE synchronous dispatch failed, falling back to queue: '
                             . $e->getMessage()
                         );
                     }
                 }

                 // Add to the message queue
                 $this->messageQueue[] = $message;

                 if (!$isBackground) {
                     $this->logger->debug('Parsed JsonRpcMessage from SSE event');
                 }
             }
         } catch (\JsonException $e) {
             $this->logger->warning("Failed to parse SSE event data as JSON: {$e->getMessage()}");
         } catch (\Exception $e) {
             $this->logger->warning("Error processing SSE message: {$e->getMessage()}");
         }
     }
     
     /**
      * Converts raw JSON data to a JsonRpcMessage object.
      *
      * @param array<string|int, mixed> $data The decoded JSON data
      * @return JsonRpcMessage|null The converted message or null on error
      */
     private function convertToJsonRpcMessage(array $data): ?JsonRpcMessage {
         return $this->convertSingleMessage($data);
     }
     
     /**
      * Converts a single JSON-RPC message.
      *
      * @param array<string, mixed> $data The decoded JSON data
      * @return JsonRpcMessage|null The converted message or null on error
      */
     private function convertSingleMessage(array $data): ?JsonRpcMessage {
         // Validate JSON-RPC version
         if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
             $this->logger->warning('Invalid JSON-RPC version in SSE message');
             return null;
         }
         
         if (isset($data['method'])) {
             // Request or notification
            if (isset($data['id'])) {
                // Request
                $params = null;
                if (isset($data['params']) && is_array($data['params'])) {
                    $params = $this->parseRequestParams($data['params']);
                } elseif (isset($data['params'])) {
                    $params = $data['params'];
                }

                return new JsonRpcMessage(new JSONRPCRequest(
                    jsonrpc: '2.0',
                    id: new RequestId($data['id']),
                    method: $data['method'],
                    params: $params
                ));
            } else {
                // Notification
                $params = null;
                if (isset($data['params']) && is_array($data['params'])) {
                    $params = $this->parseNotificationParams($data['params']);
                } elseif (isset($data['params'])) {
                    $params = $data['params'];
                }

                return new JsonRpcMessage(new JSONRPCNotification(
                    jsonrpc: '2.0',
                    method: $data['method'],
                    params: $params
                ));
            }
         } elseif (isset($data['id'])) {
             // Response or error
             if (isset($data['error'])) {
                 // Error
                 $error = $data['error'];
                 return new JsonRpcMessage(new JSONRPCError(
                     jsonrpc: '2.0',
                     id: new RequestId($data['id']),
                     error: new JsonRpcErrorObject(
                         code: $error['code'],
                         message: $error['message'],
                         data: $error['data'] ?? null
                     )
                 ));
             } else {
                 // Response
                 return new JsonRpcMessage(new JSONRPCResponse(
                     jsonrpc: '2.0',
                     id: new RequestId($data['id']),
                     result: $data['result'] ?? null
                 ));
             }
         }
         
        $this->logger->warning('Invalid JSON-RPC message format in SSE event');
        return null;
    }

    /**
     * Parses notification parameters into a NotificationParams object.
     *
     * @param array<string, mixed> $params
     */
    private function parseNotificationParams(array $params): NotificationParams {
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
     * Parses request parameters into a RequestParams object.
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
     * Helper to create a Meta object from an associative array.
     *
     * @param array<string, mixed> $metaArr
     */
    private function metaFromArray(array $metaArr): Meta {
        $meta = new Meta();
        foreach ($metaArr as $key => $value) {
            $meta->$key = $value;
        }
        return $meta;
    }
     
     /**
      * Receives the next available message from the SSE connection.
      * 
      * @return JsonRpcMessage|null The next message or null if none available
      */
     public function receiveMessage(): ?JsonRpcMessage {
         if (!$this->active) {
             return null;
         }
         
         if ($this->usingBackground) {
             // Background mode - read from IPC pipe
             return $this->receiveMessageFromBackground();
         } else {
             // Foreground mode - process cURL events
             return $this->receiveMessageFromForeground();
         }
     }
     
     /**
      * Receives a message from the background process via IPC.
      * 
      * @return JsonRpcMessage|null The next message or null if none available
      */
     private function receiveMessageFromBackground(): ?JsonRpcMessage {
         if ($this->ipcHandle === null) {
             return null;
         }
         
         // Check if the background process is still running
         if ($this->backgroundPid !== null) {
             $result = pcntl_waitpid($this->backgroundPid, $status, WNOHANG);
             if ($result === $this->backgroundPid) {
                 // Process has exited
                 $this->logger->warning("SSE background process exited with status: " . pcntl_wexitstatus($status));
                 $this->active = false;
                 return null;
             }
         }
         
         // Try to read a message size header (4 bytes)
         $header = fread($this->ipcHandle, 4);
         if ($header === false || strlen($header) < 4) {
             // No data available
             return null;
         }
         
         // Unpack the message size
         $size = unpack('N', $header)[1];
         
         // Read the serialized message
         $serialized = fread($this->ipcHandle, $size);
         if ($serialized === false || strlen($serialized) < $size) {
             // Incomplete read - this shouldn't happen with a properly functioning named pipe
             $this->logger->warning("Incomplete message read from IPC pipe");
             return null;
         }
         
         try {
             // Unserialize the message
             $message = unserialize($serialized);
             if ($message instanceof JsonRpcMessage) {
                 return $message;
             }
         } catch (\Exception $e) {
             $this->logger->warning("Failed to unserialize message from IPC pipe: {$e->getMessage()}");
         }
         
         return null;
     }
     
     /**
      * Receives a message from the foreground cURL connection.
      * 
      * @return JsonRpcMessage|null The next message or null if none available
      */
     private function receiveMessageFromForeground(): ?JsonRpcMessage {
         if ($this->curlHandle === null || $this->multiHandle === null) {
             return null;
         }
         
         // Process any pending cURL events
         curl_multi_exec($this->multiHandle, $running);
         
         // Check for cURL errors
         $info = curl_multi_info_read($this->multiHandle);
         if ($info !== false && $info['result'] !== CURLE_OK) {
             $error = curl_error($this->curlHandle);
             $this->logger->warning("SSE connection error: {$error}");
             $this->active = false;
             return null;
         }
         
         // Check if any messages were parsed during processing
         if (!empty($this->messageQueue)) {
             return array_shift($this->messageQueue);
         }
         
         return null;
     }
     
     /**
      * Checks if the SSE connection is active.
      * 
      * @return bool True if the connection is active, false otherwise
      */
     public function isActive(): bool {
         return $this->active;
     }
     
     /**
      * Gets the last event ID.
      * 
      * @return string|null The last event ID or null if none
      */
     public function getLastEventId(): ?string {
         return $this->lastEventId;
     }
     
     /**
      * Stops the SSE connection.
      */
     public function stop(): void {
         $this->logger->info("Stopping SSE connection");
         
         // Set inactive
         $this->active = false;
         
         if ($this->usingBackground) {
             // Clean up background resources
             $this->killBackgroundProcess();
             
             if ($this->ipcHandle !== null) {
                 fclose($this->ipcHandle);
                 $this->ipcHandle = null;
             }
             
             if ($this->ipcPath !== null && file_exists($this->ipcPath)) {
                 @unlink($this->ipcPath);
                 $this->ipcPath = null;
             }
         } else {
             // Clean up foreground resources
             if ($this->curlHandle !== null && $this->multiHandle !== null) {
                 curl_multi_remove_handle($this->multiHandle, $this->curlHandle);
                 curl_close($this->curlHandle);
                 curl_multi_close($this->multiHandle);
                 $this->curlHandle = null;
                 $this->multiHandle = null;
             }
         }
         
         // Clear any pending messages
         $this->messageQueue = [];
     }
     
     /**
      * Kills the background process if it's running.
      */
     private function killBackgroundProcess(): void {
         if ($this->backgroundPid !== null) {
             $this->logger->info("Terminating SSE background process (PID: {$this->backgroundPid})");
             
             // Try to terminate gracefully
             if (posix_kill($this->backgroundPid, SIGTERM)) {
                 // Wait briefly for graceful termination
                 usleep(100000); // 100ms
                 
                 // Force kill if still running
                 if (posix_kill($this->backgroundPid, 0)) {
                     posix_kill($this->backgroundPid, SIGKILL);
                 }
             }
             
             // Clean up zombie
             pcntl_waitpid($this->backgroundPid, $status, WNOHANG);
             $this->backgroundPid = null;
         }
     }
     
     /**
      * Destructor to ensure resources are cleaned up.
      */
     public function __destruct() {
         $this->stop();
     }
 }
 