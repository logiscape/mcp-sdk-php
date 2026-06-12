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
 * Filename: tests/Client/Auth/OAuthClientMigrationTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client\Auth;

use Mcp\Client\Auth\Callback\AuthorizationCallbackInterface;
use Mcp\Client\Auth\Callback\AuthorizationCallbackResult;
use Mcp\Client\Auth\Discovery\AuthorizationServerMetadata;
use Mcp\Client\Auth\Discovery\MetadataDiscovery;
use Mcp\Client\Auth\Discovery\ProtectedResourceMetadata;
use Mcp\Client\Auth\OAuthClient;
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\OAuthException;
use Mcp\Client\Auth\Registration\ClientCredentials;
use Mcp\Client\Auth\Registration\DynamicClientRegistration;
use Mcp\Client\Auth\Token\MemoryTokenStorage;
use Mcp\Client\Auth\Token\TokenSet;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Tests for SEP-2352 credential binding and authorization server migration.
 *
 * When a 401 arrives while the client holds tokens for the resource — or a
 * 403 insufficient_scope arrives for tokens it holds — the client MUST:
 *   - re-fetch the Protected Resource Metadata (not serve it from cache),
 *   - detect an authorization_servers issuer change,
 *   - discard tokens bound to the previous AS (dynamic-registration path),
 *   - never present the previous AS's client_id/credentials to the new AS
 *     (fresh DCR registration at the new AS instead),
 *   - surface a clear error for pre-registered credentials bound to the
 *     previous AS rather than silently reusing them — durably on retries,
 *     while still deleting bearer tokens bound to the old issuer.
 */
final class OAuthClientMigrationTest extends TestCase
{
    private const RESOURCE_URL = 'https://api.example.com/mcp';
    private const AS1_ISSUER = 'https://as1.example.com';
    private const AS2_ISSUER = 'https://as2.example.com';

    /**
     * Capture-and-halt callback handler: records the authorization URL the
     * flow built, then aborts before any token request would be issued.
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

    private function makeAsMetadata(string $issuer): AuthorizationServerMetadata
    {
        return new AuthorizationServerMetadata(
            issuer: $issuer,
            authorizationEndpoint: $issuer . '/authorize',
            tokenEndpoint: $issuer . '/token',
            registrationEndpoint: $issuer . '/register',
            codeChallengeMethodsSupported: ['S256'],
        );
    }

    private function makeAs1Tokens(): TokenSet
    {
        return new TokenSet(
            accessToken: 'as1-access-token',
            refreshToken: 'as1-refresh-token',
            resourceUrl: self::RESOURCE_URL,
            issuer: self::AS1_ISSUER,
            resource: self::RESOURCE_URL
        );
    }

    /**
     * @return array{OAuthClient, MetadataDiscovery&\PHPUnit\Framework\MockObject\MockObject, DynamicClientRegistration&\PHPUnit\Framework\MockObject\MockObject, MemoryTokenStorage}
     */
    private function createClient(
        AuthorizationCallbackInterface $callback,
        ?ClientCredentials $preRegistered = null
    ): array {
        $storage = new MemoryTokenStorage();

        $config = new OAuthConfiguration(
            clientCredentials: $preRegistered,
            tokenStorage: $storage,
            authCallback: $callback,
            redirectUri: 'http://127.0.0.1/callback',
        );

        $client = new OAuthClient($config);

        $mockDiscovery = $this->createMock(MetadataDiscovery::class);
        $discoveryRef = new ReflectionProperty(OAuthClient::class, 'discovery');
        $discoveryRef->setAccessible(true);
        $discoveryRef->setValue($client, $mockDiscovery);

        $mockDcr = $this->createMock(DynamicClientRegistration::class);
        $dcrRef = new ReflectionProperty(OAuthClient::class, 'dcr');
        $dcrRef->setAccessible(true);
        $dcrRef->setValue($client, $mockDcr);

        return [$client, $mockDiscovery, $mockDcr, $storage];
    }

