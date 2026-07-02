<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Filename: tests/Shared/FeatureLifecycleTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Shared;

use Mcp\Shared\FeatureLifecycle;
use Mcp\Shared\Version;
use PHPUnit\Framework\TestCase;

/**
 * The SEP-2596 feature-lifecycle registry mirror: which features are
 * Deprecated in which revision (matching the spec's deprecated-features
 * registry), the version gate (a feature is Deprecated only under
 * revisions at or past its deprecating revision — it is Active before),
 * and the runtime warning message content.
 */
final class FeatureLifecycleTest extends TestCase
{
    public function testRegistryMatchesTheSpecDeprecatedFeaturesRegistry(): void
    {
        $this->assertSame('2026-07-28', FeatureLifecycle::deprecatedIn(FeatureLifecycle::ROOTS));
        $this->assertSame('2026-07-28', FeatureLifecycle::deprecatedIn(FeatureLifecycle::SAMPLING));
        $this->assertSame('2026-07-28', FeatureLifecycle::deprecatedIn(FeatureLifecycle::LOGGING));
        $this->assertSame('2026-07-28', FeatureLifecycle::deprecatedIn(FeatureLifecycle::DYNAMIC_CLIENT_REGISTRATION));
        $this->assertSame('2025-11-25', FeatureLifecycle::deprecatedIn(FeatureLifecycle::SAMPLING_INCLUDE_CONTEXT));
    }

    public function testActiveFeaturesAreNotInTheRegistry(): void
    {
        $this->assertNull(FeatureLifecycle::deprecatedIn('tools'));
        $this->assertNull(FeatureLifecycle::deprecatedIn('elicitation'));
        $this->assertFalse(FeatureLifecycle::isDeprecatedIn('tools', '2026-07-28'));
    }

    public function testDeprecationIsGatedOnTheNegotiatedRevision(): void
    {
        // SEP-2577 features are Active through 2025-11-25 and Deprecated
        // from 2026-07-28.
        foreach ([FeatureLifecycle::ROOTS, FeatureLifecycle::SAMPLING, FeatureLifecycle::LOGGING] as $feature) {
            $this->assertFalse(FeatureLifecycle::isDeprecatedIn($feature, '2024-11-05'));
            $this->assertFalse(FeatureLifecycle::isDeprecatedIn($feature, '2025-11-25'));
            $this->assertTrue(FeatureLifecycle::isDeprecatedIn($feature, '2026-07-28'));
        }

        // The includeContext values were deprecated earlier (2025-11-25,
        // SEP-2596 transition provisions).
        $this->assertFalse(FeatureLifecycle::isDeprecatedIn(FeatureLifecycle::SAMPLING_INCLUDE_CONTEXT, '2025-06-18'));
        $this->assertTrue(FeatureLifecycle::isDeprecatedIn(FeatureLifecycle::SAMPLING_INCLUDE_CONTEXT, '2025-11-25'));
        $this->assertTrue(FeatureLifecycle::isDeprecatedIn(FeatureLifecycle::SAMPLING_INCLUDE_CONTEXT, '2026-07-28'));
    }

    public function testUnknownVersionNeverDeprecates(): void
    {
        $this->assertFalse(FeatureLifecycle::isDeprecatedIn(FeatureLifecycle::SAMPLING, null));
    }

    public function testRcWindowDraftAliasCanonicalizesToTheDeprecatingRevision(): void
    {
        $this->assertTrue(FeatureLifecycle::isDeprecatedIn(
            FeatureLifecycle::SAMPLING,
            Version::DRAFT_MODERN_PROTOCOL_VERSION
        ));
    }

    public function testWarningMessageNamesSepRevisionAndMigrationPath(): void
    {
        $message = FeatureLifecycle::warningMessage(FeatureLifecycle::LOGGING);
        $this->assertStringContainsString('SEP-2577', $message);
        $this->assertStringContainsString('2026-07-28', $message);
        $this->assertStringContainsString('stderr', $message);
        $this->assertStringContainsString('deprecated', $message);

        $dcr = FeatureLifecycle::warningMessage(FeatureLifecycle::DYNAMIC_CLIENT_REGISTRATION);
        $this->assertStringContainsString('Client ID Metadata Documents', $dcr);
    }
}
