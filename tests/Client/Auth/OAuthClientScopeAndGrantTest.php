<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
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
 * Filename: tests/Client/Auth/OAuthClientScopeAndGrantTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client\Auth;

use Mcp\Client\Auth\Callback\AuthorizationCallbackInterface;
use Mcp\Client\Auth\Callback\AuthorizationCallbackResult;
use Mcp\Client\Auth\CrossAppAccessConfiguration;
use Mcp\Client\Auth\Discovery\AuthorizationServerMetadata;
use Mcp\Client\Auth\Discovery\MetadataDiscovery;
use Mcp\Client\Auth\Discovery\ProtectedResourceMetadata;
use Mcp\Client\Auth\OAuthClient;
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\OAuthException;
use Mcp\Client\Auth\Registration\ClientCredentials;
use Mcp\Client\Auth\Token\MemoryTokenStorage;
use Mcp\Client\Auth\Token\TokenSet;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Regression guards for scope handling and grant dispatch in OAuthClient:
 *
 *   - SEP-2350: handleInsufficientScope() step-up requests the UNION of the
 *     previously granted scopes and the newly challenged scopes.
 *   - SEP-2207: the offline_access scope is never requested from an AS whose
 *     metadata does not list it in scopes_supported.
 *   - client_credentials grant: configuration dispatches to the
 *     non-interactive flow and private_key_jwt requires key material.
 */
final class OAuthClientScopeAndGrantTest extends TestCase
{
    private const RESOURCE_URL = 'https://api.example.com/mcp';
    private const ISSUER = 'https://as.example.com';

    /**
     * Capture-and-halt callback handler (no token request is ever made).
     */
    private function makeHaltingCallback(): AuthorizationCallbackInterface
    {
        return new class implements AuthorizationCallbackInterface {
            public ?string $capturedAuthUrl = null;

            public function authorize(string $authUrl, string $state): string|AuthorizationCallbackResult
            {
                $this->capturedAuthUrl = $authUrl;
                throw new RuntimeException('HALT_BEFORE_TOKEN_REQUEST');
            }

            public function getRedirectUri(): string
            {
                return 'http://127.0.0.1/callback';
            }
        };
    }

    /**
     * @param array<int, string>|null $scopesSupported
     */
    private function makeAsMetadata(?array $scopesSupported = null): AuthorizationServerMetadata
    {
        return new AuthorizationServerMetadata(
            issuer: self::ISSUER,
            authorizationEndpoint: self::ISSUER . '/authorize',
            tokenEndpoint: self::ISSUER . '/token',
            codeChallengeMethodsSupported: ['S256'],
            scopesSupported: $scopesSupported,
        );
    }

    /**
     * @param array<int, string> $additionalScopes
     * @return array{OAuthClient, MetadataDiscovery&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function createClient(
        AuthorizationCallbackInterface $callback,
        array $additionalScopes = [],
        bool $useClientCredentialsGrant = false,
        ?ClientCredentials $credentials = null,
        ?CrossAppAccessConfiguration $crossAppAccess = null
    ): array {
        $config = new OAuthConfiguration(
            clientCredentials: $credentials ?? new ClientCredentials('test-client', null, 'none'),
            tokenStorage: new MemoryTokenStorage(),
            authCallback: $callback,
            additionalScopes: $additionalScopes,
            redirectUri: 'http://127.0.0.1/callback',
            useClientCredentialsGrant: $useClientCredentialsGrant,
            crossAppAccess: $crossAppAccess,
        );

        $client = new OAuthClient($config);

        $mockDiscovery = $this->createMock(MetadataDiscovery::class);
        $ref = new ReflectionProperty(OAuthClient::class, 'discovery');
        $ref->setAccessible(true);
        $ref->setValue($client, $mockDiscovery);

        return [$client, $mockDiscovery];
    }

    /**
     * Extract the scope parameter from a captured authorization URL.
     */
    private function extractScope(?string $authUrl): ?string
    {
        $this->assertNotNull($authUrl, 'An authorization URL must have been built');
        $query = parse_url($authUrl, PHP_URL_QUERY);
        $this->assertIsString($query);
        parse_str($query, $params);

        return isset($params['scope']) && is_string($params['scope']) ? $params['scope'] : null;
    }

    /**
     * SEP-2350: the step-up authorization request carries the union of the
     * current token's scopes and the scopes challenged in the 403's
     * WWW-Authenticate header, without duplicates.
     */
    public function testInsufficientScopeRequestsUnionOfScopes(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery] = $this->createClient($callback);

