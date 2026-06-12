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
 * Filename: tests/Client/Auth/OAuthClientIssValidationTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client\Auth;

use Mcp\Client\Auth\AuthorizationRequest;
use Mcp\Client\Auth\Callback\AuthorizationCallbackInterface;
use Mcp\Client\Auth\Callback\AuthorizationCallbackResult;
use Mcp\Client\Auth\Discovery\AuthorizationServerMetadata;
use Mcp\Client\Auth\Discovery\ProtectedResourceMetadata;
use Mcp\Client\Auth\OAuthClient;
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\OAuthException;
use Mcp\Client\Auth\Registration\ClientCredentials;
use Mcp\Client\Auth\Token\MemoryTokenStorage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for SEP-2468 (RFC 9207) authorization response iss validation.
 *
 * Validation matrix (client-side, before any token exchange, applies to
 * error responses too):
 *   - advertised true + iss present  -> byte-for-byte compare; mismatch aborts
 *   - advertised true + iss absent   -> abort
 *   - not advertised  + iss present  -> STILL compare; mismatch aborts
 *   - not advertised  + iss absent   -> proceed
 *
 * The comparison MUST be non-normalized: no case folding, default-port
 * elision, or trailing-slash normalization. On mismatch the client must not
 * act on error parameters from the response.
 */
final class OAuthClientIssValidationTest extends TestCase
{
    private const ISSUER = 'https://as.example.com';
    private const RESOURCE_URL = 'https://api.example.com/mcp';

    private function makeAsMetadata(?bool $issSupported): AuthorizationServerMetadata
    {
        return new AuthorizationServerMetadata(
            issuer: self::ISSUER,
            authorizationEndpoint: self::ISSUER . '/authorize',
            tokenEndpoint: self::ISSUER . '/token',
            codeChallengeMethodsSupported: ['S256'],
            authorizationResponseIssParameterSupported: $issSupported
        );
    }

    private function invokeValidate(OAuthClient $client, ?string $iss, ?bool $issSupported): void
    {
        $method = new ReflectionMethod(OAuthClient::class, 'validateAuthorizationResponseIssuer');
        $method->setAccessible(true);
        $method->invoke($client, $iss, $this->makeAsMetadata($issSupported));
    }

    private function makeClient(?AuthorizationCallbackInterface $callback = null): OAuthClient
    {
        $config = new OAuthConfiguration(
            clientCredentials: new ClientCredentials(
                clientId: 'pre-registered-client',
                clientSecret: null,
                tokenEndpointAuthMethod: ClientCredentials::AUTH_METHOD_NONE
            ),
            tokenStorage: new MemoryTokenStorage(),
            authCallback: $callback,
            redirectUri: 'http://127.0.0.1/callback',
        );

        return new OAuthClient($config);
    }

    /**
     * Build a stub callback handler returning a fixed result.
     */
    private function makeCallback(string|AuthorizationCallbackResult $result): AuthorizationCallbackInterface
    {
        return new class ($result) implements AuthorizationCallbackInterface {
            public function __construct(
                private string|AuthorizationCallbackResult $result
            ) {
            }

            public function authorize(string $authUrl, string $state): string|AuthorizationCallbackResult
            {
                return $this->result;
            }

            public function getRedirectUri(): string
            {
                return 'http://127.0.0.1/callback';
            }
        };
    }

