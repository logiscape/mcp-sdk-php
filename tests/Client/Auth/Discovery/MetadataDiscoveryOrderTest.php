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
 * Filename: tests/Client/Auth/Discovery/MetadataDiscoveryOrderTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client\Auth\Discovery;

use Mcp\Client\Auth\Discovery\MetadataDiscovery;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression guard for the SEP-2351 authorization server metadata discovery
 * candidate URL ordering.
 *
 * For an issuer with a path component the client must probe, in order:
 *   1. RFC 8414 path-aware:  /.well-known/oauth-authorization-server/{path}
 *   2. OIDC path-aware:      /.well-known/openid-configuration/{path}
 *   3. OIDC path suffix:     /{path}/.well-known/openid-configuration
 *
 * For an issuer without a path component:
 *   1. RFC 8414:             /.well-known/oauth-authorization-server
 *   2. OIDC:                 /.well-known/openid-configuration
 */
final class MetadataDiscoveryOrderTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function getCandidateUrls(string $issuerUrl): array
    {
        $discovery = new MetadataDiscovery();
        $method = new ReflectionMethod(MetadataDiscovery::class, 'getAuthServerMetadataUrls');
        $method->setAccessible(true);

        /** @var array<int, string> */
        return $method->invoke($discovery, $issuerUrl);
    }

    /**
     * Issuer with a path component: three candidates in SEP-2351 order.
     */
    public function testCandidateOrderForIssuerWithPath(): void
    {
        $this->assertSame(
            [
                'https://as.example.com/.well-known/oauth-authorization-server/tenant1',
                'https://as.example.com/.well-known/openid-configuration/tenant1',
                'https://as.example.com/tenant1/.well-known/openid-configuration',
            ],
            $this->getCandidateUrls('https://as.example.com/tenant1')
        );
    }

    /**
     * Issuer without a path component: RFC 8414 location first, then OIDC.
     */
    public function testCandidateOrderForIssuerWithoutPath(): void
    {
        $this->assertSame(
            [
                'https://as.example.com/.well-known/oauth-authorization-server',
                'https://as.example.com/.well-known/openid-configuration',
            ],
            $this->getCandidateUrls('https://as.example.com')
        );
    }

    /**
     * A bare trailing slash counts as "no path"; ports are preserved.
     */
    public function testTrailingSlashAndPortHandling(): void
    {
        $this->assertSame(
            [
                'https://as.example.com:8443/.well-known/oauth-authorization-server',
                'https://as.example.com:8443/.well-known/openid-configuration',
            ],
            $this->getCandidateUrls('https://as.example.com:8443/')
        );
    }

    /**
     * Nested path components are preserved in all three candidates.
     */
    public function testNestedPathComponents(): void
    {
        $this->assertSame(
            [
                'https://as.example.com/.well-known/oauth-authorization-server/auth/v2',
                'https://as.example.com/.well-known/openid-configuration/auth/v2',
                'https://as.example.com/auth/v2/.well-known/openid-configuration',
            ],
            $this->getCandidateUrls('https://as.example.com/auth/v2')
        );
    }
}
