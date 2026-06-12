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
 * Filename: Types/CacheableResultTrait.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Implements the SEP-2549 caching-hint fields for {@see CacheableResult}
 * result types.
 *
 * The properties are nullable so legacy serialization is unchanged when the
 * hints are unset: Result::jsonSerialize() only emits non-null properties.
 * Under the 2026-07-28 revision both fields are required on the wire, so the
 * server session stamps defaults (ttlMs 0, cacheScope "private" — the most
 * conservative combination) on any cacheable result the handler left bare.
 */
trait CacheableResultTrait {
    /** Freshness lifetime in milliseconds (>= 0; 0 = immediately stale). */
    public ?int $ttlMs = null;

    /** Cache scope: "public" (shared caches allowed) or "private" (per-user only). */
    public ?string $cacheScope = null;

    public function setCacheHints(int $ttlMs, string $cacheScope): void {
        $this->ttlMs = $ttlMs;
        $this->cacheScope = $cacheScope;
        $this->validateCacheHints();
    }

    public function getTtlMs(): ?int {
        return $this->ttlMs;
    }

    public function getCacheScope(): ?string {
        return $this->cacheScope;
    }

    public function clearCacheHints(): void {
        $this->ttlMs = null;
        $this->cacheScope = null;
    }

    /**
     * Validate the caching hints when present. Called from the using class's
     * validate() method.
     */
    protected function validateCacheHints(): void {
        if ($this->ttlMs !== null && $this->ttlMs < 0) {
            throw new \InvalidArgumentException('ttlMs must be a non-negative integer');
        }
        if ($this->cacheScope !== null
            && $this->cacheScope !== CacheableResult::CACHE_SCOPE_PUBLIC
            && $this->cacheScope !== CacheableResult::CACHE_SCOPE_PRIVATE
        ) {
            throw new \InvalidArgumentException('cacheScope must be "public" or "private"');
        }
    }
}
