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
 * Filename: Server/HttpServerSession.php
 */

declare(strict_types=1);

namespace Mcp\Server;

use Mcp\Server\ClientRequestSuspendException;
use Mcp\Server\Elicitation\ElicitationSuspendException;
use Mcp\Server\Elicitation\PendingElicitation;
use Mcp\Server\Sampling\PendingSampling;
use Mcp\Server\Sampling\SamplingSuspendException;
use Mcp\Server\ServerSession;
use Mcp\Shared\BaseSession;
use Mcp\Shared\RequestResponder;
use Mcp\Types\CreateMessageResult;
use Mcp\Types\ElicitationCreateResult;
use Mcp\Types\Implementation;
use Mcp\Types\ClientCapabilities;
use Mcp\Types\Meta;
use Mcp\Types\ModelPreferences;
use Mcp\Types\SamplingMessage;
use Mcp\Types\TaskRequestParams;
use Mcp\Types\ToolChoice;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\RequestId;
use Mcp\Types\RequestParams;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Mcp\Server\InitializationState;
use Mcp\Types\InitializeRequestParams;

class HttpServerSession extends ServerSession
{
    /**
     * Pending elicitation states, keyed by server request ID.
     *
     * Multiple tool calls within the same HTTP request can each suspend
     * independently, so this must be a map rather than a single slot.
     *
     * @var array<int, PendingElicitation>
     */
    protected array $pendingElicitations = [];

    /**
     * Pending sampling states, keyed by server request ID.
     *
     * Mirrors $pendingElicitations: each suspended `sampling/createMessage`
     * round records its own entry, so multi-round sampling within a single
     * tool call works the same way elicitation does.
     *
     * @var array<int, PendingSampling>
     */
    protected array $pendingSamplings = [];

    /**
     * Not supported on HTTP transports.
     *
     * The base implementation blocks in `BaseSession::waitForResponse()` until
     * a matching reply arrives, but in HTTP mode the reply can only arrive on
     * a subsequent POST — so an in-flight tool handler would never see it.
     * This override refuses the call before anything is written, so the
     * request id is never left dangling.
     *
     * Tool handlers should accept a {@see \Mcp\Server\Sampling\SamplingContext}
     * parameter (auto-injected by {@see \Mcp\Server\McpServer}) and call
     * `$sampling->createMessage(...)` or `$sampling->prompt(...)`; that path
     * uses suspend/resume to span HTTP cycles.
     *
     * @param SamplingMessage[] $messages
     * @param string[]|null $stopSequences
     * @param array<int, \Mcp\Types\Tool>|null $tools
     * @throws \BadMethodCallException Always — use SamplingContext.
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
        throw new \BadMethodCallException(
            'ServerSession::sendSamplingRequest() blocks for a client response and cannot be used '
            . 'on an HTTP transport — the response can only arrive on a subsequent POST, so the call '
            . 'would hang the request. Inject a SamplingContext parameter into your tool handler '
            . '(McpServer auto-detects it via reflection) and call $sampling->createMessage(...) '
            . 'or $sampling->prompt(...) — that API uses the HTTP suspend/resume pattern.'
        );
    }

    /**
     * Not supported on HTTP transports — see {@see sendSamplingRequest()} for
     * the rationale. Tool handlers should accept an
     * {@see \Mcp\Server\Elicitation\ElicitationContext} parameter and call
     * `$elicit->form(...)` / `$elicit->url(...)` instead.
     *
     * @param array<string, mixed>|null $requestedSchema
     * @throws \BadMethodCallException Always — use ElicitationContext.
     */
    public function sendElicitationRequest(
        string $message,
        ?array $requestedSchema = null,
        ?string $url = null,
        ?string $elicitationId = null,
        ?Meta $_meta = null,
        ?TaskRequestParams $task = null,
    ): ?ElicitationCreateResult {
        throw new \BadMethodCallException(
            'ServerSession::sendElicitationRequest() blocks for a client response and cannot be used '
            . 'on an HTTP transport — the response can only arrive on a subsequent POST, so the call '
            . 'would hang the request. Inject an ElicitationContext parameter into your tool handler '
            . '(McpServer auto-detects it via reflection) and call $elicit->form(...) / $elicit->url(...) '
            . '— that API uses the HTTP suspend/resume pattern.'
        );
    }

