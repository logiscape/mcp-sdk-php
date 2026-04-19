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

use Mcp\Server\Elicitation\ElicitationSuspendException;
use Mcp\Server\Elicitation\PendingElicitation;
use Mcp\Server\ServerSession;
use Mcp\Shared\BaseSession;
use Mcp\Shared\RequestResponder;
use Mcp\Types\ElicitationCreateResult;
use Mcp\Types\Implementation;
use Mcp\Types\ClientCapabilities;
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

        return $session;
    }
}
