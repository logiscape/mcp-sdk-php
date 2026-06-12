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
 * Filename: tests/Client/Auth/Registration/DynamicClientRegistrationMetadataTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client\Auth\Registration;

use Mcp\Client\Auth\Registration\DynamicClientRegistration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the DCR registration metadata builder.
 *
 * Validates SEP-837 (every registration body carries an application_type of
 * exactly "native" or "web", derived from the redirect URIs unless overridden)
 * and the SEP-2207 default of including refresh_token in grant_types.
 */
final class DynamicClientRegistrationMetadataTest extends TestCase
{
    /**
     * SEP-837: loopback/localhost redirect URIs identify a native application.
     *
     * @return array<string, array{array<int, string>, string}>
     */
    public static function applicationTypeProvider(): array
    {
        return [
            'localhost http' => [['http://localhost:3000/callback'], 'native'],
            'loopback IPv4' => [['http://127.0.0.1/callback'], 'native'],
            'loopback IPv6' => [['http://[::1]:8080/callback'], 'native'],
            'private-use scheme' => [['com.example.app:/oauth/callback'], 'native'],
            'mixed loopback variants' => [
                ['http://127.0.0.1/callback', 'http://localhost/callback'],
                'native',
            ],
            'no redirect uris' => [[], 'native'],
            'remote https' => [['https://app.example.com/callback'], 'web'],
            'remote mixed with loopback' => [
                ['http://localhost/callback', 'https://app.example.com/callback'],
                'web',
            ],
            'lookalike host' => [['https://localhost.evil.example/callback'], 'web'],
        ];
    }

    /**
     * deriveApplicationType maps redirect URIs to native/web per RFC 8252.
     *
     * @dataProvider applicationTypeProvider
     * @param array<int, string> $redirectUris
     */
    public function testDeriveApplicationType(array $redirectUris, string $expected): void
    {
        $this->assertSame(
            $expected,
            DynamicClientRegistration::deriveApplicationType($redirectUris)
        );
    }

    /**
     * SEP-837: buildMetadata always includes an application_type of exactly
     * "native" or "web".
     */
    public function testBuildMetadataIncludesApplicationType(): void
    {
        $metadata = DynamicClientRegistration::buildMetadata(
            clientName: 'Test Client',
            redirectUris: ['http://127.0.0.1/callback']
        );

        $this->assertArrayHasKey('application_type', $metadata);
        $this->assertSame('native', $metadata['application_type']);

        $webMetadata = DynamicClientRegistration::buildMetadata(
            clientName: 'Test Client',
            redirectUris: ['https://app.example.com/callback']
        );

        $this->assertSame('web', $webMetadata['application_type']);
    }

    /**
     * A caller-supplied application_type in additionalMetadata overrides the
     * derived default.
     */
    public function testExplicitApplicationTypeOverridesDerivation(): void
    {
        $metadata = DynamicClientRegistration::buildMetadata(
            clientName: 'Test Client',
            redirectUris: ['http://127.0.0.1/callback'],
            additionalMetadata: ['application_type' => 'web']
        );

        $this->assertSame('web', $metadata['application_type']);
    }

    /**
     * SEP-2207: registration metadata defaults to requesting the
     * refresh_token grant alongside authorization_code.
     */
    public function testBuildMetadataIncludesRefreshTokenGrant(): void
    {
        $metadata = DynamicClientRegistration::buildMetadata(
            clientName: 'Test Client',
            redirectUris: ['http://127.0.0.1/callback']
        );

        $this->assertContains('refresh_token', $metadata['grant_types']);
        $this->assertContains('authorization_code', $metadata['grant_types']);
    }
}
