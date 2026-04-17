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
 * Filename: tests/Client/Auth/OAuthClientLegacyFallbackTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client\Auth;

use Mcp\Client\Auth\Discovery\AuthorizationServerMetadata;
use Mcp\Client\Auth\Discovery\MetadataDiscovery;
use Mcp\Client\Auth\Discovery\ProtectedResourceMetadata;
use Mcp\Client\Auth\OAuthClient;
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\OAuthException;
use Mcp\Client\Auth\Token\TokenSet;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Tests for the narrowed MCP 2025-03-26 legacy OAuth fallback in OAuthClient.
 *
 * Validates that:
 *   - The flag relaxes issuer validation and permits endpoint synthesis ONLY
 *     for AS URLs derived from the server-root fallback in discoverResourceMetadata().
 *   - AS URLs that came from PRM or from OAuthConfiguration::authorizationServerUrl
 *     are always validated strictly per RFC 8414 and never get synthesized endpoints,
 *     even when enableLegacyOAuthFallback is true.
 *   - With the flag off, discovery throws on failure (unchanged behavior).
 */
final class OAuthClientLegacyFallbackTest extends TestCase
{
    private const RESOURCE_URL = 'https://api.example.com/mcp';
    private const DERIVED_AS_URL = 'https://api.example.com';

    /**
     * @return array{OAuthClient, MetadataDiscovery&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function createClient(
        bool $enableLegacyFallback,
        ?string $authorizationServerUrl = null
    ): array {
        $config = new OAuthConfiguration(
            authorizationServerUrl: $authorizationServerUrl,
            enableLegacyOAuthFallback: $enableLegacyFallback,
        );

        $client = new OAuthClient($config);

        $mockDiscovery = $this->createMock(MetadataDiscovery::class);
        $ref = new ReflectionProperty(OAuthClient::class, 'discovery');
        $ref->setAccessible(true);
        $ref->setValue($client, $mockDiscovery);

        return [$client, $mockDiscovery];
    }

    private function invokeDiscoverResourceMetadata(OAuthClient $client, string $resourceUrl): ProtectedResourceMetadata
    {
        $method = new ReflectionMethod(OAuthClient::class, 'discoverResourceMetadata');
        $method->setAccessible(true);
        return $method->invoke($client, $resourceUrl, null);
    }

    private function invokeDiscoverAuthorizationServerMetadata(
        OAuthClient $client,
        string $authServerUrl
    ): AuthorizationServerMetadata {
        $method = new ReflectionMethod(OAuthClient::class, 'discoverAuthorizationServerMetadata');
        $method->setAccessible(true);
        return $method->invoke($client, $authServerUrl);
    }

    private function invokeDiscoverAuthorizationServerMetadataForRefresh(
        OAuthClient $client,
        TokenSet $tokens
    ): AuthorizationServerMetadata {
        $method = new ReflectionMethod(OAuthClient::class, 'discoverAuthorizationServerMetadataForRefresh');
        $method->setAccessible(true);
        return $method->invoke($client, $tokens);
    }

    /**
     * Legacy fallback ON + PRM fails → synthetic PRM is returned pointing at the
     * server-root AS URL, and that URL is marked as legacy-derived.
     */
    public function testPrmFailureWithLegacyFlagSynthesizesDerivedAs(): void
    {
        [$client, $mockDiscovery] = $this->createClient(enableLegacyFallback: true);

        $mockDiscovery->method('discoverResourceMetadata')
            ->willThrowException(OAuthException::discoveryFailed('prm', 'Not found'));

        $prm = $this->invokeDiscoverResourceMetadata($client, self::RESOURCE_URL);

        $this->assertSame(self::RESOURCE_URL, $prm->resource);
        $this->assertSame([self::DERIVED_AS_URL], $prm->authorizationServers);

        $legacyDerived = new ReflectionProperty(OAuthClient::class, 'legacyDerivedAuthServers');
        $legacyDerived->setAccessible(true);
        $this->assertSame(
            [self::DERIVED_AS_URL => true],
            $legacyDerived->getValue($client),
            'The derived AS URL must be marked so only it receives relaxed validation.'
        );
    }