    protected function startMessageProcessing(): void
    {
        $this->isInitialized = true;

        // Normal HTTP path: process messages until none remain, then close.
        while ($this->isInitialized) {
            $message = $this->transport->readMessage();
            if ($message === null) {
                break;
            }

            // Check if this is a response to a pending elicitation request
            $matchedId = $this->matchElicitationResponse($message);
            if ($matchedId !== null) {
                $this->handleElicitationResponse($message, $matchedId);
                continue;
            }

            // Check if this is a response to a pending sampling request
            $matchedId = $this->matchSamplingResponse($message);
            if ($matchedId !== null) {
                $this->handleSamplingResponse($message, $matchedId);
                continue;
            }

            $this->handleIncomingMessage($message);
        }
        $this->close();
    }

    /**
     * Override handleRequest to catch ElicitationSuspendException in HTTP mode.
     *
     * When a tool handler throws ElicitationSuspendException, we:
     * 1. Save the pending state so the next HTTP request can resume
     * 2. Write the elicitation/create request to the outgoing queue
     * 3. Do NOT respond to the original tools/call request (deferred)
     */
    public function handleRequest(RequestResponder $responder): void
    {
        $request = $responder->getRequest();
        $actualRequest = $request->getRequest();
        $method = $actualRequest->method;
        $params = $actualRequest->params ?? null;

        if ($method === 'initialize') {
            $respond = fn($result) => $responder->sendResponse($result);
            $this->handleInitialize($request, $respond);
            return;
        }

        if ($this->initializationState !== InitializationState::Initialized) {
            throw new \RuntimeException('Received request before initialization was complete');
        }

        if (isset($this->methodRequestHandlers[$method])) {
            $this->logger->info("Calling handler for method: $method");
            $handler = $this->methodRequestHandlers[$method];
            try {
                $result = $handler($params);
                $responder->sendResponse($result);
            } catch (ElicitationSuspendException $e) {
                $this->handleElicitationSuspend($e, $responder, $params);
            } catch (SamplingSuspendException $e) {
                $this->handleSamplingSuspend($e, $responder, $params);
            } catch (\Mcp\Shared\McpError $e) {
                $this->logger->error("Handler error for method '$method': " . $e->getMessage());
                $responder->sendResponse($e->error);
            } catch (\Throwable $e) {
                $this->logger->error("Handler error for method '$method': " . $e->getMessage());
                $responder->sendResponse(new \Mcp\Shared\ErrorData(
                    code: -32603,
                    message: $e->getMessage()
                ));
            }
        } else {
            $this->logger->warning("No registered handler for method: $method");
            $responder->sendResponse(new \Mcp\Shared\ErrorData(
                code: -32601,
                message: "Method not found: $method"
            ));
        }
    }

    /**
     * Handle an ElicitationSuspendException from a tool handler.
     *
     * Sends the elicitation/create request to the client and saves the pending
     * state so the tool handler can be resumed when the response arrives.
     */
    protected function handleElicitationSuspend(
        ElicitationSuspendException $e,
        RequestResponder $responder,
        ?RequestParams $originalParams = null,
    ): void {
        // Assign a server request ID for the elicitation request
        $serverRequestId = $this->getNextRequestId();
        $this->setNextRequestId($serverRequestId + 1);

        $this->logger->info("Suspending tool '{$e->toolName}' for elicitation (server request ID: $serverRequestId)");

        // Serialize original request params to preserve _meta, task, and other fields
        $serializedParams = [];
        if ($originalParams !== null) {
            $serialized = $originalParams->jsonSerialize();
            $serializedParams = $serialized instanceof \stdClass
                ? (array) $serialized
                : (is_array($serialized) ? $serialized : []);
        }

        // Save pending state keyed by server request ID
        $this->pendingElicitations[$serverRequestId] = new PendingElicitation(
            toolName: $e->toolName,
            toolArguments: $e->toolArguments,
            originalRequestId: $responder->getRequestId()->value,
            serverRequestId: $serverRequestId,
            elicitationSequence: $e->elicitationSequence,
            previousResults: $e->previousResults,
            createdAt: microtime(true),
            originalRequestParams: $serializedParams,
        );

        // Write the elicitation/create request to the outgoing queue
        $requestId = new RequestId($serverRequestId);
        $jsonRpcRequest = new JSONRPCRequest(
            jsonrpc: '2.0',
            id: $requestId,
            method: $e->request->method,
            params: $e->request->params,
        );
        $this->writeMessage(new JsonRpcMessage($jsonRpcRequest));

        // Do NOT respond to the original request — it remains pending until
        // the client sends back the elicitation response in a subsequent HTTP request.
    }

