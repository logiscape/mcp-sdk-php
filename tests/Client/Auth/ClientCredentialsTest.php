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
 * Filename: tests/Client/Auth/ClientCredentialsTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client\Auth;

use Mcp\Client\Auth\Registration\ClientCredentials;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ClientCredentials class.
 *
 * Validates client credential handling for different authentication methods.
 */
final class ClientCredentialsTest extends TestCase
{
    /**
     * Test public client (no secret).
     */
    public function testPublicClient(): void
    {
        $credentials = new ClientCredentials(
            clientId: 'my-client'
        );

        $this->assertSame('my-client', $credentials->clientId);
        $this->assertNull($credentials->clientSecret);
        $this->assertTrue($credentials->isPublicClient());
    }

    /**
     * The issuer binding (Authorization Server Binding rule) is optional
     * and defaults to unbound; when set it is retained verbatim.
     */
    public function testIssuerBindingDefaultsToNullAndIsRetained(): void
    {
        $unbound = new ClientCredentials(clientId: 'my-client');
        $this->assertNull($unbound->issuer);

        $bound = new ClientCredentials(
            clientId: 'my-client',
            clientSecret: 'my-secret',
            tokenEndpointAuthMethod: ClientCredentials::AUTH_METHOD_CLIENT_SECRET_BASIC,
            issuer: 'https://auth.example.com'
        );
        $this->assertSame('https://auth.example.com', $bound->issuer);
    }

    /**
     * Test confidential client with client_secret_post.
     */
    public function testConfidentialClientSecretPost(): void
    {
        $credentials = new ClientCredentials(
            clientId: 'my-client',
            clientSecret: 'my-secret',
            tokenEndpointAuthMethod: ClientCredentials::AUTH_METHOD_CLIENT_SECRET_POST
        );

        $this->assertSame('my-client', $credentials->clientId);
        $this->assertSame('my-secret', $credentials->clientSecret);
        $this->assertFalse($credentials->isPublicClient());
        $this->assertSame(ClientCredentials::AUTH_METHOD_CLIENT_SECRET_POST, $credentials->tokenEndpointAuthMethod);
    }

    /**
     * Test confidential client with client_secret_basic.
     */
    public function testConfidentialClientSecretBasic(): void
    {
        $credentials = new ClientCredentials(
            clientId: 'my-client',
            clientSecret: 'my-secret',
            tokenEndpointAuthMethod: ClientCredentials::AUTH_METHOD_CLIENT_SECRET_BASIC
        );

        $this->assertFalse($credentials->isPublicClient());
        $this->assertSame(ClientCredentials::AUTH_METHOD_CLIENT_SECRET_BASIC, $credentials->tokenEndpointAuthMethod);
    }

    /**
     * Test isPublicClient with auth method 'none'.
     */
    public function testIsPublicClientWithAuthMethodNone(): void
    {
        $credentials = new ClientCredentials(
            clientId: 'my-client',
            clientSecret: 'my-secret',
            tokenEndpointAuthMethod: ClientCredentials::AUTH_METHOD_NONE
        );

        $this->assertTrue($credentials->isPublicClient());
    }

    /**
     * Test getTokenRequestParams for public client.
     */
    public function testGetTokenRequestParamsPublicClient(): void
    {
        $credentials = new ClientCredentials(
            clientId: 'my-client'
        );

        $params = $credentials->getTokenRequestParams();

        $this->assertSame(['client_id' => 'my-client'], $params);
    }

    /**
     * Test getTokenRequestParams for client_secret_post.
     */
    public function testGetTokenRequestParamsClientSecretPost(): void
    {
        $credentials = new ClientCredentials(
            clientId: 'my-client',
            clientSecret: 'my-secret',
            tokenEndpointAuthMethod: ClientCredentials::AUTH_METHOD_CLIENT_SECRET_POST
        );

        $params = $credentials->getTokenRequestParams();

        $this->assertSame([
            'client_id' => 'my-client',
            'client_secret' => 'my-secret',
        ], $params);
    }

    /**
     * Test getTokenRequestParams for client_secret_basic (no secret in params).
     */
    public function testGetTokenRequestParamsClientSecretBasic(): void
    {
        $credentials = new ClientCredentials(
            clientId: 'my-client',
            clientSecret: 'my-secret',
            tokenEndpointAuthMethod: ClientCredentials::AUTH_METHOD_CLIENT_SECRET_BASIC
        );

        $params = $credentials->getTokenRequestParams();

        // client_secret_basic puts credentials in Authorization header, not body
        $this->assertSame(['client_id' => 'my-client'], $params);
    }

    /**
     * Test getAuthorizationHeader for client_secret_basic.
     */
    public function testGetAuthorizationHeaderClientSecretBasic(): void
    {
        $credentials = new ClientCredentials(
            clientId: 'my-client',
            clientSecret: 'my-secret',
            tokenEndpointAuthMethod: ClientCredentials::AUTH_METHOD_CLIENT_SECRET_BASIC
        );

        $header = $credentials->getAuthorizationHeader();

        // Expected: Basic base64(urlencode(client_id):urlencode(client_secret))
        $expected = 'Basic ' . base64_encode('my-client:my-secret');
        $this->assertSame($expected, $header);
    }

    /**
     * Test getAuthorizationHeader with special characters.
     */
    public function testGetAuthorizationHeaderWithSpecialChars(): void
    {
        $credentials = new ClientCredentials(
            clientId: 'my:client',
            clientSecret: 'my@secret',
            tokenEndpointAuthMethod: ClientCredentials::AUTH_METHOD_CLIENT_SECRET_BASIC
        );

        $header = $credentials->getAuthorizationHeader();

        // Special characters should be URL-encoded
        $expected = 'Basic ' . base64_encode(urlencode('my:client') . ':' . urlencode('my@secret'));
        $this->assertSame($expected, $header);
    }

    /**
     * Test getAuthorizationHeader returns null for non-basic auth.
     */
    public function testGetAuthorizationHeaderReturnsNullForNonBasic(): void
    {
        $credentials = new ClientCredentials(
            clientId: 'my-client',
            clientSecret: 'my-secret',
            tokenEndpointAuthMethod: ClientCredentials::AUTH_METHOD_CLIENT_SECRET_POST
        );

        $this->assertNull($credentials->getAuthorizationHeader());

        $publicCredentials = new ClientCredentials(
            clientId: 'my-client'
        );

        $this->assertNull($publicCredentials->getAuthorizationHeader());
    }

    /**
     * Test default auth method is client_secret_post.
     */
    public function testDefaultAuthMethod(): void
    {
        $credentials = new ClientCredentials(
            clientId: 'my-client',
            clientSecret: 'my-secret'
        );

        $this->assertSame(ClientCredentials::AUTH_METHOD_CLIENT_SECRET_POST, $credentials->tokenEndpointAuthMethod);
    }
}