    /**
     * Legacy fallback ON + explicit authorizationServerUrl takes precedence over
     * legacy derivation, and the AS URL is NOT marked as legacy-derived.
     */
    public function testAuthorizationServerUrlTakesPrecedenceOverLegacyFallback(): void
    {
        [$client, $mockDiscovery] = $this->createClient(
            enableLegacyFallback: true,
            authorizationServerUrl: 'https://configured.example.com',
        );

        $mockDiscovery->method('discoverResourceMetadata')
            ->willThrowException(OAuthException::discoveryFailed('prm', 'Not found'));

        $prm = $this->invokeDiscoverResourceMetadata($client, self::RESOURCE_URL);

        $this->assertSame(['https://configured.example.com'], $prm->authorizationServers);

        $legacyDerived = new ReflectionProperty(OAuthClient::class, 'legacyDerivedAuthServers');
        $legacyDerived->setAccessible(true);
        $this->assertSame(
            [],
            $legacyDerived->getValue($client),
            'Configured authorization server URLs must not be treated as legacy-derived.'
        );
    }

    /**
     * AS discovery against a legacy-derived URL dispatches to the relaxed
     * discovery method. Regression guard for the spec-relaxed path.
     */
    public function testLegacyDerivedAsUsesRelaxedDiscoveryMethod(): void
    {
        [$client, $mockDiscovery] = $this->createClient(enableLegacyFallback: true);

        // Mark the AS URL as legacy-derived (simulating the PRM-failed path).
        $legacyDerived = new ReflectionProperty(OAuthClient::class, 'legacyDerivedAuthServers');
        $legacyDerived->setAccessible(true);
        $legacyDerived->setValue($client, [self::DERIVED_AS_URL => true]);

        $relaxed = $this->makeMetadata(issuer: self::DERIVED_AS_URL . '/oauth');
        $mockDiscovery->expects($this->once())
            ->method('discoverAuthorizationServerMetadataWithoutIssuerMatch')
            ->with(self::DERIVED_AS_URL)
            ->willReturn($relaxed);
        $mockDiscovery->expects($this->never())
            ->method('discoverAuthorizationServerMetadata');

        $this->invokeDiscoverAuthorizationServerMetadata($client, self::DERIVED_AS_URL);
    }

    /**
     * AS discovery against a PRM-sourced URL (not legacy-derived) must still
     * use the strict discovery method even when the flag is on.
     */
    public function testNonLegacyDerivedAsUsesStrictDiscoveryMethod(): void
    {
        [$client, $mockDiscovery] = $this->createClient(enableLegacyFallback: true);

        $strict = $this->makeMetadata(issuer: 'https://strict.example.com');
        $mockDiscovery->expects($this->once())
            ->method('discoverAuthorizationServerMetadata')
            ->with('https://strict.example.com')
            ->willReturn($strict);
        $mockDiscovery->expects($this->never())
            ->method('discoverAuthorizationServerMetadataWithoutIssuerMatch');

        $this->invokeDiscoverAuthorizationServerMetadata($client, 'https://strict.example.com');
    }

    /**
     * Legacy fallback ON + PRM discovery succeeds + AS discovery then fails:
     * the AS URL came from PRM, NOT from legacy derivation, so failure must
     * throw instead of synthesizing /authorize, /token, /register.
     */
    public function testPrmSuccessAsFailureDoesNotSynthesizeEvenWithFlag(): void
    {
        [$client, $mockDiscovery] = $this->createClient(enableLegacyFallback: true);

        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willThrowException(OAuthException::discoveryFailed('as', 'Not found'));

        $this->expectException(OAuthException::class);
        $this->invokeDiscoverAuthorizationServerMetadata($client, 'https://prm-sourced.example.com');
    }

