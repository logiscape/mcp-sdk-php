<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2025 Logiscape LLC <https://logiscape.com>
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
 * Filename: tests/Client/Auth/OAuthConfigurationTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client\Auth;

use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\Registration\ClientCredentials;
use Mcp\Client\Auth\Token\MemoryTokenStorage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for OAuthConfiguration class.
 *
 * Validates OAuth configuration creation and option handling.
 */
final class OAuthConfigurationTest extends TestCase
{
    /**
     * Test default configuration values.
     */
    public function testDefaultConfiguration(): void
    {
        $config = new OAuthConfiguration();

        $this->assertNull($config->getClientCredentials());
        $this->assertInstanceOf(MemoryTokenStorage::class, $config->getTokenStorage());
        $this->assertNull($config->getAuthCallback());
        $this->assertTrue($config->isCimdEnabled());
        $this->assertTrue($config->isDynamicRegistrationEnabled());
        $this->assertNull($config->getCimdUrl());
        $this->assertSame([], $config->getAdditionalScopes());
        $this->assertSame(30.0, $config->getTimeout());
        $this->assertTrue($config->isAutoRefreshEnabled());
        $this->assertSame(60, $config->getRefreshBuffer());
        $this->assertNull($config->getRedirectUri());
    }

    /**
     * Test configuration with pre-registered credentials.
     */
    public function testConfigurationWithClientCredentials(): void
    {
        $credentials = new ClientCredentials(
            clientId: 'my-client',
            clientSecret: 'my-secret'
        );

        $config = new OAuthConfiguration(clientCredentials: $credentials);

        $this->assertTrue($config->hasClientCredentials());
        $this->assertSame($credentials, $config->getClientCredentials());
        $this->assertSame('my-client', $config->getClientCredentials()->clientId);
    }

    /**
     * Test configuration with CIMD.
     */
    public function testConfigurationWithCimd(): void
    {
        $config = new OAuthConfiguration(
            enableCimd: true,
            cimdUrl: 'https://my-app.com/oauth/client.json'
        );

        $this->assertTrue($config->isCimdEnabled());
        $this->assertTrue($config->hasCimd());
        $this->assertSame('https://my-app.com/oauth/client.json', $config->getCimdUrl());
    }

    /**
     * Test hasCimd returns false when CIMD is disabled.
     */
    public function testHasCimdWhenDisabled(): void
    {
        $config = new OAuthConfiguration(
            enableCimd: false,
            cimdUrl: 'https://my-app.com/oauth/client.json'
        );

        $this->assertFalse($config->hasCimd());
    }

    /**
     * Test hasCimd returns false when no URL provided.
     */
    public function testHasCimdWithoutUrl(): void
    {
        $config = new OAuthConfiguration(enableCimd: true);

        $this->assertFalse($config->hasCimd());
    }

    /**
     * Test configuration with custom token storage.
     */
    public function testConfigurationWithCustomStorage(): void
    {
        $storage = new MemoryTokenStorage();
        $config = new OAuthConfiguration(tokenStorage: $storage);

        $this->assertSame($storage, $config->getTokenStorage());
    }

    /**
     * Test configuration with additional scopes.
     */
    public function testConfigurationWithAdditionalScopes(): void
    {
        $config = new OAuthConfiguration(
            additionalScopes: ['offline_access', 'profile']
        );

        $this->assertSame(['offline_access', 'profile'], $config->getAdditionalScopes());
    }

    /**
     * Test configuration with custom timeout.
     */
    public function testConfigurationWithCustomTimeout(): void
    {
        $config = new OAuthConfiguration(timeout: 60.0);

        $this->assertSame(60.0, $config->getTimeout());
    }

    /**
     * Test configuration with auto-refresh disabled.
     */
    public function testConfigurationWithAutoRefreshDisabled(): void
    {
        $config = new OAuthConfiguration(autoRefresh: false);

        $this->assertFalse($config->isAutoRefreshEnabled());
    }

    /**
     * Test configuration with custom refresh buffer.
     */
    public function testConfigurationWithCustomRefreshBuffer(): void
    {
        $config = new OAuthConfiguration(refreshBuffer: 300);

        $this->assertSame(300, $config->getRefreshBuffer());
    }

    /**
     * Test configuration with redirect URI override.
     */
    public function testConfigurationWithRedirectUri(): void
    {
        $config = new OAuthConfiguration(
            redirectUri: 'https://my-app.com/oauth/callback'
        );

        $this->assertSame('https://my-app.com/oauth/callback', $config->getRedirectUri());
    }

    /**
     * Test hasClientCredentials returns false when none provided.
     */
    public function testHasClientCredentialsWhenNone(): void
    {
        $config = new OAuthConfiguration();

        $this->assertFalse($config->hasClientCredentials());
    }

    /**
     * Test that authorizationServerUrl defaults to null.
     */
    public function testDefaultAuthorizationServerUrl(): void
    {
        $config = new OAuthConfiguration();

        $this->assertNull($config->getAuthorizationServerUrl());
        $this->assertFalse($config->hasAuthorizationServer());
    }

    /**
     * Test configuration with explicit authorization server URL.
     */
    public function testConfigurationWithAuthorizationServerUrl(): void
    {
        $config = new OAuthConfiguration(
            authorizationServerUrl: 'https://auth.example.com'
        );

        $this->assertSame('https://auth.example.com', $config->getAuthorizationServerUrl());
        $this->assertTrue($config->hasAuthorizationServer());
    }
}