    /**
     * If an incoming message is a JSON-RPC response that matches a pending
     * elicitation, return that elicitation's server request ID. Otherwise null.
     */
    protected function matchElicitationResponse(JsonRpcMessage $message): ?int
    {
        if (empty($this->pendingElicitations)) {
            return null;
        }

        $inner = $message->message;
        $responseId = null;

        if ($inner instanceof JSONRPCResponse) {
            $responseId = $inner->id->value;
        } elseif ($inner instanceof JSONRPCError) {
            $responseId = $inner->id->value;
        }

        if ($responseId !== null && isset($this->pendingElicitations[$responseId])) {
            return $responseId;
        }

        return null;
    }

    /**
     * Handle the client's response to one of our elicitation requests.
     *
     * Re-invokes the tool handler with the elicitation result pre-loaded,
     * then responds to the original tools/call request.
     */
    protected function handleElicitationResponse(JsonRpcMessage $message, int $serverRequestId): void
    {
        $pending = $this->pendingElicitations[$serverRequestId];
        unset($this->pendingElicitations[$serverRequestId]);

        $inner = $message->message;

        // Parse the elicitation result
        if ($inner instanceof JSONRPCError) {
            $this->logger->warning('Client returned error for elicitation request: ' . ($inner->error->message ?? 'unknown'));
            // Respond to the original request with an error
            $originalRequestId = new RequestId($pending->originalRequestId);
            $this->sendResponse($originalRequestId, new \Mcp\Shared\ErrorData(
                code: -32603,
                message: 'Elicitation failed: client returned error'
            ));
            return;
        }

        // Build the elicitation result from the response
        $resultData = $inner->result;
        if (!is_array($resultData)) {
            $resultData = (array) $resultData;
        }

        // Add this result to accumulated previous results
        $allResults = $pending->previousResults;
        $allResults[$pending->elicitationSequence] = $resultData;

        // Re-invoke the tool handler with preloaded results
        $toolName = $pending->toolName;
        $originalRequestId = new RequestId($pending->originalRequestId);

        if (!isset($this->methodRequestHandlers['tools/call'])) {
            $this->sendResponse($originalRequestId, new \Mcp\Shared\ErrorData(
                code: -32601,
                message: 'No tools/call handler registered'
            ));
            return;
        }

        $handler = $this->methodRequestHandlers['tools/call'];

        // Reconstruct the params, restoring original request fields (_meta, task, etc.)
        $params = new RequestParams();

        // Restore _meta (preserves progressToken, etc.) from original request
        if (isset($pending->originalRequestParams['_meta']) && is_array($pending->originalRequestParams['_meta'])) {
            $meta = new \Mcp\Types\Meta();
            foreach ($pending->originalRequestParams['_meta'] as $k => $v) {
                $meta->$k = $v;
            }
            $params->_meta = $meta;
        }

        // Restore extra fields from original request (e.g. task)
        foreach ($pending->originalRequestParams as $key => $value) {
            if ($key !== '_meta' && $key !== 'name' && $key !== 'arguments') {
                $params->$key = $value;
            }
        }

        // Set tool-specific fields
        $params->name = $toolName;
        $params->arguments = !empty($pending->toolArguments)
            ? (object) $pending->toolArguments
            : new \stdClass();
        // Tag with elicitation results so McpServer can build the context
        $params->_elicitationResults = $allResults;

        // Tell the HTTP transport that the handler we're about to call is
        // a RESUMED handler for `originalRequestId`. While the context is
        // set, all outgoing messages (progress notifications, chained
        // server→client requests, and the final response) append to the
        // same open stream that carried the original tools/call — matching
        // spec §5.6.5's "messages SHOULD relate to the originating client
        // request" and ensuring Last-Event-ID resume can deliver the full
        // tail of the operation.
        $transport = $this->transport;
        $setContext = $transport instanceof \Mcp\Server\Transport\HttpServerTransport
            ? static fn (string|int|null $id) => $transport->setResumeContext($id)
            : static fn (string|int|null $id) => null;

        $setContext($pending->originalRequestId);
        try {
            $result = $handler($params);
            $this->sendResponse($originalRequestId, $result);
        } catch (ElicitationSuspendException $e) {
            // Another elicitation needed — save new pending state
            $newServerRequestId = $this->getNextRequestId();
            $this->setNextRequestId($newServerRequestId + 1);

            $this->logger->info("Tool '{$toolName}' requires additional elicitation (sequence {$e->elicitationSequence})");

            $this->pendingElicitations[$newServerRequestId] = new PendingElicitation(
                toolName: $e->toolName,
                toolArguments: $e->toolArguments,
                originalRequestId: $pending->originalRequestId,
                serverRequestId: $newServerRequestId,
                elicitationSequence: $e->elicitationSequence,
                previousResults: $e->previousResults,
                createdAt: microtime(true),
                originalRequestParams: $pending->originalRequestParams,
            );

            $requestId = new RequestId($newServerRequestId);
            $jsonRpcRequest = new JSONRPCRequest(
                jsonrpc: '2.0',
                id: $requestId,
                method: $e->request->method,
                params: $e->request->params,
            );
            $this->writeMessage(new JsonRpcMessage($jsonRpcRequest));
        } catch (SamplingSuspendException $e) {
            // Tool issued sampling after elicitation resumed — route to the
            // sampling path so the existing machinery takes over.
            $newServerRequestId = $this->getNextRequestId();
            $this->setNextRequestId($newServerRequestId + 1);

            $this->pendingSamplings[$newServerRequestId] = new PendingSampling(
                toolName: $e->toolName,
                toolArguments: $e->toolArguments,
                originalRequestId: $pending->originalRequestId,
                serverRequestId: $newServerRequestId,
                samplingSequence: $e->samplingSequence,
                previousResults: $e->previousResults,
                createdAt: microtime(true),
                originalRequestParams: $pending->originalRequestParams,
            );

            $requestId = new RequestId($newServerRequestId);
            $jsonRpcRequest = new JSONRPCRequest(
                jsonrpc: '2.0',
                id: $requestId,
                method: $e->request->method,
                params: $e->request->params,
            );
            $this->writeMessage(new JsonRpcMessage($jsonRpcRequest));
        } catch (\Mcp\Shared\McpError $e) {
            $this->sendResponse($originalRequestId, $e->error);
        } catch (\Throwable $e) {
            $this->sendResponse($originalRequestId, new \Mcp\Shared\ErrorData(
                code: -32603,
                message: $e->getMessage()
            ));
        } finally {
            $setContext(null);
        }
    }