        $mockDiscovery->method('discoverResourceMetadata')
            ->willReturn(new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::ISSUER],
            ));
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willReturn($this->makeAsMetadata());

        $current = new TokenSet(
            accessToken: 'token',
            scope: ['mcp:read', 'mcp:write'],
            resourceUrl: self::RESOURCE_URL,
            issuer: self::ISSUER
        );

        try {
            $client->handleInsufficientScope(
                self::RESOURCE_URL,
                ['scope' => 'mcp:write mcp:admin'],
                $current
            );
            $this->fail('Expected the halting callback to abort the flow');
        } catch (RuntimeException $e) {
            $this->assertSame('HALT_BEFORE_TOKEN_REQUEST', $e->getMessage());
        }

        $this->assertSame(
            'mcp:read mcp:write mcp:admin',
            $this->extractScope($callback->capturedAuthUrl),
            'Step-up must request the union of previous and challenged scopes'
        );
    }

    /**
     * SEP-2207: offline_access (e.g. from additionalScopes configuration) is
     * dropped when the AS metadata has no scopes_supported entry for it.
     */
    public function testOfflineAccessFilteredWhenAsDoesNotSupportIt(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery] = $this->createClient(
            $callback,
            additionalScopes: ['offline_access', 'mcp:read']
        );

        $mockDiscovery->method('discoverResourceMetadata')
            ->willReturn(new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::ISSUER],
            ));
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willReturn($this->makeAsMetadata(scopesSupported: ['mcp:read']));

        try {
            $client->handleUnauthorized(self::RESOURCE_URL, []);
            $this->fail('Expected the halting callback to abort the flow');
        } catch (RuntimeException $e) {
            $this->assertSame('HALT_BEFORE_TOKEN_REQUEST', $e->getMessage());
        }

        $this->assertSame(
            'mcp:read',
            $this->extractScope($callback->capturedAuthUrl),
            'offline_access must not be requested when the AS does not advertise it'
        );
    }

    /**
     * SEP-2207: offline_access is kept when the AS advertises it in
     * scopes_supported.
     */
    public function testOfflineAccessKeptWhenAsSupportsIt(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery] = $this->createClient(
            $callback,
            additionalScopes: ['offline_access', 'mcp:read']
        );

        $mockDiscovery->method('discoverResourceMetadata')
            ->willReturn(new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::ISSUER],
            ));
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willReturn($this->makeAsMetadata(scopesSupported: ['mcp:read', 'offline_access']));

        try {
            $client->handleUnauthorized(self::RESOURCE_URL, []);
            $this->fail('Expected the halting callback to abort the flow');
        } catch (RuntimeException $e) {
            $this->assertSame('HALT_BEFORE_TOKEN_REQUEST', $e->getMessage());
        }

        $scope = $this->extractScope($callback->capturedAuthUrl);
        $this->assertNotNull($scope);
        $this->assertStringContainsString('offline_access', $scope);
    }

    /**
     * The filter only targets offline_access; an AS without scopes_supported
     * leaves other scopes untouched.
     */
    public function testFilterLeavesOtherScopesUntouched(): void
    {
        [$client] = $this->createClient($this->makeHaltingCallback());

        $method = new ReflectionMethod(OAuthClient::class, 'filterUnsupportedOfflineAccess');
        $method->setAccessible(true);

        $this->assertSame(
            ['mcp:read', 'mcp:write'],
            $method->invoke($client, ['mcp:read', 'mcp:write'], $this->makeAsMetadata())
        );
        $this->assertSame(
            ['mcp:read'],
            $method->invoke($client, ['offline_access', 'mcp:read'], $this->makeAsMetadata()),
            'offline_access must be filtered when scopes_supported is absent entirely'
        );
    }

    /**
     * client_credentials grant: enabling the option dispatches away from the
     * interactive authorization flow (no callback is invoked); private_key_jwt
     * without key material fails with a clear error before any HTTP request.
     */
    public function testClientCredentialsGrantWithoutKeyMaterialFailsClearly(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery] = $this->createClient(
            $callback,
            useClientCredentialsGrant: true,
            credentials: new ClientCredentials(
                clientId: 'cc-client',
                clientSecret: null,
                tokenEndpointAuthMethod: ClientCredentials::AUTH_METHOD_PRIVATE_KEY_JWT
            )
        );

        $mockDiscovery->method('discoverResourceMetadata')
            ->willReturn(new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::ISSUER],
            ));
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willReturn($this->makeAsMetadata());

        try {
            $client->handleUnauthorized(self::RESOURCE_URL, []);
            $this->fail('Expected OAuthException');
        } catch (OAuthException $e) {
            $this->assertStringContainsString('private key', $e->getMessage());
        }

        $this->assertNull(
            $callback->capturedAuthUrl ?? null,
            'The client_credentials grant must never invoke the interactive callback'
        );
    }

    /**
     * Cross-app access flow: invoking it without pre-registered client
     * credentials for the AS fails with the standard credentials error
     * (no silent fallback to an interactive flow).
     */
    public function testCrossAppAccessRequiresClientCredentials(): void
    {
        $callback = $this->makeHaltingCallback();

        $config = new OAuthConfiguration(
            clientCredentials: null,
            tokenStorage: new MemoryTokenStorage(),
            authCallback: $callback,
            // CIMD/DCR disabled: no credential source remains for the AS leg.
            enableCimd: false,
            enableDynamicRegistration: false,
            crossAppAccess: new CrossAppAccessConfiguration(
                idpTokenEndpoint: 'https://idp.example.com/token',
                idpIdToken: 'id-token',
                idpClientId: 'idp-client',
            ),
        );
        $client = new OAuthClient($config);

        $mockDiscovery = $this->createMock(MetadataDiscovery::class);
        $ref = new ReflectionProperty(OAuthClient::class, 'discovery');
        $ref->setAccessible(true);
        $ref->setValue($client, $mockDiscovery);

        $mockDiscovery->method('discoverResourceMetadata')
            ->willReturn(new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::ISSUER],
            ));
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willReturn($this->makeAsMetadata());

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('No client credentials available');

        $client->handleUnauthorized(self::RESOURCE_URL, []);
    }
}
