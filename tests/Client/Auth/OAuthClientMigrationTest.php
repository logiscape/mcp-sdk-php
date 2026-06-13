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
 *
 * Pre-registered credentials carry their binding per the spec's
 * Authorization Server Binding rule: ClientCredentials::$issuer names the
 * AS they were registered with and is enforced before every grant flow
 * (cross-process durable). Unbound credentials are pinned to the first
 * validated issuer for the lifetime of the OAuthClient instance.
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
        ?ClientCredentials $preRegistered = null,
        bool $allowUnbound = false
    ): array {
        $storage = new MemoryTokenStorage();

        $config = new OAuthConfiguration(
            clientCredentials: $preRegistered,
            tokenStorage: $storage,
            authCallback: $callback,
            redirectUri: 'http://127.0.0.1/callback',
            allowUnboundClientCredentials: $allowUnbound,
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
     * Runs in the legacy unbound mode (allowUnbound) because that is the
     * mode where retry-survival depends on the issuer pin rather than on
     * the default unbound rejection.
     */
    public function testMigrationBlockWithPreRegisteredCredentialsSurvivesRetry(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery, , $storage] = $this->createClient(
            $callback,
            preRegistered: new ClientCredentials('pre-registered-id', 'secret', 'client_secret_basic'),
            allowUnbound: true
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

    /**
     * Issuer-bound pre-registered credentials (draft spec, Authorization
     * Server Binding: credentials MUST be keyed by the issuer they were
     * registered with). The binding lives in configuration, so it survives
     * process boundaries: even with NO stored tokens — a fresh PHP worker
     * after the old tokens were already deleted — discovery selecting a
     * different issuer is blocked before any authorization request is built.
     */
    public function testBoundCredentialsBlockMigrationWithoutStoredTokens(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery] = $this->createClient(
            $callback,
            preRegistered: new ClientCredentials(
                'pre-registered-id',
                'secret',
                'client_secret_basic',
                issuer: self::AS1_ISSUER
            )
        );

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

        $this->assertNull(
            $callback->capturedAuthUrl ?? null,
            'Credentials bound to AS1 must never start an authorization request at AS2'
        );
    }

    /**
     * The positive side of issuer binding: bound credentials whose issuer
     * matches the discovered authorization server are used normally.
     */
    public function testBoundCredentialsMatchingDiscoveredIssuerProceed(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery] = $this->createClient(
            $callback,
            preRegistered: new ClientCredentials(
                'pre-registered-id',
                'secret',
                'client_secret_basic',
                issuer: self::AS2_ISSUER
            )
        );

        $mockDiscovery->method('discoverResourceMetadata')
            ->willReturn(new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::AS2_ISSUER],
            ));
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willReturn($this->makeAsMetadata(self::AS2_ISSUER));

        try {
            $client->handleUnauthorized(self::RESOURCE_URL, []);
            $this->fail('Expected the halting callback to abort the flow');
        } catch (RuntimeException $e) {
            $this->assertSame('HALT_BEFORE_TOKEN_REQUEST', $e->getMessage());
        }

        $this->assertStringStartsWith(self::AS2_ISSUER . '/authorize', (string) $callback->capturedAuthUrl);
        $this->assertStringContainsString('client_id=pre-registered-id', (string) $callback->capturedAuthUrl);
    }

    /**
     * Remediation self-heals: after the operator provisions credentials
     * bound to the NEW issuer, a migration 401 deletes the old tokens and
     * the flow proceeds at the new authorization server — no manual token
     * storage cleanup and no lingering block.
     */
    public function testRemediatedBoundCredentialsProceedAfterMigration(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery, , $storage] = $this->createClient(
            $callback,
            preRegistered: new ClientCredentials(
                'as2-registered-id',
                'secret',
                'client_secret_basic',
                issuer: self::AS2_ISSUER
            )
        );

        $storage->store(self::RESOURCE_URL, $this->makeAs1Tokens());
        $this->seedStalePrmCache($client);

        $mockDiscovery->expects($this->once())
            ->method('discoverResourceMetadata')
            ->with(self::RESOURCE_URL)
            ->willReturn(new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::AS2_ISSUER],
            ));
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willReturn($this->makeAsMetadata(self::AS2_ISSUER));

        try {
            $client->handleUnauthorized(self::RESOURCE_URL, []);
            $this->fail('Expected the halting callback to abort the flow');
        } catch (RuntimeException $e) {
            $this->assertSame('HALT_BEFORE_TOKEN_REQUEST', $e->getMessage());
        }

        $this->assertNull(
            $storage->retrieve(self::RESOURCE_URL),
            'Tokens bound to the previous authorization server must be discarded'
        );
        $this->assertStringStartsWith(self::AS2_ISSUER . '/authorize', (string) $callback->capturedAuthUrl);
        $this->assertStringContainsString('client_id=as2-registered-id', (string) $callback->capturedAuthUrl);
    }

    /**
     * Default (spec-aligned) behavior: pre-registered credentials without
     * an issuer binding are rejected before any authorization or token
     * request is made. The Authorization Server Binding rule requires
     * credentials to be keyed by issuer; without one the client cannot
     * enforce the binding across processes, so it refuses with an
     * actionable error instead of trusting the first discovered AS.
     */
    public function testUnboundCredentialsRejectedByDefault(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery] = $this->createClient(
            $callback,
            preRegistered: new ClientCredentials('pre-registered-id', 'secret', 'client_secret_basic')
        );

        $mockDiscovery->method('discoverResourceMetadata')
            ->willReturn(new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::AS1_ISSUER],
            ));
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willReturn($this->makeAsMetadata(self::AS1_ISSUER));

        try {
            $client->handleUnauthorized(self::RESOURCE_URL, []);
            $this->fail('Expected OAuthException');
        } catch (OAuthException $e) {
            $this->assertSame(OAuthException::REASON_UNBOUND_CLIENT_CREDENTIALS, $e->getReasonCode());
            $this->assertStringContainsString('issuer', $e->getMessage());
            $this->assertStringContainsString('allowUnboundClientCredentials', $e->getMessage());
        }

        $this->assertNull(
            $callback->capturedAuthUrl ?? null,
            'No authorization request may be started with unbound credentials'
        );
    }

    /**
     * Legacy compatibility (published 2025-11-25 behavior, explicit
     * opt-in via allowUnboundClientCredentials): unbound pre-registered
     * credentials are pinned to the first validated issuer they are used
     * with in this instance. A later discovery resolving to a DIFFERENT
     * issuer — here via a second resource served by another authorization
     * server — must not silently carry the same credentials there.
     */
    public function testUnboundCredentialsPinnedToFirstValidatedIssuer(): void
    {
        $resource2 = 'https://api2.example.com/mcp';
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery] = $this->createClient(
            $callback,
            preRegistered: new ClientCredentials('pre-registered-id', 'secret', 'client_secret_basic'),
            allowUnbound: true
        );

        $mockDiscovery->method('discoverResourceMetadata')
            ->willReturnCallback(fn (string $url) => new ProtectedResourceMetadata(
                resource: $url,
                authorizationServers: [
                    $url === self::RESOURCE_URL ? self::AS1_ISSUER : self::AS2_ISSUER,
                ],
            ));
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willReturnCallback(fn (string $url) => $this->makeAsMetadata($url));

        // First use: validated issuer AS1 — the credentials are pinned to it.
        try {
            $client->handleUnauthorized(self::RESOURCE_URL, []);
            $this->fail('Expected the halting callback to abort the flow');
        } catch (RuntimeException $e) {
            $this->assertSame('HALT_BEFORE_TOKEN_REQUEST', $e->getMessage());
        }
        $this->assertStringStartsWith(self::AS1_ISSUER . '/authorize', (string) $callback->capturedAuthUrl);

        // Second resource resolves to AS2: the pinned credentials must not follow.
        try {
            $client->handleUnauthorized($resource2, []);
            $this->fail('Expected OAuthException');
        } catch (OAuthException $e) {
            $this->assertSame(OAuthException::REASON_AUTH_SERVER_MIGRATION, $e->getReasonCode());
        }
        $this->assertStringStartsWith(
            self::AS1_ISSUER . '/authorize',
            (string) $callback->capturedAuthUrl,
            'No authorization request may have been started at AS2'
        );
    }

    /**
     * Multi-AS selection (RFC 9728 §7.6: the client selects among
     * advertised authorization servers): when PRM lists several entries
     * and the configured credentials are bound to one of them, that one
     * is selected — not rejected because the first entry differs.
     */
    public function testBoundCredentialsSelectMatchingAuthorizationServerFromList(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery] = $this->createClient(
            $callback,
            preRegistered: new ClientCredentials(
                'as2-registered-id',
                'secret',
                'client_secret_basic',
                issuer: self::AS2_ISSUER
            )
        );

        $mockDiscovery->method('discoverResourceMetadata')
            ->willReturn(new ProtectedResourceMetadata(
                resource: self::RESOURCE_URL,
                authorizationServers: [self::AS1_ISSUER, self::AS2_ISSUER],
            ));
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willReturnCallback(fn (string $url) => $this->makeAsMetadata($url));

        try {
            $client->handleUnauthorized(self::RESOURCE_URL, []);
            $this->fail('Expected the halting callback to abort the flow');
        } catch (RuntimeException $e) {
            $this->assertSame('HALT_BEFORE_TOKEN_REQUEST', $e->getMessage());
        }

        $this->assertStringStartsWith(
            self::AS2_ISSUER . '/authorize',
            (string) $callback->capturedAuthUrl,
            'The AS the credentials are bound to must be selected from the list'
        );
        $this->assertStringContainsString('client_id=as2-registered-id', (string) $callback->capturedAuthUrl);
    }

    /**
     * The pinned issuer of unbound credentials (legacy opt-in mode)
     * participates in multi-AS selection the same way, so a reordered
     * authorization_servers list does not spuriously block an instance
     * that already pinned one of its entries.
     */
    public function testPinnedIssuerPreferredInMultiAsList(): void
    {
        $resource2 = 'https://api2.example.com/mcp';
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery] = $this->createClient(
            $callback,
            preRegistered: new ClientCredentials('pre-registered-id', 'secret', 'client_secret_basic'),
            allowUnbound: true
        );

        $mockDiscovery->method('discoverResourceMetadata')
            ->willReturnCallback(fn (string $url) => new ProtectedResourceMetadata(
                resource: $url,
                authorizationServers: $url === self::RESOURCE_URL
                    ? [self::AS1_ISSUER]
                    : [self::AS2_ISSUER, self::AS1_ISSUER],
            ));
        $mockDiscovery->method('discoverAuthorizationServerMetadata')
            ->willReturnCallback(fn (string $url) => $this->makeAsMetadata($url));

        // First use pins AS1.
        try {
            $client->handleUnauthorized(self::RESOURCE_URL, []);
            $this->fail('Expected the halting callback to abort the flow');
        } catch (RuntimeException $e) {
            $this->assertSame('HALT_BEFORE_TOKEN_REQUEST', $e->getMessage());
        }
        $this->assertStringStartsWith(self::AS1_ISSUER . '/authorize', (string) $callback->capturedAuthUrl);

        // The second resource lists AS1 too (just not first): AS1 is
        // selected and the flow proceeds instead of blocking on the pin.
        try {
            $client->handleUnauthorized($resource2, []);
            $this->fail('Expected the halting callback to abort the flow');
        } catch (RuntimeException $e) {
            $this->assertSame('HALT_BEFORE_TOKEN_REQUEST', $e->getMessage());
        }
        $this->assertStringStartsWith(self::AS1_ISSUER . '/authorize', (string) $callback->capturedAuthUrl);
    }

    /**
     * Issuer identifiers are security keys: RFC 8414 requires the issuer
     * to be compared without normalization, so a binding that differs
     * only by a default port (or trailing slash, or host case) from the
     * validated metadata issuer is a MISMATCH, not a match.
     */
    public function testIssuerBindingComparisonIsExact(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client, $mockDiscovery] = $this->createClient(
            $callback,
            preRegistered: new ClientCredentials(
                'pre-registered-id',
                'secret',
                'client_secret_basic',
                issuer: 'https://as2.example.com:443'
            )
        );

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
            $this->assertStringContainsString('https://as2.example.com:443', $e->getMessage());
            $this->assertStringContainsString(self::AS2_ISSUER, $e->getMessage());
        }

        $this->assertNull($callback->capturedAuthUrl ?? null);
    }

    /**
     * AUTH_METHOD_AUTO resolution rebuilds the credentials with the
     * negotiated method; the issuer binding must survive that rebuild.
     */
    public function testAutoMethodResolutionPreservesIssuerBinding(): void
    {
        $callback = $this->makeHaltingCallback();
        [$client] = $this->createClient($callback);

        $method = new \ReflectionMethod(OAuthClient::class, 'resolveAuthMethodFromMetadata');
        $method->setAccessible(true);

        $resolved = $method->invoke(
            $client,
            new ClientCredentials(
                'pre-registered-id',
                'secret',
                ClientCredentials::AUTH_METHOD_AUTO,
                issuer: self::AS1_ISSUER
            ),
            new AuthorizationServerMetadata(
                issuer: self::AS1_ISSUER,
                authorizationEndpoint: self::AS1_ISSUER . '/authorize',
                tokenEndpoint: self::AS1_ISSUER . '/token',
                tokenEndpointAuthMethodsSupported: ['client_secret_basic'],
            )
        );

        $this->assertInstanceOf(ClientCredentials::class, $resolved);
        $this->assertSame(ClientCredentials::AUTH_METHOD_CLIENT_SECRET_BASIC, $resolved->tokenEndpointAuthMethod);
        $this->assertSame(self::AS1_ISSUER, $resolved->issuer);
    }
}
