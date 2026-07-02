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
 * Filename: Shared/FeatureLifecycle.php
 */

declare(strict_types=1);

namespace Mcp\Shared;

/**
 * The SEP-2596 feature lifecycle (Active → Deprecated → Removed) as it
 * applies to this SDK: a mirror of the spec's deprecated-features registry
 * (`docs/specification/draft/deprecated.mdx`) for the features the SDK
 * implements, keyed by the revision in which each became Deprecated.
 *
 * SEP-2596 defines NO wire-level deprecation signal — deprecation is a
 * specification/schema/registry concern, and during the (minimum
 * twelve-month) deprecation window wire behavior is unchanged: deprecated
 * features keep working, capability negotiation is untouched, and no error
 * codes are introduced. The SDK obligations are language-native API
 * annotations (`@deprecated` docblocks) and the SHOULD-level runtime
 * warning this class backs: "Implementations SHOULD emit a runtime warning
 * when a deprecated feature is exercised" (SEP-2596), "Implementations
 * SHOULD emit a warning (e.g., in logs or developer tooling) when
 * deprecated capabilities are negotiated" (SEP-2577).
 *
 * A feature is Deprecated relative to a negotiated revision: exercising
 * Sampling on a `2025-11-25` session is NOT deprecated behavior (that
 * revision has it Active), so the warnings are gated on the session's
 * negotiated protocol version being at or past the deprecating revision.
 */
final class FeatureLifecycle
{
    /** Roots — deprecated by SEP-2577 as of 2026-07-28. */
    public const ROOTS = 'roots';

    /** Sampling — deprecated by SEP-2577 as of 2026-07-28. */
    public const SAMPLING = 'sampling';

    /** Logging — deprecated by SEP-2577 as of 2026-07-28. */
    public const LOGGING = 'logging';

    /**
     * The `includeContext: "thisServer" | "allServers"` sampling values —
     * deprecated by SEP-2596's transition provisions as of 2025-11-25
     * (removal follows the Sampling feature itself).
     */
    public const SAMPLING_INCLUDE_CONTEXT = 'sampling.includeContext';

    /**
     * OAuth Dynamic Client Registration (RFC 7591) — deprecated as of
     * 2026-07-28 (spec PR #2858) in favor of Client ID Metadata Documents.
     */
    public const DYNAMIC_CLIENT_REGISTRATION = 'authorization.dynamicClientRegistration';

    /** The revision in which each feature became Deprecated. */
    private const DEPRECATED_IN = [
        self::ROOTS => '2026-07-28',
        self::SAMPLING => '2026-07-28',
        self::LOGGING => '2026-07-28',
        self::SAMPLING_INCLUDE_CONTEXT => '2025-11-25',
        self::DYNAMIC_CLIENT_REGISTRATION => '2026-07-28',
    ];

    /** The registry's documented migration path for each feature. */
    private const MIGRATION = [
        self::ROOTS => 'pass directories or files via tool parameters, resource URIs, or server configuration',
        self::SAMPLING => 'integrate directly with LLM provider APIs',
        self::LOGGING => 'log to stderr for stdio transports; use OpenTelemetry for observability',
        self::SAMPLING_INCLUDE_CONTEXT => 'omit the includeContext field or use "none"',
        self::DYNAMIC_CLIENT_REGISTRATION => 'use Client ID Metadata Documents (CIMD)',
    ];

    /** The SEP (or spec PR) that deprecated each feature. */
    private const DEPRECATED_BY = [
        self::ROOTS => 'SEP-2577',
        self::SAMPLING => 'SEP-2577',
        self::LOGGING => 'SEP-2577',
        self::SAMPLING_INCLUDE_CONTEXT => 'SEP-2596',
        self::DYNAMIC_CLIENT_REGISTRATION => 'spec PR #2858',
    ];

    private function __construct()
    {
    }

    /**
     * The revision in which a feature became Deprecated, or null for
     * features this registry does not track (i.e. Active features).
     */
    public static function deprecatedIn(string $feature): ?string
    {
        return self::DEPRECATED_IN[$feature] ?? null;
    }

    /**
     * Whether a feature is in the Deprecated state under the given
     * negotiated protocol revision. False when the version is unknown
     * (null) or predates the deprecating revision — the feature is Active
     * there and exercising it warrants no warning.
     */
    public static function isDeprecatedIn(string $feature, ?string $negotiatedVersion): bool
    {
        $deprecatedIn = self::DEPRECATED_IN[$feature] ?? null;
        if ($deprecatedIn === null || $negotiatedVersion === null) {
            return false;
        }
        return version_compare(Version::canonicalizeVersion($negotiatedVersion), $deprecatedIn, '>=');
    }

    /**
     * The runtime warning message for exercising a deprecated feature —
     * names the deprecating SEP, the deprecating revision, and the
     * registry's migration path.
     */
    public static function warningMessage(string $feature): string
    {
        $deprecatedIn = self::DEPRECATED_IN[$feature] ?? 'unknown';
        $by = self::DEPRECATED_BY[$feature] ?? 'the MCP specification';
        $migration = self::MIGRATION[$feature] ?? 'see the deprecated features registry';
        return "MCP feature '{$feature}' is deprecated as of protocol revision {$deprecatedIn} ({$by}) "
            . "and is scheduled for removal after the twelve-month deprecation window; "
            . "migration: {$migration}. See the specification's deprecated features registry.";
    }
}