    /**
     * Handle a SamplingSuspendException from a tool handler.
     *
     * Mirrors handleElicitationSuspend(): writes the sampling/createMessage
     * request to the client and records pending state so the tool can be
     * resumed in the next HTTP cycle.
     */
    protected function handleSamplingSuspend(
        SamplingSuspendException $e,
        RequestResponder $responder,
        ?RequestParams $originalParams = null,
    ): void {
        $serverRequestId = $this->getNextRequestId();
        $this->setNextRequestId($serverRequestId + 1);

        $this->logger->info("Suspending tool '{$e->toolName}' for sampling (server request ID: $serverRequestId)");

        $serializedParams = [];
        if ($originalParams !== null) {
            $serialized = $originalParams->jsonSerialize();
            $serializedParams = $serialized instanceof \stdClass
                ? (array) $serialized
                : (is_array($serialized) ? $serialized : []);
        }

        $this->pendingSamplings[$serverRequestId] = new PendingSampling(
            toolName: $e->toolName,
            toolArguments: $e->toolArguments,
            originalRequestId: $responder->getRequestId()->value,
            serverRequestId: $serverRequestId,
            samplingSequence: $e->samplingSequence,
            previousResults: $e->previousResults,
            createdAt: microtime(true),
            originalRequestParams: $serializedParams,
        );

        $requestId = new RequestId($serverRequestId);
        $jsonRpcRequest = new JSONRPCRequest(
            jsonrpc: '2.0',
            id: $requestId,
            method: $e->request->method,
            params: $e->request->params,
        );
        $this->writeMessage(new JsonRpcMessage($jsonRpcRequest));
    }

    /**
     * Return the server request ID of a pending sampling that matches this
     * incoming response, or null if none.
     */
    protected function matchSamplingResponse(JsonRpcMessage $message): ?int
    {
        if (empty($this->pendingSamplings)) {
            return null;
        }

        $inner = $message->message;
        $responseId = null;

        if ($inner instanceof JSONRPCResponse) {
            $responseId = $inner->id->value;
        } elseif ($inner instanceof JSONRPCError) {
            $responseId = $inner->id->value;
        }

        if ($responseId !== null && isset($this->pendingSamplings[$responseId])) {
            return $responseId;
        }

        return null;
    }

