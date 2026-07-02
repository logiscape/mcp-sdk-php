<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Filename: tests/Client/Auth/DcrDeprecationWarningTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client\Auth;

use Mcp\Client\Auth\Discovery\AuthorizationServerMetadata;
use Mcp\Client\Auth\OAuthException;
use Mcp\Client\Auth\Registration\DynamicClientRegistration;
use Mcp\Tests\Shared\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Dynamic Client Registration is Deprecated as of 2026-07-28 (spec PR
 * #2858; migration: Client ID Metadata Documents). Exercising it emits
 * the SEP-2596 runtime warning before the registration request — the
 * authorization layer has no negotiated MCP revision to gate on, so the
 * warning states the deprecating revision instead.
 */
final class DcrDeprecationWarningTest extends TestCase
{
    public function testRegisterEmitsDeprecationWarning(): void
    {
        $logger = new RecordingLogger();
        $dcr = new DynamicClientRegistration(timeout: 0.2, verifyTls: false, logger: $logger);
        $as = new AuthorizationServerMetadata(
            issuer: 'http://127.0.0.1:9', // discard port: connection refused fast
            authorizationEndpoint: 'http://127.0.0.1:9/authorize',
            tokenEndpoint: 'http://127.0.0.1:9/token',
            registrationEndpoint: 'http://127.0.0.1:9/register',
        );

        try {
            $dcr->register($as, ['redirect_uris' => ['http://localhost/callback']]);
        } catch (OAuthException $e) {
            // Expected: the endpoint is unreachable. The warning fires first.
        }

        $warnings = array_values(array_filter(
            $logger->warnings(),
            static fn (string $m): bool => str_contains($m, 'dynamicClientRegistration')
        ));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Client ID Metadata Documents', $warnings[0]);
        $this->assertStringContainsString('2026-07-28', $warnings[0]);
    }
}
