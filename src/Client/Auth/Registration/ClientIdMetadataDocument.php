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
 * Filename: Client/Auth/Registration/ClientIdMetadataDocument.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth\Registration;

/**
 * Client ID Metadata Document (CIMD) support.
 *
 * Implements draft-ietf-oauth-client-id-metadata-document-00 for
 * clients that host their own metadata document at an HTTPS URL.
 *
 * The client_id is the HTTPS URL where the metadata document is hosted.
 * The authorization server fetches this URL to retrieve client metadata.
 *
 * @see https://datatracker.ietf.org/doc/draft-ietf-oauth-client-id-metadata-document/
 */
class ClientIdMetadataDocument
{
    /**
     * @param string $clientIdUrl HTTPS URL serving as the client_id
     * @param string $clientName Human-readable client name
     * @param array<int, string> $redirectUris Allowed redirect URIs
     * @param string|null $clientUri URL of the client home page
     * @param string|null $logoUri URL of the client logo
     * @param array<int, string> $contacts Contact emails for the client
     * @param string|null $tosUri URL of the terms of service
     * @param string|null $policyUri URL of the privacy policy
     * @param array<int, string> $grantTypes Allowed grant types
     * @param array<int, string> $responseTypes Allowed response types
     * @param string $tokenEndpointAuthMethod Token endpoint auth method
     * @param string|null $softwareId Software identifier
     * @param string|null $softwareVersion Software version
     */
    public function __construct(
        public readonly string $clientIdUrl,
        public readonly string $clientName,
        public readonly array $redirectUris = [],
        public readonly ?string $clientUri = null,
        public readonly ?string $logoUri = null,
        public readonly array $contacts = [],
        public readonly ?string $tosUri = null,
        public readonly ?string $policyUri = null,
        public readonly array $grantTypes = ['authorization_code', 'refresh_token'],
        public readonly array $responseTypes = ['code'],
        public readonly string $tokenEndpointAuthMethod = 'none',
        public readonly ?string $softwareId = null,
        public readonly ?string $softwareVersion = null
    ) {
        if (!str_starts_with($clientIdUrl, 'https://')) {
            throw new \InvalidArgumentException('Client ID URL must use HTTPS');
        }
    }

    /**
     * Get the client_id (which is the metadata URL).
     *
     * @return string
     */
    public function getClientId(): string
    {
        return $this->clientIdUrl;
    }

    /**
     * Convert to ClientCredentials for use in OAuth flows.
     *
     * @return ClientCredentials
     */
    public function toClientCredentials(): ClientCredentials
    {
        return new ClientCredentials(
            clientId: $this->clientIdUrl,
            clientSecret: null,
            tokenEndpointAuthMethod: $this->tokenEndpointAuthMethod
        );
    }

    /**
     * Generate the metadata document array.
     *
     * This is what should be served at the clientIdUrl.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'client_id' => $this->clientIdUrl,
            'client_name' => $this->clientName,
            'redirect_uris' => $this->redirectUris,
            'grant_types' => $this->grantTypes,
            'response_types' => $this->responseTypes,
            'token_endpoint_auth_method' => $this->tokenEndpointAuthMethod,
        ];

        if ($this->clientUri !== null) {
            $data['client_uri'] = $this->clientUri;
        }

        if ($this->logoUri !== null) {
            $data['logo_uri'] = $this->logoUri;
        }

        if (!empty($this->contacts)) {
            $data['contacts'] = $this->contacts;
        }

        if ($this->tosUri !== null) {
            $data['tos_uri'] = $this->tosUri;
        }

        if ($this->policyUri !== null) {
            $data['policy_uri'] = $this->policyUri;
        }

        if ($this->softwareId !== null) {
            $data['software_id'] = $this->softwareId;
        }

        if ($this->softwareVersion !== null) {
            $data['software_version'] = $this->softwareVersion;
        }

        return $data;
    }

    /**
     * Generate the metadata document as JSON.
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Create from an array (e.g., from fetched metadata).
     *
     * @param array<string, mixed> $data The metadata array
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            clientIdUrl: $data['client_id'],
            clientName: $data['client_name'] ?? 'Unknown Client',
            redirectUris: $data['redirect_uris'] ?? [],
            clientUri: $data['client_uri'] ?? null,
            logoUri: $data['logo_uri'] ?? null,
            contacts: $data['contacts'] ?? [],
            tosUri: $data['tos_uri'] ?? null,
            policyUri: $data['policy_uri'] ?? null,
            grantTypes: $data['grant_types'] ?? ['authorization_code', 'refresh_token'],
            responseTypes: $data['response_types'] ?? ['code'],
            tokenEndpointAuthMethod: $data['token_endpoint_auth_method'] ?? 'none',
            softwareId: $data['software_id'] ?? null,
            softwareVersion: $data['software_version'] ?? null
        );
    }
}