    /**
     * Handle the client's response to a pending sampling/createMessage request.
     *
     * Re-invokes the tool handler with the sampling result preloaded so the
     * handler can continue past the suspend point.
     */
    protected function handleSamplingResponse(JsonRpcMessage $message, int $serverRequestId): void
    {
        $pending = $this->pendingSamplings[$serverRequestId];
        unset($this->pendingSamplings[$serverRequestId]);

        $inner = $message->message;

        if ($inner instanceof JSONRPCError) {
            $this->logger->warning('Client returned error for sampling request: ' . ($inner->error->message ?? 'unknown'));
            $originalRequestId = new RequestId($pending->originalRequestId);
            $this->sendResponse($originalRequestId, new \Mcp\Shared\ErrorData(
                code: -32603,
                message: 'Sampling failed: client returned error'
            ));
            return;
        }

        $resultData = $inner->result;
        if (!is_array($resultData)) {
            $resultData = (array) $resultData;
        }

        $allResults = $pending->previousResults;
        $allResults[$pending->samplingSequence] = $resultData;

        $toolName = $pending->toolName;
        $originalRequestId = new RequestId($pending->originalRequestId);

        if (!isset($this->methodRequestHandlers['tools/call'])) {
            $this->sendResponse($originalRequestId, new \Mcp\Shared\ErrorData(
                code: -32601,
                message: 'No tools/call handler registered'
            ));
            return;
        }

        $handler = $this->methodRequestHandlers['tools/call'];

        $params = new RequestParams();

        if (isset($pending->originalRequestParams['_meta']) && is_array($pending->originalRequestParams['_meta'])) {
            $meta = new \Mcp\Types\Meta();
            foreach ($pending->originalRequestParams['_meta'] as $k => $v) {
                $meta->$k = $v;
            }
            $params->_meta = $meta;
        }

        foreach ($pending->originalRequestParams as $key => $value) {
            if ($key !== '_meta' && $key !== 'name' && $key !== 'arguments') {
                $params->$key = $value;
            }
        }

        $params->name = $toolName;
        $params->arguments = !empty($pending->toolArguments)
            ? (object) $pending->toolArguments
            : new \stdClass();
        $params->_samplingResults = $allResults;

        // Same stream-routing contract as elicitation — see handleElicitationResponse.
        $transport = $this->transport;
        $setContext = $transport instanceof \Mcp\Server\Transport\HttpServerTransport
            ? static fn (string|int|null $id) => $transport->setResumeContext($id)
            : static fn (string|int|null $id) => null;

        $setContext($pending->originalRequestId);
        try {
            $result = $handler($params);
            $this->sendResponse($originalRequestId, $result);
        } catch (SamplingSuspendException $e) {
            $newServerRequestId = $this->getNextRequestId();
            $this->setNextRequestId($newServerRequestId + 1);

            $this->logger->info("Tool '{$toolName}' requires additional sampling (sequence {$e->samplingSequence})");

            $this->pendingSamplings[$newServerRequestId] = new PendingSampling(
                toolName: $e->toolName,
                toolArguments: $e->toolArguments,
                originalRequestId: $pending->originalRequestId,
                serverRequestId: $newServerRequestId,
                samplingSequence: $e->samplingSequence,
                previousResults: $e->previousResults,
                createdAt: microtime(true),
                originalRequestParams: $pending->originalRequestParams,
            );

            $requestId = new RequestId($newServerRequestId);
            $jsonRpcRequest = new JSONRPCRequest(
                jsonrpc: '2.0',
                id: $requestId,
                method: $e->request->method,
                params: $e->request->params,
            );
            $this->writeMessage(new JsonRpcMessage($jsonRpcRequest));
        } catch (ElicitationSuspendException $e) {
            // Tool issued an elicitation after sampling resumed — route to the
            // elicitation path so the existing machinery takes over from here.
            $newServerRequestId = $this->getNextRequestId();
            $this->setNextRequestId($newServerRequestId + 1);

            $this->pendingElicitations[$newServerRequestId] = new PendingElicitation(
                toolName: $e->toolName,
                toolArguments: $e->toolArguments,
                originalRequestId: $pending->originalRequestId,
                serverRequestId: $newServerRequestId,
                elicitationSequence: $e->elicitationSequence,
                previousResults: $e->previousResults,
                createdAt: microtime(true),
                originalRequestParams: $pending->originalRequestParams,
            );

            $requestId = new RequestId($newServerRequestId);
            $jsonRpcRequest = new JSONRPCRequest(
                jsonrpc: '2.0',
                id: $requestId,
                method: $e->request->method,
                params: $e->request->params,
            );
            $this->writeMessage(new JsonRpcMessage($jsonRpcRequest));
        } catch (\Mcp\Shared\McpError $e) {
            $this->sendResponse($originalRequestId, $e->error);
        } catch (\Throwable $e) {
            $this->sendResponse($originalRequestId, new \Mcp\Shared\ErrorData(
                code: -32603,
                message: $e->getMessage()
            ));
        } finally {
            $setContext(null);
        }
    }

