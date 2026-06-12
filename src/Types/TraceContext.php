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
 * Filename: Types/TraceContext.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * W3C Trace Context / Baggage carried in `_meta` (SEP-414).
 *
 * The spec reserves the bare keys `traceparent`, `tracestate`, and `baggage`
 * in `_meta` on requests and notifications — an explicit exception to the
 * DNS-prefix convention, because renaming them would break trace and log
 * correlation. MCP itself imposes no obligation to generate or propagate
 * them; this class is the SDK's pass-through accessor surface, with no
 * OpenTelemetry dependency (see docs/dependency-policy.md). Values are
 * treated as opaque strings — format validation is the tracer's job.
 */
final class TraceContext {
    public function __construct(
        public readonly ?string $traceparent = null,
        public readonly ?string $tracestate = null,
        public readonly ?string $baggage = null,
    ) {}

    /**
     * Extract the trace context from a `_meta` object, or null when none of
     * the reserved keys are present.
     */
    public static function fromMeta(?Meta $meta): ?self {
        if ($meta === null) {
            return null;
        }
        return self::fromArray($meta->getExtraFields());
    }

    /**
     * Extract the trace context from a raw `_meta` array, or null when none
     * of the reserved keys are present.
     *
     * @param array<string, mixed> $meta
     */
    public static function fromArray(array $meta): ?self {
        $traceparent = $meta[MetaKeys::TRACEPARENT] ?? null;
        $tracestate = $meta[MetaKeys::TRACESTATE] ?? null;
        $baggage = $meta[MetaKeys::BAGGAGE] ?? null;

        $traceparent = is_string($traceparent) ? $traceparent : null;
        $tracestate = is_string($tracestate) ? $tracestate : null;
        $baggage = is_string($baggage) ? $baggage : null;

        if ($traceparent === null && $tracestate === null && $baggage === null) {
            return null;
        }

        return new self($traceparent, $tracestate, $baggage);
    }

    /**
     * Write the trace-context keys onto a `_meta` object (unprefixed, per
     * SEP-414). Null fields are left untouched so an existing value is never
     * accidentally cleared.
     */
    public function applyToMeta(Meta $meta): void {
        if ($this->traceparent !== null) {
            $meta->{MetaKeys::TRACEPARENT} = $this->traceparent;
        }
        if ($this->tracestate !== null) {
            $meta->{MetaKeys::TRACESTATE} = $this->tracestate;
        }
        if ($this->baggage !== null) {
            $meta->{MetaKeys::BAGGAGE} = $this->baggage;
        }
    }
}