    /**
     * Seed the in-memory PRM cache with stale metadata pointing at AS1, as if
     * an earlier successful flow had populated it.
     */
    private function seedStalePrmCache(OAuthClient $client): void
    {
        $cacheRef = new ReflectionProperty(OAuthClient::class, 'resourceMetadataCache');
        $cacheRef->setAccessible(true);
        $cacheRef->setValue($client, [
            self::RESOURCE_URL => new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::AS1_ISSUER],
            ),
        ]);
    }

    /**
     * A 401 while holding tokens must bypass the PRM cache: the discovery
     * service is consulted again even though cached metadata exists, the AS1
     * tokens are discarded once the issuer change is detected, and a fresh
     * DCR registration happens at AS2 (the AS1 client_id never appears in the
     * new authorization request).
     */
    public function testMigrationRefetchesPrmDropsTokensAndRegistersFreshAtNewAs(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery, $mockDcr, $storage] = $this->createClient($callback);

        $storage->store(self::RESOURCE_URL, $this->makeAs1Tokens());
        $this->seedStalePrmCache($client);

        // Seed cached AS1 dynamic credentials, as if registered during the
        // original flow — these must NOT be reused at AS2.
        $credsRef = new ReflectionProperty(OAuthClient::class, 'clientCredentialsCache');
        $credsRef->setAccessible(true);
        $credsRef->setValue($client, [
            self::AS1_ISSUER => new ClientCredentials('as1-client-id', null, 'none'),
        ]);

        // Fresh PRM now lists AS2 — must be fetched despite the cache.
        $mockDiscovery->expects($this->once())
            ->method('discoverResourceMetadata')
            ->with(self::RESOURCE_URL)
            ->willReturn(new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::AS2_ISSUER],
            ));

        $as2Metadata = $this->makeAsMetadata(self::AS2_ISSUER);
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->with(self::AS2_ISSUER)
            ->willReturn($as2Metadata);

        // Fresh registration must happen at AS2.
        $mockDcr->expects($this->once())
            ->method('register')
            ->with($as2Metadata, $this->isType('array'))
            ->willReturn(new ClientCredentials('as2-client-id', null, 'none'));

        try {
            $client->handleUnauthorized(self::RESOURCE_URL, []);
            $this->fail('Expected the halting callback to abort the flow');
        } catch (RuntimeException $e) {
            $this->assertSame('HALT_BEFORE_TOKEN_REQUEST', $e->getMessage());
        }

        // AS1-bound tokens were discarded before the new authorization began.
        $this->assertNull(
            $storage->retrieve(self::RESOURCE_URL),
            'Tokens bound to the previous authorization server must be discarded'
        );

        // The new authorization request uses the AS2 registration, never the
        // AS1 client_id.
        $this->assertNotNull($callback->capturedAuthUrl);
        $this->assertStringContainsString('client_id=as2-client-id', $callback->capturedAuthUrl);
        $this->assertStringNotContainsString('as1-client-id', $callback->capturedAuthUrl);
        $this->assertStringStartsWith(self::AS2_ISSUER . '/authorize', $callback->capturedAuthUrl);
    }

    /**
     * Pre-registered (non-DCR) credentials are bound to the AS they were
     * issued by. On migration they must not be silently presented to the new
     * AS — a clear, typed error is raised instead.
     */
    public function testMigrationWithPreRegisteredCredentialsRaisesClearError(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery, , $storage] = $this->createClient(
            $callback,
            preRegistered: new ClientCredentials('pre-registered-id', 'secret', 'client_secret_basic')
        );

        $storage->store(self::RESOURCE_URL, $this->makeAs1Tokens());

        $mockDiscovery->method('discoverResourceMetadata')
            ->willReturn(new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::AS2_ISSUER],
            ));
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willReturn($this->makeAsMetadata(self::AS2_ISSUER));

        try {
            $client->handleUnauthorized(self::RESOURCE_URL, []);
            $this->fail('Expected OAuthException');
        } catch (OAuthException $e) {
            $this->assertSame(OAuthException::REASON_AUTH_SERVER_MIGRATION, $e->getReasonCode());
            $this->assertStringContainsString(self::AS1_ISSUER, $e->getMessage());
            $this->assertStringContainsString(self::AS2_ISSUER, $e->getMessage());
        }

        // The pre-registered credentials were never sent anywhere: the flow
        // aborted before the callback (and thus before any token request).
        $this->assertNull(
            $callback->capturedAuthUrl ?? null,
            'No authorization request may be started with credentials bound to the old AS'
        );

        $this->assertNull(
            $storage->retrieve(self::RESOURCE_URL),
            'Rejected bearer tokens bound to the old issuer must be deleted'
        );
    }

    /**
     * Regression (WS3 post-commit review): the guard used to discard the
     * stored tokens before raising the pre-registered-credentials error,
     * which disarmed migration detection on retry. The block must survive
     * any number of retries without retaining the rejected bearer token.
     */
    public function testMigrationBlockWithPreRegisteredCredentialsSurvivesRetry(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery, , $storage] = $this->createClient(
            $callback,
            preRegistered: new ClientCredentials('pre-registered-id', 'secret', 'client_secret_basic')
        );

        $storage->store(self::RESOURCE_URL, $this->makeAs1Tokens());

        $mockDiscovery->method('discoverResourceMetadata')
            ->willReturn(new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::AS2_ISSUER],
            ));
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willReturn($this->makeAsMetadata(self::AS2_ISSUER));

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $client->handleUnauthorized(self::RESOURCE_URL, []);
                $this->fail("Attempt {$attempt}: expected OAuthException");
            } catch (OAuthException $e) {
                $this->assertSame(
                    OAuthException::REASON_AUTH_SERVER_MIGRATION,
                    $e->getReasonCode(),
                    "Attempt {$attempt} must still be blocked as a migration"
                );
            }
        }

        $this->assertNull(
            $callback->capturedAuthUrl,
            'The pre-registered credentials must never reach the new AS, even on retry'
        );
        $this->assertNull(
            $storage->retrieve(self::RESOURCE_URL),
            'Durable migration detection must not depend on retaining stale tokens'
        );
    }

    /**
     * SEP-2352 on the 403 insufficient_scope path (WS3 post-commit
     * review): a migration must be detected here exactly like on the 401
     * path — the PRM cache is bypassed, the AS1-bound tokens are
     * discarded, and the step-up grant flow runs against AS2 with a fresh
     * DCR registration, never the AS1 client_id.
     */
    public function test403MigrationRefetchesPrmDropsTokensAndRegistersFreshAtNewAs(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery, $mockDcr, $storage] = $this->createClient($callback);

        $current = $this->makeAs1Tokens();
        $storage->store(self::RESOURCE_URL, $current);
        $this->seedStalePrmCache($client);

        // Seed cached AS1 dynamic credentials — these must NOT be reused at AS2.
        $credsRef = new ReflectionProperty(OAuthClient::class, 'clientCredentialsCache');
        $credsRef->setAccessible(true);
        $credsRef->setValue($client, [
            self::AS1_ISSUER => new ClientCredentials('as1-client-id', null, 'none'),
        ]);

        // Fresh PRM now lists AS2 — must be fetched despite the seeded cache.
        $mockDiscovery->expects($this->once())
            ->method('discoverResourceMetadata')
            ->with(self::RESOURCE_URL)
            ->willReturn(new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::AS2_ISSUER],
            ));

        $as2Metadata = $this->makeAsMetadata(self::AS2_ISSUER);
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->with(self::AS2_ISSUER)
            ->willReturn($as2Metadata);

        $mockDcr->expects($this->once())
            ->method('register')
            ->with($as2Metadata, $this->isType('array'))
            ->willReturn(new ClientCredentials('as2-client-id', null, 'none'));

        try {
            $client->handleInsufficientScope(self::RESOURCE_URL, ['scope' => 'extra.scope'], $current);
            $this->fail('Expected the halting callback to abort the flow');
        } catch (RuntimeException $e) {
            $this->assertSame('HALT_BEFORE_TOKEN_REQUEST', $e->getMessage());
        }

        $this->assertNull(
            $storage->retrieve(self::RESOURCE_URL),
            'Tokens bound to the previous authorization server must be discarded'
        );
        $this->assertNotNull($callback->capturedAuthUrl);
        $this->assertStringContainsString('client_id=as2-client-id', $callback->capturedAuthUrl);
        $this->assertStringNotContainsString('as1-client-id', $callback->capturedAuthUrl);
        $this->assertStringStartsWith(self::AS2_ISSUER . '/authorize', $callback->capturedAuthUrl);
    }

    /**
     * SEP-2352 on the 403 path with pre-registered credentials: the same
     * clear migration error as the 401 path, before any grant flow could
     * carry the old credentials to the new issuer (including via a hostile
     * resource server answering 403 with a resource_metadata pointer at an
     * attacker-controlled AS).
     */
    public function test403MigrationWithPreRegisteredCredentialsRaisesClearError(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery] = $this->createClient(
            $callback,
            preRegistered: new ClientCredentials('pre-registered-id', 'secret', 'client_secret_basic')
        );

        $current = $this->makeAs1Tokens();

        $mockDiscovery->method('discoverResourceMetadata')
            ->willReturn(new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::AS2_ISSUER],
            ));
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willReturn($this->makeAsMetadata(self::AS2_ISSUER));

        try {
            $client->handleInsufficientScope(self::RESOURCE_URL, ['scope' => 'extra.scope'], $current);
            $this->fail('Expected OAuthException');
        } catch (OAuthException $e) {
            $this->assertSame(OAuthException::REASON_AUTH_SERVER_MIGRATION, $e->getReasonCode());
            $this->assertStringContainsString(self::AS1_ISSUER, $e->getMessage());
            $this->assertStringContainsString(self::AS2_ISSUER, $e->getMessage());
        }

        $this->assertNull(
            $callback->capturedAuthUrl ?? null,
            'No authorization request may be started with credentials bound to the old AS'
        );
    }

    /**
     * No migration: a 401 with stored tokens whose issuer matches the freshly
     * discovered AS keeps the tokens in place (they may simply be expired and
     * the regular re-authorization continues), while the PRM is still
     * re-fetched rather than served from cache.
     */
    public function testNoIssuerChangeKeepsTokensButStillRefetchesPrm(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery, $mockDcr, $storage] = $this->createClient($callback);

        $storage->store(self::RESOURCE_URL, $this->makeAs1Tokens());
        $this->seedStalePrmCache($client);

        $mockDiscovery->expects($this->once())
            ->method('discoverResourceMetadata')
            ->with(self::RESOURCE_URL)
            ->willReturn(new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::AS1_ISSUER],
            ));
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willReturn($this->makeAsMetadata(self::AS1_ISSUER));

        $mockDcr->method('register')
            ->willReturn(new ClientCredentials('as1-client-id', null, 'none'));

        try {
            $client->handleUnauthorized(self::RESOURCE_URL, []);
            $this->fail('Expected the halting callback to abort the flow');
        } catch (RuntimeException $e) {
            $this->assertSame('HALT_BEFORE_TOKEN_REQUEST', $e->getMessage());
        }

        $this->assertNotNull(
            $storage->retrieve(self::RESOURCE_URL),
            'Tokens must not be discarded when the issuer is unchanged'
        );
    }

    /**
     * First-contact 401 (no stored tokens): the PRM cache is honored and no
     * migration handling occurs. Guards against over-eager cache busting.
     */
    public function testFirstContactUsesCachedPrm(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery, $mockDcr] = $this->createClient($callback);

        $this->seedStalePrmCache($client);

        $mockDiscovery->expects($this->never())
            ->method('discoverResourceMetadata');
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willReturn($this->makeAsMetadata(self::AS1_ISSUER));

        $mockDcr->method('register')
            ->willReturn(new ClientCredentials('as1-client-id', null, 'none'));

        try {
            $client->handleUnauthorized(self::RESOURCE_URL, []);
            $this->fail('Expected the halting callback to abort the flow');
        } catch (RuntimeException $e) {
            $this->assertSame('HALT_BEFORE_TOKEN_REQUEST', $e->getMessage());
        }

        $this->assertStringContainsString('client_id=as1-client-id', (string) $callback->capturedAuthUrl);
    }
}
