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

use Mcp\Server\ServerSession;
use Mcp\Shared\BaseSession;
use Mcp\Types\Implementation;
use Mcp\Types\ClientCapabilities;
use Mcp\Server\InitializationState;
use Mcp\Types\InitializeRequestParams;

class HttpServerSession extends ServerSession
{
    protected function startMessageProcessing(): void
    {
        $this->isInitialized = true;

        // Normal HTTP path: process messages until none remain, then close.
        while ($this->isInitialized) {
            $message = $this->transport->readMessage();
            if ($message === null) {
                break;
            }
            $this->handleIncomingMessage($message);
        }
        $this->close();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            // InitializationState is an enum; store its integer value
            'initializationState' => $this->initializationState->value,
            
            // $clientParams is an object (InitializeRequestParams)
            // so convert to an array or JSON
            'clientParams' => $this->clientParams
                ? $this->clientParams->jsonSerialize()
                : null,
            
            'negotiatedProtocolVersion' => $this->negotiatedProtocolVersion,
        ];
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
            $clientInfo = new Implementation(
                name: $clientParamsData['clientInfo']['name'] ?? '',
                version: $clientParamsData['clientInfo']['version'] ?? ''
            );
    
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

        return $session;
    }
}
