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
 * Filename: tests/Client/Auth/TokenSetExtendedTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client\Auth;

use PHPUnit\Framework\TestCase;
use Mcp\Client\Auth\Token\TokenSet;
use Mcp\Client\Auth\Token\MemoryTokenStorage;
use Mcp\Client\Auth\Pkce\PkceGenerator;

/**
 * Extended tests for auth token components: TokenSet, MemoryTokenStorage, and PkceGenerator.
 *
 * Validates expiration logic, scope checking, authorization header formatting,
 * token response parsing with RFC 6749 scope preservation, in-memory storage
 * with URL normalization, and PKCE code verifier/challenge generation.
 */
final class TokenSetExtendedTest extends TestCase
{
    /**
     * Verify that a TokenSet with an expiresAt timestamp in the past is reported as expired.
     *
     * Creates a token that expired one hour ago and asserts isExpired() returns true.
     */
    public function testTokenSetIsExpired(): void
    {
        $token = new TokenSet(
            accessToken: 'expired-token',
            expiresAt: time() - 3600
        );

        $this->assertTrue($token->isExpired(), 'Token with expiresAt in the past should be expired');
    }

    /**
     * Verify that a TokenSet with null expiresAt is never considered expired.
     *
     * When no expiration timestamp is set, the token should be treated as
     * non-expiring and isExpired() must return false.
     */
    public function testTokenSetIsNotExpiredWhenNull(): void
    {
        $token = new TokenSet(
            accessToken: 'no-expiry-token',
            expiresAt: null
        );

        $this->assertFalse($token->isExpired(), 'Token with null expiresAt should not be expired');
    }

    /**
     * Verify willExpireSoon() correctly uses the buffer parameter.
     *
     * A token expiring in 30 seconds should be considered "expiring soon" when
     * checked with a 60-second buffer, but not when checked with a 10-second buffer.
     */
    public function testTokenSetWillExpireSoon(): void
    {
        $token = new TokenSet(
            accessToken: 'soon-expiring-token',
            expiresAt: time() + 30
        );

        $this->assertTrue(
            $token->willExpireSoon(60),
            'Token expiring in 30s should be "expiring soon" with a 60s buffer'
        );
        $this->assertFalse(
            $token->willExpireSoon(10),
            'Token expiring in 30s should not be "expiring soon" with a 10s buffer'
        );
    }

    /**
     * Verify hasScope() and hasAllScopes() correctly check for individual and
     * multiple scopes within the token's granted scope list.
     *
     * Tests both positive matches and negative matches for scopes that are
     * not present in the token.
     */
    public function testTokenSetHasScope(): void
    {
        $token = new TokenSet(
            accessToken: 'scoped-token',
            scope: ['read', 'write']
        );

        $this->assertTrue($token->hasScope('read'), 'Token should have "read" scope');
        $this->assertFalse($token->hasScope('admin'), 'Token should not have "admin" scope');
        $this->assertTrue(
            $token->hasAllScopes(['read', 'write']),
            'Token should have all of ["read", "write"]'
        );
        $this->assertFalse(
            $token->hasAllScopes(['read', 'admin']),
            'Token should not have all of ["read", "admin"]'
        );
    }

    /**
     * Verify fromTokenResponse() correctly parses a full OAuth token endpoint response.
     *
     * Ensures access_token, refresh_token, expires_in (converted to expiresAt),
     * token_type, and scope (space-separated string split into array) are all
     * correctly mapped to TokenSet properties.
     */
    public function testTokenSetFromTokenResponse(): void
    {
        $response = [
            'access_token' => 'tok123',
            'refresh_token' => 'ref456',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'scope' => 'read write',
        ];

        $beforeTime = time();
        $token = TokenSet::fromTokenResponse($response);
        $afterTime = time();

        $this->assertSame('tok123', $token->accessToken);
        $this->assertSame('ref456', $token->refreshToken);
        $this->assertNotNull($token->expiresAt);
        $this->assertGreaterThanOrEqual($beforeTime + 3600, $token->expiresAt);
        $this->assertLessThanOrEqual($afterTime + 3600, $token->expiresAt);
        $this->assertSame('Bearer', $token->tokenType);
        $this->assertSame(['read', 'write'], $token->scope);
        $this->assertTrue($token->canRefresh(), 'Token with refresh_token should be refreshable');
    }

