<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Client\ClientSession;
use Mcp\Shared\Version;
use PHPUnit\Framework\TestCase;

/**
 * Tests for protocol version negotiation across all supported versions.
 */
final class VersionNegotiationTest extends TestCase
{
    public function testAllVersionsSupported(): void {
        $expected = ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25', '2026-07-28'];
        $this->assertEquals($expected, Version::SUPPORTED_PROTOCOL_VERSIONS);
    }

    public function testLatestVersion(): void {
        $this->assertEquals('2026-07-28', Version::LATEST_PROTOCOL_VERSION);
    }

    /**
     * The latest legacy version is the newest revision negotiable via the
     * initialize handshake — 2026-07-28 removes the handshake (SEP-2575),
     * so it can never be the result of an initialize exchange.
     */
    public function testLatestLegacyVersion(): void {
        $this->assertEquals('2025-11-25', Version::LATEST_LEGACY_PROTOCOL_VERSION);
        $this->assertContains(Version::LATEST_LEGACY_PROTOCOL_VERSION, Version::SUPPORTED_PROTOCOL_VERSIONS);
        $this->assertTrue(version_compare(
            Version::LATEST_LEGACY_PROTOCOL_VERSION,
            Version::LATEST_PROTOCOL_VERSION,
            '<'
        ));
    }

    /**
     * Test that Version::supportsFeature() correctly gates features by version.
     */
    public function testVersionSupportsFeature(): void {
        // 2025-03-26 features
        $this->assertTrue(Version::supportsFeature('2025-03-26', 'audio_content'));
        $this->assertTrue(Version::supportsFeature('2025-06-18', 'audio_content'));
        $this->assertTrue(Version::supportsFeature('2025-11-25', 'annotations'));
        $this->assertFalse(Version::supportsFeature('2024-11-05', 'audio_content'));

        // 2025-06-18 features
        $this->assertTrue(Version::supportsFeature('2025-06-18', 'elicitation'));
        $this->assertTrue(Version::supportsFeature('2025-11-25', 'structured_content'));
        $this->assertFalse(Version::supportsFeature('2025-03-26', 'elicitation'));
        $this->assertFalse(Version::supportsFeature('2024-11-05', 'resource_link_content'));

        // 2025-11-25 features
        $this->assertTrue(Version::supportsFeature('2025-11-25', 'tasks'));
        $this->assertTrue(Version::supportsFeature('2025-11-25', 'sampling_with_tools'));
        $this->assertFalse(Version::supportsFeature('2025-06-18', 'tasks'));
        $this->assertFalse(Version::supportsFeature('2025-03-26', 'url_elicitation'));

        // 2026-07-28 features
        $this->assertTrue(Version::supportsFeature('2026-07-28', 'stateless_lifecycle'));
        $this->assertTrue(Version::supportsFeature('2026-07-28', 'caching_hints'));
        $this->assertTrue(Version::supportsFeature('2026-07-28', 'resource_not_found_invalid_params'));
        $this->assertTrue(Version::supportsFeature('2026-07-28', 'json_schema_2020_12'));
        $this->assertFalse(Version::supportsFeature('2025-11-25', 'stateless_lifecycle'));
        $this->assertFalse(Version::supportsFeature('2025-11-25', 'caching_hints'));
        $this->assertFalse(Version::supportsFeature('2025-11-25', 'resource_not_found_invalid_params'));
        $this->assertFalse(Version::supportsFeature('2025-11-25', 'json_schema_2020_12'));

        // Legacy features remain available on the newest revision
        $this->assertTrue(Version::supportsFeature('2026-07-28', 'tasks'));
        $this->assertTrue(Version::supportsFeature('2026-07-28', 'structured_content'));

        // Unknown feature returns false
        $this->assertFalse(Version::supportsFeature('2025-11-25', 'nonexistent_feature'));
    }
}
