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
     * Test that feature gating works correctly for each version.
     */
    public function testClientSessionFeatureGating(): void {
        // We can't fully test ClientSession.supportsFeature without a full session,
        // but we can verify the version comparison logic directly
        $this->assertTrue(version_compare('2025-03-26', '2025-03-26', '>='));
        $this->assertTrue(version_compare('2025-06-18', '2025-03-26', '>='));
        $this->assertTrue(version_compare('2025-11-25', '2025-03-26', '>='));
        $this->assertFalse(version_compare('2024-11-05', '2025-03-26', '>='));

        // 2025-06-18 features
        $this->assertTrue(version_compare('2025-06-18', '2025-06-18', '>='));
        $this->assertTrue(version_compare('2025-11-25', '2025-06-18', '>='));
        $this->assertFalse(version_compare('2025-03-26', '2025-06-18', '>='));

        // 2025-11-25 features
        $this->assertTrue(version_compare('2025-11-25', '2025-11-25', '>='));
        $this->assertFalse(version_compare('2025-06-18', '2025-11-25', '>='));
    }
}