    /**
     * Legacy fallback OFF: PRM failure always throws (unchanged behavior).
     */
    public function testFlagOffPrmFailureThrows(): void
    {
        [$client, $mockDiscovery] = $this->createClient(enableLegacyFallback: false);

        $mockDiscovery->method('discoverResourceMetadata')
            ->willThrowException(OAuthException::discoveryFailed('prm', 'Not found'));

        $this->expectException(OAuthException::class);
        $this->invokeDiscoverResourceMetadata($client, self::RESOURCE_URL);
    }

    /**
     * Legacy synthesis only activates for legacy-derived URLs: feed a derived
     * URL into AS discovery, let it fail, and verify the synthesized metadata
     * points at /authorize, /token, /register under the derived base.
     */
    public function testLegacyDerivedAsSynthesizesDefaultEndpointsOnFailure(): void
    {
        [$client, $mockDiscovery] = $this->createClient(enableLegacyFallback: true);

        $legacyDerived = new ReflectionProperty(OAuthClient::class, 'legacyDerivedAuthServers');
        $legacyDerived->setAccessible(true);
        $legacyDerived->setValue($client, [self::DERIVED_AS_URL => true]);

        $mockDiscovery->method('discoverAuthorizationServerMetadataWithoutIssuerMatch')
            ->willThrowException(OAuthException::discoveryFailed('as', 'Not found'));

        $metadata = $this->invokeDiscoverAuthorizationServerMetadata($client, self::DERIVED_AS_URL);

        $this->assertSame(self::DERIVED_AS_URL . '/authorize', $metadata->authorizationEndpoint);
        $this->assertSame(self::DERIVED_AS_URL . '/token', $metadata->tokenEndpoint);
        $this->assertSame(self::DERIVED_AS_URL . '/register', $metadata->registrationEndpoint);
        $this->assertTrue($metadata->supportsPkce());
    }

    /**
     * Legacy endpoint synthesis is only for missing metadata. If metadata was
     * found and failed a required validation such as PKCE-S256 support, the
     * client must not invent metadata that lets the flow continue.
     */
    public function testLegacyDerivedAsDoesNotSynthesizeWhenPkceUnsupported(): void
    {
        [$client, $mockDiscovery] = $this->createClient(enableLegacyFallback: true);

        $legacyDerived = new ReflectionProperty(OAuthClient::class, 'legacyDerivedAuthServers');
        $legacyDerived->setAccessible(true);
        $legacyDerived->setValue($client, [self::DERIVED_AS_URL => true]);

        $mockDiscovery->method('discoverAuthorizationServerMetadataWithoutIssuerMatch')
            ->willThrowException(OAuthException::pkceNotSupported());

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('does not support PKCE');

        $this->invokeDiscoverAuthorizationServerMetadata($client, self::DERIVED_AS_URL);
    }

    /**
     * A fresh client has no in-memory legacy marker. Refresh still reconstructs
     * the legacy AS base from the token's resource URL and uses synthesized
     * default endpoints when the legacy server has no AS metadata.
     */
    public function testRefreshMetadataUsesLegacyBaseAfterCacheLossForEndpointFallback(): void
    {
        [$client, $mockDiscovery] = $this->createClient(enableLegacyFallback: true);

        $mockDiscovery->expects($this->once())
            ->method('discoverAuthorizationServerMetadataWithoutIssuerMatch')
            ->with(self::DERIVED_AS_URL)
            ->willThrowException(OAuthException::discoveryFailed('as', 'Not found'));
        $mockDiscovery->expects($this->never())
            ->method('discoverAuthorizationServerMetadata');

        $tokens = new TokenSet(
            accessToken: 'access-token',
            refreshToken: 'refresh-token',
            resourceUrl: self::RESOURCE_URL,
            issuer: self::DERIVED_AS_URL,
            resource: self::RESOURCE_URL
        );

        $metadata = $this->invokeDiscoverAuthorizationServerMetadataForRefresh($client, $tokens);

        $this->assertSame(self::DERIVED_AS_URL . '/token', $metadata->tokenEndpoint);
    }