    private function invokeAuthorizationFlow(OAuthClient $client): void
    {
        $method = new ReflectionMethod(OAuthClient::class, 'performAuthorizationFlow');
        $method->setAccessible(true);
        $method->invoke(
            $client,
            self::RESOURCE_URL,
            new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::ISSUER]
            ),
            $this->makeAsMetadata(true),
            []
        );
    }

    // -- Validation matrix -------------------------------------------------

    /**
     * Advertised + matching iss: validation passes.
     */
    public function testAdvertisedWithMatchingIssPasses(): void
    {
        $this->invokeValidate($this->makeClient(), self::ISSUER, true);
        $this->addToAssertionCount(1);
    }

    /**
     * Advertised + missing iss: abort before any token request.
     */
    public function testAdvertisedWithMissingIssThrows(): void
    {
        try {
            $this->invokeValidate($this->makeClient(), null, true);
            $this->fail('Expected OAuthException for missing iss');
        } catch (OAuthException $e) {
            $this->assertSame(OAuthException::REASON_ISS_VALIDATION_FAILED, $e->getReasonCode());
        }
    }

    /**
     * Advertised + mismatching iss: abort.
     */
    public function testAdvertisedWithMismatchingIssThrows(): void
    {
        try {
            $this->invokeValidate($this->makeClient(), 'https://attacker.example.com', true);
            $this->fail('Expected OAuthException for iss mismatch');
        } catch (OAuthException $e) {
            $this->assertSame(OAuthException::REASON_ISS_VALIDATION_FAILED, $e->getReasonCode());
        }
    }

    /**
     * Not advertised + iss present and matching: passes (MCP extension still
     * compares when the parameter is present).
     */
    public function testUnadvertisedWithMatchingIssPasses(): void
    {
        $this->invokeValidate($this->makeClient(), self::ISSUER, null);
        $this->addToAssertionCount(1);
    }

    /**
     * Not advertised + iss present but mismatching: abort (MCP extension of
     * RFC 9207).
     */
    public function testUnadvertisedWithMismatchingIssThrows(): void
    {
        $this->expectException(OAuthException::class);
        $this->invokeValidate($this->makeClient(), 'https://attacker.example.com', null);
    }

    /**
     * Not advertised + iss absent: proceed.
     */
    public function testUnadvertisedWithAbsentIssPasses(): void
    {
        $this->invokeValidate($this->makeClient(), null, null);
        $this->invokeValidate($this->makeClient(), null, false);
        $this->addToAssertionCount(1);
    }

    /**
     * The comparison is byte-for-byte: a trailing slash that URL
     * normalization would erase MUST be treated as a mismatch
     * (auth/iss-normalized conformance scenario).
     */
    public function testTrailingSlashIsMismatch(): void
    {
        $this->expectException(OAuthException::class);
        $this->invokeValidate($this->makeClient(), self::ISSUER . '/', true);
    }

    /**
     * The comparison is byte-for-byte: host case folding must not be applied.
     */
    public function testHostCaseFoldingIsNotApplied(): void
    {
        $this->expectException(OAuthException::class);
        $this->invokeValidate($this->makeClient(), 'https://AS.example.com', true);
    }

    /**
     * The comparison is byte-for-byte: default-port elision must not be applied.
     */
    public function testDefaultPortElisionIsNotApplied(): void
    {
        $this->expectException(OAuthException::class);
        $this->invokeValidate($this->makeClient(), 'https://as.example.com:443', true);
    }

    // -- iss-before-error ordering ------------------------------------------

    /**
     * When the callback carries BOTH an OAuth error and a mismatching iss,
     * the iss failure must win: the error parameters from the unvalidated
     * response must not be surfaced.
     */
    public function testIssCheckedBeforeErrorParameters(): void
    {
        $callback = $this->makeCallback(new AuthorizationCallbackResult(
            code: null,
            iss: 'https://attacker.example.com',
            params: [
                'error' => 'access_denied',
                'error_description' => 'spoofed error from wrong issuer',
                'state' => 'whatever',
                'iss' => 'https://attacker.example.com',
            ]
        ));

        try {
            $this->invokeAuthorizationFlow($this->makeClient($callback));
            $this->fail('Expected OAuthException');
        } catch (OAuthException $e) {
            $this->assertSame(
                OAuthException::REASON_ISS_VALIDATION_FAILED,
                $e->getReasonCode(),
                'The iss mismatch must be reported, not the spoofed OAuth error'
            );
            $this->assertNull(
                $e->getOAuthError(),
                'Error parameters from a response failing iss validation must not be surfaced'
            );
            $this->assertStringNotContainsString('spoofed error', $e->getMessage());
        }
    }

    /**
     * When iss validates, error parameters ARE processed and surfaced.
     */
    public function testErrorParametersSurfacedAfterValidIss(): void
    {
        $callback = $this->makeCallback(new AuthorizationCallbackResult(
            code: null,
            iss: self::ISSUER,
            params: [
                'error' => 'access_denied',
                'error_description' => 'user said no',
                'iss' => self::ISSUER,
            ]
        ));

        try {
            $this->invokeAuthorizationFlow($this->makeClient($callback));
            $this->fail('Expected OAuthException');
        } catch (OAuthException $e) {
            $this->assertSame('access_denied', $e->getOAuthError());
            $this->assertSame('user said no', $e->getOAuthErrorDescription());
        }
    }

    /**
     * A legacy string return from a third-party callback handler is treated
     * as a code with no iss parameter — which the advertised-support rule
     * then rejects.
     */
    public function testLegacyStringReturnTreatedAsMissingIss(): void
    {
        $callback = $this->makeCallback('legacy-code-123');

        try {
            $this->invokeAuthorizationFlow($this->makeClient($callback));
            $this->fail('Expected OAuthException');
        } catch (OAuthException $e) {
            $this->assertSame(OAuthException::REASON_ISS_VALIDATION_FAILED, $e->getReasonCode());
        }
    }

    /**
     * A callback result with neither error nor code fails with a clear
     * message once iss validation has passed.
     */
    public function testMissingCodeAfterValidIssThrows(): void
    {
        $callback = $this->makeCallback(new AuthorizationCallbackResult(
            code: null,
            iss: self::ISSUER,
            params: ['iss' => self::ISSUER]
        ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Authorization code not found');

        $this->invokeAuthorizationFlow($this->makeClient($callback));
    }

    // -- Web flow (exchangeCodeForTokens) ------------------------------------

    private function makeAuthorizationRequest(?bool $issSupported): AuthorizationRequest
    {
        return new AuthorizationRequest(
            authorizationUrl: self::ISSUER . '/authorize?x=y',
            state: 'state-1',
            codeVerifier: 'verifier-1',
            redirectUri: 'https://app.example.com/callback',
            resourceUrl: self::RESOURCE_URL,
            resource: self::RESOURCE_URL,
            tokenEndpoint: self::ISSUER . '/token',
            issuer: self::ISSUER,
            clientId: 'web-client',
            clientSecret: null,
            tokenEndpointAuthMethod: ClientCredentials::AUTH_METHOD_NONE,
            issParameterSupported: $issSupported
        );
    }

    /**
     * Web flow: a mismatching iss aborts the code exchange before any token
     * request is sent.
     */
    public function testExchangeCodeForTokensRejectsIssMismatch(): void
    {
        $client = $this->makeClient();

        try {
            $client->exchangeCodeForTokens(
                $this->makeAuthorizationRequest(null),
                'auth-code',
                'https://attacker.example.com'
            );
            $this->fail('Expected OAuthException');
        } catch (OAuthException $e) {
            $this->assertSame(OAuthException::REASON_ISS_VALIDATION_FAILED, $e->getReasonCode());
        }
    }

    /**
     * Web flow: when the AS advertised iss support, a callback without iss
     * aborts the exchange.
     */
    public function testExchangeCodeForTokensRejectsMissingIssWhenAdvertised(): void
    {
        $client = $this->makeClient();

        try {
            $client->exchangeCodeForTokens(
                $this->makeAuthorizationRequest(true),
                'auth-code',
                null
            );
            $this->fail('Expected OAuthException');
        } catch (OAuthException $e) {
            $this->assertSame(OAuthException::REASON_ISS_VALIDATION_FAILED, $e->getReasonCode());
        }
    }

    /**
     * AuthorizationRequest round-trips the issParameterSupported flag through
     * toArray()/fromArray() (session persistence in web flows).
     */
    public function testAuthorizationRequestRoundTripsIssParameterSupported(): void
    {
        $request = $this->makeAuthorizationRequest(true);
        $restored = AuthorizationRequest::fromArray($request->toArray());
        $this->assertTrue($restored->issParameterSupported);

        // Old serialized payloads without the field default to null.
        $data = $request->toArray();
        unset($data['issParameterSupported']);
        $legacy = AuthorizationRequest::fromArray($data);
        $this->assertNull($legacy->issParameterSupported);
    }

    /**
     * AuthorizationServerMetadata parses and serializes
     * authorization_response_iss_parameter_supported.
     */
    public function testAsMetadataParsesIssParameterFlag(): void
    {
        $metadata = AuthorizationServerMetadata::fromArray([
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER . '/authorize',
            'token_endpoint' => self::ISSUER . '/token',
            'authorization_response_iss_parameter_supported' => true,
        ]);

        $this->assertTrue($metadata->authorizationResponseIssParameterSupported);
        $this->assertTrue($metadata->toArray()['authorization_response_iss_parameter_supported']);

        $absent = AuthorizationServerMetadata::fromArray([
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER . '/authorize',
            'token_endpoint' => self::ISSUER . '/token',
        ]);

        $this->assertNull($absent->authorizationResponseIssParameterSupported);
        $this->assertArrayNotHasKey(
            'authorization_response_iss_parameter_supported',
            $absent->toArray()
        );
    }
}