    /**
     * Verify that fromTokenResponse() preserves original scopes when the token
     * response does not include a scope field, per RFC 6749 Section 6.
     *
     * When refreshing a token, if the authorization server omits the scope
     * from the response, the originally granted scopes should be preserved.
     */
    public function testTokenSetFromTokenResponsePreservesOriginalScope(): void
    {
        $response = [
            'access_token' => 'refreshed-token',
            'refresh_token' => 'new-refresh',
            'expires_in' => 1800,
        ];

        $originalScope = ['openid', 'profile'];

        $token = TokenSet::fromTokenResponse(
            $response,
            resourceUrl: null,
            issuer: null,
            originalScope: $originalScope
        );

        $this->assertSame(
            ['openid', 'profile'],
            $token->scope,
            'Original scopes should be preserved when response omits scope (RFC 6749 Section 6)'
        );
    }

    /**
     * Verify getAuthorizationHeader() returns the correctly formatted
     * Authorization header value combining token type and access token.
     *
     * The format must be "{tokenType} {accessToken}" as expected by HTTP
     * Authorization headers.
     */
    public function testTokenSetGetAuthorizationHeader(): void
    {
        $token = new TokenSet(
            accessToken: 'abc',
            tokenType: 'Bearer'
        );

        $this->assertSame('Bearer abc', $token->getAuthorizationHeader());
    }

    /**
     * Verify that MemoryTokenStorage normalizes URLs by removing trailing slashes,
     * so that storing a token under a URL with a trailing slash can be retrieved
     * using the same URL without the trailing slash, and vice versa.
     */
    public function testMemoryTokenStorageUrlNormalization(): void
    {
        $storage = new MemoryTokenStorage();
        $token = new TokenSet(accessToken: 'normalized-token');

        // Store with trailing slash
        $storage->store('https://example.com/', $token);

        // Retrieve without trailing slash
        $retrieved = $storage->retrieve('https://example.com');

        $this->assertNotNull($retrieved, 'Token stored with trailing slash should be retrievable without it');
        $this->assertSame('normalized-token', $retrieved->accessToken);

        // Also verify the reverse: retrieve with trailing slash
        $retrieved2 = $storage->retrieve('https://example.com/');
        $this->assertNotNull($retrieved2, 'Token should also be retrievable with trailing slash');
        $this->assertSame('normalized-token', $retrieved2->accessToken);
    }

    /**
     * Verify the full lifecycle of MemoryTokenStorage: store, retrieve, remove, and clear.
     *
     * Tests that tokens can be stored and retrieved successfully, that remove()
     * deletes a specific token, and that clear() removes all stored tokens.
     */
    public function testMemoryTokenStorageStoreRetrieveRemoveClear(): void
    {
        $storage = new MemoryTokenStorage();
        $tokenA = new TokenSet(accessToken: 'token-a');
        $tokenB = new TokenSet(accessToken: 'token-b');

        // Store and retrieve
        $storage->store('https://a.example.com', $tokenA);
        $storage->store('https://b.example.com', $tokenB);

        $retrievedA = $storage->retrieve('https://a.example.com');
        $this->assertNotNull($retrievedA);
        $this->assertSame('token-a', $retrievedA->accessToken);

        // Remove specific token
        $storage->remove('https://a.example.com');
        $this->assertNull(
            $storage->retrieve('https://a.example.com'),
            'Token should be null after remove()'
        );
        $this->assertNotNull(
            $storage->retrieve('https://b.example.com'),
            'Other tokens should remain after removing one'
        );

        // Store again then clear all
        $storage->store('https://a.example.com', $tokenA);
        $storage->clear();
        $this->assertNull(
            $storage->retrieve('https://a.example.com'),
            'All tokens should be null after clear()'
        );
        $this->assertNull(
            $storage->retrieve('https://b.example.com'),
            'All tokens should be null after clear()'
        );
    }

    /**
     * Verify that PkceGenerator produces a valid PKCE pair conforming to RFC 7636.
     *
     * Checks that:
     * - The verifier has the expected default length of 64 characters
     * - The verifier contains only characters from the allowed set [A-Za-z0-9\-._~]
     * - The challenge matches the manual computation: base64url(sha256(verifier))
     * - The method is 'S256'
     */
    public function testPkceGeneratorProducesValidPair(): void
    {
        $generator = new PkceGenerator();
        $pkce = $generator->generate();

        // Verify verifier length
        $this->assertSame(64, strlen($pkce['verifier']), 'Default verifier length should be 64');

        // Verify verifier character set
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9\-._~]+$/',
            $pkce['verifier'],
            'Verifier should only contain unreserved URI characters'
        );

        // Verify challenge matches manual computation
        $expectedHash = hash('sha256', $pkce['verifier'], true);
        $expectedChallenge = rtrim(strtr(base64_encode($expectedHash), '+/', '-_'), '=');
        $this->assertSame(
            $expectedChallenge,
            $pkce['challenge'],
            'Challenge should be base64url(sha256(verifier))'
        );

        // Verify method
        $this->assertSame('S256', $pkce['method'], 'Method should be S256');
    }
}
