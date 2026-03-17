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
 * Filename: tests/Client/Auth/OAuthClientDiscoverResourceMetadataTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client\Auth;

use Mcp\Client\Auth\Discovery\MetadataDiscovery;
use Mcp\Client\Auth\Discovery\ProtectedResourceMetadata;
use Mcp\Client\Auth\OAuthClient;
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\OAuthException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Tests for OAuthClient authorization server resolution fallback behavior.
 *
 * Validates that when RFC 9728 protected resource metadata discovery fails
 * or returns metadata without authorization_servers, the client falls back
 * to a configured authorization server URL, and that fallback results are
 * not cached so transient failures can recover.
 */
final class OAuthClientDiscoverResourceMetadataTest extends TestCase
{
    private const RESOURCE_URL = 'https://api.example.com/mcp';
    private const AUTH_SERVER_URL = 'https://auth.example.com';

    /**
     * Helper: create an OAuthClient with a mock MetadataDiscovery injected.
     *
     * @return array{OAuthClient, MetadataDiscovery&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function createClientWithMockDiscovery(?string $authorizationServerUrl = null): array
    {
        $config = new OAuthConfiguration(
            authorizationServerUrl: $authorizationServerUrl,
        );

        $client = new OAuthClient($config);

        $mockDiscovery = $this->createMock(MetadataDiscovery::class);

        $ref = new ReflectionProperty(OAuthClient::class, 'discovery');
        $ref->setAccessible(true);
        $ref->setValue($client, $mockDiscovery);

        return [$client, $mockDiscovery];
    }

    /**
     * Helper: invoke the private discoverResourceMetadata method.
     */
    private function invokeDiscoverResourceMetadata(
        OAuthClient $client,
        string $resourceUrl,
        ?string $metadataUrl = null
    ): ProtectedResourceMetadata {
        $method = new ReflectionMethod(OAuthClient::class, 'discoverResourceMetadata');
        $method->setAccessible(true);
        return $method->invoke($client, $resourceUrl, $metadataUrl);
    }

    /**
     * Helper: invoke the private resolveAuthorizationServer method.
     */
    private function invokeResolveAuthorizationServer(
        OAuthClient $client,
        ProtectedResourceMetadata $resourceMetadata
    ): string {
        $method = new ReflectionMethod(OAuthClient::class, 'resolveAuthorizationServer');
        $method->setAccessible(true);
        return $method->invoke($client, $resourceMetadata);
    }

    /**
     * Test that successful discovery returns and caches real metadata.
     */
    public function testSuccessfulDiscoveryReturnsCachedMetadata(): void
    {
        [$client, $mockDiscovery] = $this->createClientWithMockDiscovery(self::AUTH_SERVER_URL);

        $realMetadata = new ProtectedResourceMetadata(
            resource: self::RESOURCE_URL,
            authorizationServers: ['https://real-auth.example.com'],
        );

        // Discovery should only be called once; second call uses cache
        $mockDiscovery->expects($this->once())
            ->method('discoverResourceMetadata')
            ->willReturn($realMetadata);

        $result1 = $this->invokeDiscoverResourceMetadata($client, self::RESOURCE_URL);
        $result2 = $this->invokeDiscoverResourceMetadata($client, self::RESOURCE_URL);

        $this->assertSame($realMetadata, $result1);
        $this->assertSame($realMetadata, $result2);
    }

    /**
     * Test that when discovery fails and authorizationServerUrl is configured,
     * a synthetic ProtectedResourceMetadata is returned with the configured URL.
     */
    public function testFallbackToConfiguredAuthorizationServer(): void
    {
        [$client, $mockDiscovery] = $this->createClientWithMockDiscovery(self::AUTH_SERVER_URL);

        $mockDiscovery->method('discoverResourceMetadata')
            ->willThrowException(OAuthException::discoveryFailed('resource metadata', 'Not found'));

        $result = $this->invokeDiscoverResourceMetadata($client, self::RESOURCE_URL);

        $this->assertSame(self::RESOURCE_URL, $result->resource);
        $this->assertSame([self::AUTH_SERVER_URL], $result->authorizationServers);
    }

