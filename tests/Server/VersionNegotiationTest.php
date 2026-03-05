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
        $expected = ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'];
        $this->assertEquals($expected, Version::SUPPORTED_PROTOCOL_VERSIONS);
    }

    public function testLatestVersion(): void {
        $this->assertEquals('2025-11-25', Version::LATEST_PROTOCOL_VERSION);
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

        // Unknown feature returns false
        $this->assertFalse(Version::supportsFeature('2025-11-25', 'nonexistent_feature'));
    }
}