    /**
     * For 2025-03-26 metadata-backcompat tokens whose issuer includes a path,
     * refresh should probe root-derived legacy metadata, not strict modern
     * path-aware metadata at the issuer URL.
     */
    public function testRefreshMetadataUsesLegacyRootForPathIssuerAfterCacheLoss(): void
    {
        [$client, $mockDiscovery] = $this->createClient(enableLegacyFallback: true);

        $issuer = self::DERIVED_AS_URL . '/oauth';
        $relaxed = $this->makeMetadata(issuer: $issuer);

        $mockDiscovery->expects($this->once())
            ->method('discoverAuthorizationServerMetadataWithoutIssuerMatch')
            ->with(self::DERIVED_AS_URL)
            ->willReturn($relaxed);
        $mockDiscovery->expects($this->never())
            ->method('discoverAuthorizationServerMetadata');

        $tokens = new TokenSet(
            accessToken: 'access-token',
            refreshToken: 'refresh-token',
            resourceUrl: self::RESOURCE_URL,
            issuer: $issuer,
            resource: self::RESOURCE_URL
        );

        $metadata = $this->invokeDiscoverAuthorizationServerMetadataForRefresh($client, $tokens);

        $this->assertSame($issuer, $metadata->issuer);
    }

    /**
     * Self-healing: legacy markers and cached AS metadata for URLs that PRM
     * later blesses must be invalidated, so subsequent AS discovery re-runs
     * under strict RFC 8414 rules. Guards against a transient-failure-then-
     * success sequence leaving a stale legacy mark on a URL.
     */
    public function testSuccessfulPrmInvalidatesLegacyStateForItsAsUrls(): void
    {
        [$client, $mockDiscovery] = $this->createClient(enableLegacyFallback: true);

        // Seed legacy state as if an earlier PRM failure had already triggered
        // the derived-root fallback for this AS URL.
        $legacyMarkers = new ReflectionProperty(OAuthClient::class, 'legacyDerivedAuthServers');
        $legacyMarkers->setAccessible(true);
        $legacyMarkers->setValue($client, [self::DERIVED_AS_URL => true]);

        $asCache = new ReflectionProperty(OAuthClient::class, 'authServerMetadataCache');
        $asCache->setAccessible(true);
        $asCache->setValue($client, [
            self::DERIVED_AS_URL => $this->makeMetadata(issuer: self::DERIVED_AS_URL . '/oauth'),
        ]);

        // Real PRM succeeds and lists the same AS URL.
        $realPrm = new ProtectedResourceMetadata(
            resource: self::RESOURCE_URL,
            authorizationServers: [self::DERIVED_AS_URL],
        );
        $mockDiscovery->method('discoverResourceMetadata')->willReturn($realPrm);

        $this->invokeDiscoverResourceMetadata($client, self::RESOURCE_URL);

        $this->assertArrayNotHasKey(
            self::DERIVED_AS_URL,
            $legacyMarkers->getValue($client),
            'Real PRM success must clear legacy markers for its AS URLs.'
        );
        $this->assertArrayNotHasKey(
            self::DERIVED_AS_URL,
            $asCache->getValue($client),
            'Real PRM success must drop cached legacy-derived AS metadata for its AS URLs.'
        );
    }

    private function makeMetadata(string $issuer): AuthorizationServerMetadata
    {
        return new AuthorizationServerMetadata(
            issuer: $issuer,
            authorizationEndpoint: $issuer . '/authorize',
            tokenEndpoint: $issuer . '/token',
            registrationEndpoint: $issuer . '/register',
            codeChallengeMethodsSupported: ['S256'],
        );
    }
}