    /**
     * Get the pending elicitation states.
     *
     * @return array<int, PendingElicitation> Keyed by server request ID
     */
    public function getPendingElicitations(): array
    {
        return $this->pendingElicitations;
    }

    /**
     * Get a single pending elicitation (convenience for the common single-pending case).
     *
     * @return PendingElicitation|null The first pending elicitation, or null if none
     */
    public function getPendingElicitation(): ?PendingElicitation
    {
        return empty($this->pendingElicitations) ? null : reset($this->pendingElicitations);
    }

    /**
     * Get the pending sampling states.
     *
     * @return array<int, PendingSampling> Keyed by server request ID
     */
    public function getPendingSamplings(): array
    {
        return $this->pendingSamplings;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'initializationState' => $this->initializationState->value,
            'clientParams' => $this->clientParams
                ? $this->clientParams->jsonSerialize()
                : null,
            'negotiatedProtocolVersion' => $this->negotiatedProtocolVersion,
            'nextRequestId' => $this->getNextRequestId(),
        ];

        // Persist pending elicitation states
        if (!empty($this->pendingElicitations)) {
            $serialized = [];
            foreach ($this->pendingElicitations as $id => $pending) {
                $serialized[$id] = $pending->toArray();
            }
            $data['pendingElicitations'] = $serialized;
        }

        // Persist pending sampling states
        if (!empty($this->pendingSamplings)) {
            $serialized = [];
            foreach ($this->pendingSamplings as $id => $pending) {
                $serialized[$id] = $pending->toArray();
            }
            $data['pendingSamplings'] = $serialized;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(
        array $data,
        \Mcp\Server\Transport\Transport $transport,
        InitializationOptions $initOptions,
        \Psr\Log\LoggerInterface $logger
    ): self {
        // Build a new session object using existing constructor
        $session = new self($transport, $initOptions, $logger);

        // Restore the fields
        $session->initializationState = InitializationState::from($data['initializationState']);

        if (!empty($data['clientParams'])) {
            $clientParamsData = $data['clientParams'];

            // Reconstruct ClientCapabilities from serialized data
            $capabilitiesData = $clientParamsData['capabilities'] ?? [];
            $capabilities = is_array($capabilitiesData) && !empty($capabilitiesData)
                ? ClientCapabilities::fromArray($capabilitiesData)
                : new ClientCapabilities();

            // Instantiate Implementation for clientInfo
            $clientInfoData = $clientParamsData['clientInfo'] ?? [];
            if ($clientInfoData instanceof Implementation) {
                $clientInfo = $clientInfoData;
            } else {
                $clientInfo = new Implementation(
                    name: $clientInfoData['name'] ?? '',
                    version: $clientInfoData['version'] ?? ''
                );
            }

            // Now instantiate InitializeRequestParams
            $initParams = new InitializeRequestParams(
                protocolVersion: $clientParamsData['protocolVersion'] ?? '',
                capabilities: $capabilities,
                clientInfo: $clientInfo
            );

            $session->clientParams = $initParams;
        }

        $session->negotiatedProtocolVersion =
            $data['negotiatedProtocolVersion'] ?? $session->negotiatedProtocolVersion;

        // Restore request ID counter
        if (isset($data['nextRequestId'])) {
            $session->setNextRequestId((int) $data['nextRequestId']);
        }

        // Restore pending elicitation states
        if (!empty($data['pendingElicitations'])) {
            foreach ($data['pendingElicitations'] as $id => $pendingData) {
                $session->pendingElicitations[(int) $id] = PendingElicitation::fromArray($pendingData);
            }
        }
        // Backward compatibility: single-slot format from earlier serializations
        if (!empty($data['pendingElicitation']) && empty($data['pendingElicitations'])) {
            $p = PendingElicitation::fromArray($data['pendingElicitation']);
            $session->pendingElicitations[$p->serverRequestId] = $p;
        }

        // Restore pending sampling states
        if (!empty($data['pendingSamplings'])) {
            foreach ($data['pendingSamplings'] as $id => $pendingData) {
                $session->pendingSamplings[(int) $id] = PendingSampling::fromArray($pendingData);
            }
        }

        return $session;
    }
}
