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
 * Filename: Types/CacheableResult.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Marker interface for results that carry the SEP-2549 caching hints
 * (`ttlMs` / `cacheScope`) under the 2026-07-28 protocol revision.
 *
 * The draft schema applies these to exactly six result types: tools/list,
 * prompts/list, resources/list, resources/templates/list, resources/read,
 * and server/discover. Both fields are REQUIRED on the modern path (the
 * server session stamps conservative defaults when unset) and stripped for
 * legacy clients.
 *
 * @see CacheableResultTrait
 */
interface CacheableResult {
    public const CACHE_SCOPE_PUBLIC = 'public';
    public const CACHE_SCOPE_PRIVATE = 'private';

    /**
     * Set the caching hints for this result.
     *
     * @param int $ttlMs Freshness lifetime in milliseconds (>= 0; 0 = immediately stale)
     * @param string $cacheScope Either "public" or "private"
     */
    public function setCacheHints(int $ttlMs, string $cacheScope): void;

    public function getTtlMs(): ?int;

    public function getCacheScope(): ?string;

    /** Remove the caching hints (used when adapting a result for a legacy client). */
    public function clearCacheHints(): void;
}
