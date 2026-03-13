<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * Developed by:
 * - Josh Abbott
 * - Claude Opus 4.5 (Anthropic AI model)
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
 * Filename: Client/Auth/Registration/DynamicClientRegistration.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth\Registration;

use Mcp\Client\Auth\Discovery\AuthorizationServerMetadata;
use Mcp\Client\Auth\OAuthException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Dynamic Client Registration per RFC7591.
 *
 * Allows clients to dynamically register with an authorization server
 * that supports the registration endpoint.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7591
 */
class DynamicClientRegistration
{
    private LoggerInterface $logger;
    private float $timeout;
    private bool $verifyTls;

    /**
     * @param float $timeout HTTP request timeout in seconds
     * @param bool $verifyTls Whether to verify TLS certificates
     * @param LoggerInterface|null $logger PSR-3 logger
     */
    public function __construct(
        float $timeout = 30.0,
        bool $verifyTls = true,
        ?LoggerInterface $logger = null
    ) {
        $this->timeout = $timeout;
        $this->verifyTls = $verifyTls;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Register a new client with the authorization server.
     *
     * @param AuthorizationServerMetadata $as The authorization server metadata
     * @param array<string, mixed> $metadata Client metadata to register
     * @return ClientCredentials The registered client credentials
     * @throws OAuthException If registration fails
     */
    public function register(
        AuthorizationServerMetadata $as,
        array $metadata
    ): ClientCredentials {
        if (!$as->supportsDynamicRegistration()) {
            throw new OAuthException(
                'Authorization server does not support dynamic client registration'
            );
        }

        $this->logger->debug('Registering client via DCR', [
            'registration_endpoint' => $as->registrationEndpoint,
            'metadata' => array_keys($metadata),
        ]);

        // Ensure required fields have defaults
        $metadata = array_merge([
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], $metadata);

        $ch = curl_init($as->registrationEndpoint);
        if ($ch === false) {
            throw new OAuthException('Failed to initialize cURL for client registration');
        }

        $body = json_encode($metadata);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new OAuthException("Client registration request failed: {$error}");
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new OAuthException(
                'Invalid JSON response from registration endpoint: ' . json_last_error_msg()
            );
        }

        // Check for error response
        if (isset($data['error'])) {
            throw OAuthException::fromOAuthError($data);
        }

        // Validate successful registration response
        if ($httpCode !== 201 && $httpCode !== 200) {
            throw new OAuthException(
                "Client registration failed with HTTP {$httpCode}"
            );
        }

        if (!isset($data['client_id'])) {
            throw new OAuthException(
                'Client registration response missing client_id'
            );
        }

        $this->logger->info('Client registered successfully', [
            'client_id' => $data['client_id'],
        ]);

        return new ClientCredentials(
            clientId: $data['client_id'],
            clientSecret: $data['client_secret'] ?? null,
            tokenEndpointAuthMethod: $data['token_endpoint_auth_method'] ?? 'none'
        );
    }

    /**
     * Build client metadata for registration.
     *
     * @param string $clientName The client name
     * @param array<int, string> $redirectUris The redirect URIs
     * @param array<string, mixed> $additionalMetadata Additional metadata fields
     * @return array<string, mixed> The complete metadata for registration
     */
    public static function buildMetadata(
        string $clientName,
        array $redirectUris,
        array $additionalMetadata = []
    ): array {
        return array_merge([
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], $additionalMetadata);
    }
}
