<?php

declare(strict_types=1);

namespace Mcp\Client\Auth;

/**
 * Client ID Metadata Document (CIMD) representation.
 *
 * Per the MCP 2025-11-25 spec, a CIMD is a JSON document hosted at a URL
 * that serves as the client_id. The authorization server fetches this document
 * to learn about the client without requiring dynamic registration.
 */
class ClientIdMetadataDocument
{
    /**
     * @param string $clientId The CIMD URL (also serves as client_id)
     * @param string $clientName Human-readable name
     * @param string[] $redirectUris Registered redirect URIs
     * @param string|null $clientUri Client homepage URL
     * @param string|null $logoUri Client logo URL
     * @param string|null $tosUri Terms of Service URL
     * @param string|null $policyUri Privacy Policy URL
     * @param string[]|null $contacts Contact emails
     * @param string[]|null $grantTypes Allowed grant types
     * @param string[]|null $responseTypes Allowed response types
     * @param string|null $scope Default scope
     * @param string|null $tokenEndpointAuthMethod Auth method (must be "none" for CIMD)
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientName,
        public readonly array $redirectUris,
        public ?string $clientUri = null,
        public ?string $logoUri = null,
        public ?string $tosUri = null,
        public ?string $policyUri = null,
        public ?array $contacts = null,
        public ?array $grantTypes = null,
        public ?array $responseTypes = null,
        public ?string $scope = null,
        public ?string $tokenEndpointAuthMethod = 'none',
    ) {}

    public static function fromArray(array $data): self {
        return new self(
            clientId: $data['client_id'] ?? '',
            clientName: $data['client_name'] ?? '',
            redirectUris: $data['redirect_uris'] ?? [],
            clientUri: $data['client_uri'] ?? null,
            logoUri: $data['logo_uri'] ?? null,
            tosUri: $data['tos_uri'] ?? null,
            policyUri: $data['policy_uri'] ?? null,
            contacts: $data['contacts'] ?? null,
            grantTypes: $data['grant_types'] ?? null,
            responseTypes: $data['response_types'] ?? null,
            scope: $data['scope'] ?? null,
            tokenEndpointAuthMethod: $data['token_endpoint_auth_method'] ?? 'none',
        );
    }

    public function toArray(): array {
        $data = [
            'client_id' => $this->clientId,
            'client_name' => $this->clientName,
            'redirect_uris' => $this->redirectUris,
            'token_endpoint_auth_method' => $this->tokenEndpointAuthMethod,
        ];

        if ($this->clientUri !== null) {
            $data['client_uri'] = $this->clientUri;
        }
        if ($this->logoUri !== null) {
            $data['logo_uri'] = $this->logoUri;
        }
        if ($this->tosUri !== null) {
            $data['tos_uri'] = $this->tosUri;
        }
        if ($this->policyUri !== null) {
            $data['policy_uri'] = $this->policyUri;
        }
        if ($this->contacts !== null) {
            $data['contacts'] = $this->contacts;
        }
        if ($this->grantTypes !== null) {
            $data['grant_types'] = $this->grantTypes;
        }
        if ($this->responseTypes !== null) {
            $data['response_types'] = $this->responseTypes;
        }
        if ($this->scope !== null) {
            $data['scope'] = $this->scope;
        }

        return $data;
    }
}
