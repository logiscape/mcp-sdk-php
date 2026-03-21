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
 * Filename: Client/Transport/StdioTransport.php
 */

declare(strict_types=1);

namespace Mcp\Client\Transport;

use Mcp\Types\JsonRpcMessage;
use Mcp\Shared\MemoryStream;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use InvalidArgumentException;
use Exception; // Import the global Exception class
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JSONRPCError;
use Mcp\Types\RequestId;
use Mcp\Types\JsonRpcErrorObject;
use Mcp\Types\NotificationParams;
use Mcp\Types\RequestParams;
use Mcp\Types\Meta;

/**
 * Class StdioTransport
 *
 * Manages STDIO-based communication with an MCP server process.
 *
 * This class handles the initiation of the server process, communication via STDIN and STDOUT,
 * and the serialization/deserialization of JSON-RPC messages.
 */
class StdioTransport {
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    /** @var StdioServerParameters */
    private StdioServerParameters $parameters;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /**
     * StdioTransport constructor.
     *
     * @param StdioServerParameters      $parameters Configuration parameters for the STDIO server connection.
     * @param LoggerInterface|null       $logger     PSR-3 compliant logger.
     */
    public function __construct(
        StdioServerParameters $parameters,
        ?LoggerInterface $logger = null
    ) {
        $this->parameters = $parameters;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Opens the connection to the server process.
     *
     * @return array{MemoryStream, MemoryStream} Tuple of read and write streams.
     *
     * @throws RuntimeException If the process fails to start.
     */
    public function connect(): array {
        $descriptorSpec = [
            0 => ['pipe', 'r'],  // STDIN for the server process.
            1 => ['pipe', 'w'],  // STDOUT from the server process.
            2 => ['pipe', 'w'],  // STDERR from the server process.
        ];

        // Ensure environment initialization is done before using EnvironmentHelper.
        EnvironmentHelper::initialize();
        $env = $this->parameters->getEnv() ?? EnvironmentHelper::getDefaultEnvironment();

        $command = $this->buildCommand();
        $this->logger->info("Starting server process: $command");

        $this->process = proc_open($command, $descriptorSpec, $this->pipes, null, $env);

        if ($this->process === false || !is_resource($this->process)) {
            throw new RuntimeException("Failed to start process: $command");
        }

        // Set non-blocking mode for STDOUT and STDERR.
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        // Initialize read and write streams.
        $readStream = new class($this->pipes[1], $this->logger, $this->process) extends MemoryStream {
            /** @var resource */
            private $pipe;
            private LoggerInterface $logger;
            /** @var resource */
            private $process;

            /**
             * @param resource $pipe
             * @param resource $process
             */
            public function __construct($pipe, LoggerInterface $logger, $process) {
                $this->pipe = $pipe;
                $this->logger = $logger;
                $this->process = $process;
                // Removed parent::__construct();
            }

            /**
             * Receive a JsonRpcMessage from the server.
             *
             * @return JsonRpcMessage|Exception|null The received message, an exception, or null if no message is available.
             */
            public function receive(): mixed {
                $buffer = '';
                while (($chunk = fgets($this->pipe)) !== false) {
                    $buffer .= $chunk;

                    // Assuming each message is delimited by a newline.
                    if (str_ends_with(trim($buffer), '}') || str_ends_with(trim($buffer), ']')) {
                        try {
                            $data = json_decode(trim($buffer), true, 512, JSON_THROW_ON_ERROR);
                            $jsonRpcMessage = $this->instantiateJsonRpcMessage($data);
                            $this->logger->debug('Received JsonRpcMessage: ' . json_encode($jsonRpcMessage, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                            return $jsonRpcMessage;
                        } catch (\JsonException $e) {
                            $this->logger->error('JSON parse error: ' . $e->getMessage());
                            return $e;
                        } catch (InvalidArgumentException $e) {
                            $this->logger->error('Invalid JsonRpcMessage: ' . $e->getMessage());
                            return $e;
                        }
                    }
                }

                if (feof($this->pipe)) {
                    $this->logger->warning('Server process has terminated unexpectedly.');
                    return new RuntimeException('Server process terminated.');
                }

                return null;
            }

            /**
             * Instantiates a JsonRpcMessage from an array of JSON-RPC data.
             *
             * This method handles both single JSON-RPC objects and batch requests/responses.
             *
             * @param array<string|int, mixed> $data The JSON-RPC data to instantiate.
             *
             * @return JsonRpcMessage The instantiated JsonRpcMessage.
             *
             * @throws InvalidArgumentException If the JSON-RPC data is invalid.
             */
            private function instantiateJsonRpcMessage(array $data): JsonRpcMessage {
                return $this->instantiateSingleMessage($data);
            }
            
            /**
             * Helper to parse a single JSON-RPC object (request/notification/response/error).
             *
             * @param array<string, mixed> $data The JSON-RPC data to instantiate.
             *
             * @return JsonRpcMessage The instantiated JsonRpcMessage.
             *
             * @throws InvalidArgumentException If the JSON-RPC data is invalid.
             */
            private function instantiateSingleMessage(array $data): JsonRpcMessage {
                if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
                    throw new InvalidArgumentException('Invalid JSON-RPC version.');
                }
            
                // request/notification
                if (isset($data['method'])) {
                    if (isset($data['id'])) {
                        // request
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
                        // notification
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
                }
            
                // response/error
                if (array_key_exists('error', $data)) {
                    // error response
                    $errorData = $data['error'];
                    return new JsonRpcMessage(new JSONRPCError(
                        jsonrpc: '2.0',
                        id: isset($data['id']) ? new RequestId($data['id']) : null,
                        error: new JsonRpcErrorObject(
                            code: $errorData['code'],
                            message: $errorData['message'],
                            data: $errorData['data'] ?? null
                        )
                    ));
                } else {
                    // success response
                    return new JsonRpcMessage(new JSONRPCResponse(
                        jsonrpc: '2.0',
                        id: isset($data['id']) ? new RequestId($data['id']) : null,
                        result: $data['result'] ?? null
                    ));
                }
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
            private function parseRequestParams(array $params): RequestParams {
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
             * Helper to convert an associative array to a Meta object.
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
            
        };

        $writeStream = new class($this->pipes[0], $this->logger, $this->process) extends MemoryStream {
            /** @var resource */
            private $pipe;
            private LoggerInterface $logger;
            /** @var resource */
            private $process;

            /**
             * @param resource $pipe
             * @param resource $process
             */
            public function __construct($pipe, LoggerInterface $logger, $process) {
                $this->pipe = $pipe;
                $this->logger = $logger;
                $this->process = $process;
                // Removed parent::__construct();
            }

            /**
             * Send a JsonRpcMessage or Exception to the server.
             *
             * @param JsonRpcMessage|Exception $message The JSON-RPC message or exception to send.
             *
             * @return void
             *
             * @throws InvalidArgumentException If the message is not a JsonRpcMessage.
             * @throws RuntimeException If writing to the pipe fails.
             */
            public function send(mixed $message): void {
                if (!$message instanceof JsonRpcMessage) {
                    throw new InvalidArgumentException('Only JsonRpcMessage instances can be sent.');
                }

                if (!is_resource($this->pipe)) {
                    echo("Write pipe is no longer a valid resource\n");
                    throw new RuntimeException('Write pipe is invalid');
                }
    
                $status = proc_get_status($this->process);
                if (!$status['running']) {
                    echo("Server process has terminated. Exit code: " . $status['exitcode']."\n");
                    throw new RuntimeException('Server process has terminated');
                }

                $innerMessage = $message->message;

                if ($innerMessage instanceof \Mcp\Types\JSONRPCRequest ||
                    $innerMessage instanceof \Mcp\Types\JSONRPCNotification) {
                    $payload = [
                        'jsonrpc' => '2.0',
                        'method' => $innerMessage->method,
                    ];

                    if ($innerMessage->params !== null) {
                        // We assume $innerMessage->params implements McpModel (and thus jsonSerialize).
                        $serializedParams = $innerMessage->params->jsonSerialize();
                        if (!empty($serializedParams)) {
                            $payload['params'] = $serializedParams;
                        }
                    }

                    if ($innerMessage instanceof \Mcp\Types\JSONRPCRequest) {
                        $payload['id'] = (string)$innerMessage->id->value;
                    }
                } elseif ($innerMessage instanceof \Mcp\Types\JSONRPCResponse ||
                          $innerMessage instanceof \Mcp\Types\JSONRPCError) {
                    $payload = [
                        'jsonrpc' => '2.0',
                        'id' => (string)$innerMessage->id->value
                    ];

                    if ($innerMessage instanceof \Mcp\Types\JSONRPCResponse) {
                        $payload['result'] = $innerMessage->result;
                    } elseif ($innerMessage instanceof \Mcp\Types\JSONRPCError) {
                        $payload['error'] = [
                            'code' => $innerMessage->error->code,
                            'message' => $innerMessage->error->message,
                            'data' => $innerMessage->error->data
                        ];
                    }
                } else {
                    throw new InvalidArgumentException('Unsupported JsonRpcMessage variant.');
                }

                $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json === false) {
                    throw new RuntimeException('Failed to encode JsonRpcMessage to JSON: ' . json_last_error_msg());
                }

                $json .= "\n"; // Append newline as message delimiter.

                $bytesWritten = fwrite($this->pipe, $json);
                if ($bytesWritten === false) {
                    $this->logger->error('Failed to write message to server.');
                    throw new RuntimeException('Failed to write message to server.');
                }

                fflush($this->pipe);
                $this->logger->debug('Sent JsonRpcMessage: ' . $json);
            }
        };

        $this->logger->info('Connected to server process successfully.');
        return [$readStream, $writeStream];
    }

    /**
     * Closes the connection to the server process.
     *
     * @return void
     */
    public function close(): void {
        if (isset($this->pipes)) {
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }

        if (isset($this->process) && is_resource($this->process)) {
            proc_terminate($this->process, 9); // Forcefully terminate the process.
            proc_close($this->process);
            $this->logger->info('Server process terminated and closed.');
        }
    }

    /**
     * Builds the full command string with arguments.
     *
     * @return string The complete command to execute.
     */
    private function buildCommand(): string {
        $command = escapeshellcmd($this->parameters->getCommand());
        $args = array_map('escapeshellarg', $this->parameters->getArgs());
        return $command . ' ' . implode(' ', $args);
    }
}