    /**
     * Test that when discovery fails and no authorizationServerUrl is configured,
     * the OAuthException is rethrown (preserving current behavior).
     */
    public function testDiscoveryFailureWithoutFallbackThrows(): void
    {
        [$client, $mockDiscovery] = $this->createClientWithMockDiscovery(null);

        $mockDiscovery->method('discoverResourceMetadata')
            ->willThrowException(OAuthException::discoveryFailed('resource metadata', 'Not found'));

        $this->expectException(OAuthException::class);

        $this->invokeDiscoverResourceMetadata($client, self::RESOURCE_URL);
    }

    /**
     * Test that fallback metadata is NOT cached, so discovery is retried
     * on subsequent calls (transient failure recovery).
     */
    public function testFallbackMetadataIsNotCached(): void
    {
        [$client, $mockDiscovery] = $this->createClientWithMockDiscovery(self::AUTH_SERVER_URL);

        $realMetadata = new ProtectedResourceMetadata(
            resource: self::RESOURCE_URL,
            authorizationServers: ['https://real-auth.example.com'],
        );

        // First call: discovery fails, fallback used
        // Second call: discovery succeeds, real metadata returned
        $mockDiscovery->expects($this->exactly(2))
            ->method('discoverResourceMetadata')
            ->willReturnCallback(function () use (&$callCount, $realMetadata): ProtectedResourceMetadata {
                $callCount = ($callCount ?? 0) + 1;
                if ($callCount === 1) {
                    throw OAuthException::discoveryFailed('resource metadata', 'Not found');
                }
                return $realMetadata;
            });

        // First call: gets fallback
        $result1 = $this->invokeDiscoverResourceMetadata($client, self::RESOURCE_URL);
        $this->assertSame([self::AUTH_SERVER_URL], $result1->authorizationServers);

        // Second call: discovery retried and succeeds
        $result2 = $this->invokeDiscoverResourceMetadata($client, self::RESOURCE_URL);
        $this->assertSame($realMetadata, $result2);
    }

    /**
     * Test that resolveAuthorizationServer returns the URL from metadata
     * when authorization_servers is populated.
     */
    public function testResolveAuthorizationServerFromMetadata(): void
    {
        [$client] = $this->createClientWithMockDiscovery(self::AUTH_SERVER_URL);

        $metadata = new ProtectedResourceMetadata(
            resource: self::RESOURCE_URL,
            authorizationServers: ['https://discovered-auth.example.com'],
        );

        $result = $this->invokeResolveAuthorizationServer($client, $metadata);

        $this->assertSame('https://discovered-auth.example.com', $result);
    }

    /**
     * Test that resolveAuthorizationServer falls back to configured URL
     * when metadata has an empty authorization_servers list.
     */
    public function testResolveAuthorizationServerFallbackOnEmptyList(): void
    {
        [$client] = $this->createClientWithMockDiscovery(self::AUTH_SERVER_URL);

        $metadata = new ProtectedResourceMetadata(
            resource: self::RESOURCE_URL,
            authorizationServers: [],
        );

        $result = $this->invokeResolveAuthorizationServer($client, $metadata);

        $this->assertSame(self::AUTH_SERVER_URL, $result);
    }

    /**
     * Test that resolveAuthorizationServer throws when metadata has no
     * authorization servers and no fallback is configured.
     */
    public function testResolveAuthorizationServerThrowsWithoutFallback(): void
    {
        [$client] = $this->createClientWithMockDiscovery(null);

        $metadata = new ProtectedResourceMetadata(
            resource: self::RESOURCE_URL,
            authorizationServers: [],
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('No authorization server found');

        $this->invokeResolveAuthorizationServer($client, $metadata);
    }
}
