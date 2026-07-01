<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * Developed by:
 * - Josh Abbott
 * - Claude (Anthropic AI model)
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
 * Filename: Shared/McpHeaders.php
 */

declare(strict_types=1);

namespace Mcp\Shared;

/**
 * SEP-2243 request-metadata header rules for the Streamable HTTP transport
 * (2026-07-28 revision), shared by the client (emission) and the server
 * (validation).
 *
 * Standard headers:
 * - `Mcp-Method` mirrors the JSON-RPC `method` on every request and
 *   notification.
 * - `Mcp-Name` mirrors `params.name` on tools/call and prompts/get,
 *   `params.uri` on resources/read, and `params.taskId` on the Tasks
 *   extension's task methods.
 * - `Mcp-Param-{name}` mirrors a tool argument whose inputSchema property
 *   carries the `x-mcp-header` annotation.
 *
 * Comparison rules (spec §Case Sensitivity): header NAMES are compared
 * case-insensitively, header VALUES case-sensitively after trimming RFC 9110
 * optional whitespace (space / horizontal tab).
 *
 * Value encoding for Mcp-Param-* (spec §Value Encoding): strings as-is,
 * integers as decimal strings, booleans as lowercase true/false; values with
 * leading/trailing whitespace, characters outside printable ASCII
 * (0x20-0x7E), or that themselves match the base64 sentinel pattern are
 * wrapped as `=?base64?{base64(UTF-8 bytes)}?=`. The sentinel is
 * case-sensitive lowercase (spec PR #2937 resolved the earlier
 * case-insensitivity contradiction): a value whose prefix is not exactly
 * `=?base64?` — including `=?BASE64?` — is a literal and is never decoded.
 *
 * These rules are HTTP-binding-only; the stdio transport has no headers and
 * is exempt.
 */
final class McpHeaders
{
    public const METHOD = 'Mcp-Method';
    public const NAME = 'Mcp-Name';
    public const PARAM_PREFIX = 'Mcp-Param-';
    public const PROTOCOL_VERSION = 'MCP-Protocol-Version';

    /** The JSON Schema annotation that designates a parameter for mirroring. */
    public const SCHEMA_ANNOTATION = 'x-mcp-header';

    /**
     * Map of name-bearing methods to the params field that supplies the
     * Mcp-Name value. tasks/* entries follow SEP-2663 §Routing Headers
     * (the Tasks extension reuses Mcp-Name for the task id).
     */
    private const NAME_SOURCES = [
        'tools/call' => 'name',
        'prompts/get' => 'name',
        'resources/read' => 'uri',
        'tasks/get' => 'taskId',
        'tasks/update' => 'taskId',
        'tasks/cancel' => 'taskId',
    ];

    private function __construct()
    {
    }

    /**
     * Trim RFC 9110 §5.5 optional whitespace (SP / HTAB) from a header value
     * before evaluating it.
     */
    public static function trimOws(string $value): string
    {
        return trim($value, " \t");
    }

    /**
     * Whether the given JSON-RPC method requires an Mcp-Name header.
     */
    public static function methodBearsName(string $method): bool
    {
        return isset(self::NAME_SOURCES[$method]);
    }

    /**
     * The value the Mcp-Name header must carry for a request, derived from
     * its method and params, or null when the method does not bear one (or
     * the params lack the source field — the request is then invalid for
     * other reasons and header validation does not apply).
     *
     * @param array<string, mixed>|null $params Decoded request params
     */
    public static function expectedNameValue(string $method, ?array $params): ?string
    {
        $field = self::NAME_SOURCES[$method] ?? null;
        if ($field === null || $params === null) {
            return null;
        }
        $value = $params[$field] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * Encode a designated parameter value for an Mcp-Param-* header.
     *
     * @param string|int|float|bool $value The argument value from the
     *        request body (null arguments must be omitted by the caller,
     *        never encoded)
     */
    public static function encodeParamValue(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            // Match JSON number formatting: integral floats render without
            // a decimal point; the server compares numerically.
            $string = (string) $value;
            return $string;
        }

        if (self::needsBase64($value)) {
            return '=?base64?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    /**
     * Whether a string value must be base64-wrapped: leading/trailing
     * whitespace (SP/HTAB), any character outside printable ASCII
     * (0x20-0x7E), or a plain value that itself matches the sentinel
     * wrapper pattern.
     */
    public static function needsBase64(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if ($value !== trim($value, " \t")) {
            return true;
        }
        if (preg_match('/[^\x20-\x7E]/', $value) === 1) {
            return true;
        }
        // A literal that looks like the wrapper would be mis-decoded by a
        // receiver; wrap it so it survives round-tripping. The sentinel is
        // case-sensitive (#2937), so only an exact-lowercase match is
        // ambiguous — a non-lowercase prefix is already a plain literal.
        return preg_match('/^=\?base64\?.*\?=$/', $value) === 1;
    }

    /**
     * Decode a received Mcp-Param-* header value.
     *
     * The value must already be OWS-trimmed. A complete base64 sentinel
     * wrapper (case-sensitive lowercase per #2937 — a non-lowercase prefix
     * such as `=?BASE64?` is treated as a literal value, never decoded) is
     * strictly decoded; anything else is returned literally. Returns null
     * when the wrapper is present but its payload is not canonical base64
     * (invalid characters, bad padding) — the server must reject such a
     * request with HeaderMismatch.
     */
    public static function decodeParamValue(string $value): ?string
    {
        if (preg_match('/^=\?base64\?(.*)\?=$/s', $value, $m) !== 1) {
            return $value;
        }
        $payload = $m[1];
        if ($payload === '') {
            return '';
        }
        if (strlen($payload) % 4 !== 0) {
            return null;
        }
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return null;
        }
        // Require canonical encoding so truncated/ambiguous payloads are
        // rejected rather than silently accepted with altered content.
        if (base64_encode($decoded) !== $payload) {
            return null;
        }
        return $decoded;
    }

    /**
     * Derive the Mcp-Param-* header name for an x-mcp-header annotation
     * value (the annotation supplies the {name} part verbatim).
     */
    public static function paramHeaderName(string $annotation): string
    {
        return self::PARAM_PREFIX . $annotation;
    }

    /**
     * Whether an x-mcp-header annotation value is a valid RFC 9110 token
     * (1*tchar) — the charset constraint the spec puts on the {name} part.
     */
    public static function isValidAnnotationName(string $annotation): bool
    {
        return preg_match("/^[A-Za-z0-9!#$%&'*+.^_`|~-]+$/", $annotation) === 1;
    }

    /**
     * The largest integer exactly representable in an IEEE 754 double
     * (2^53 - 1). SEP-2243 restricts designated integer values to
     * ±MAX_SAFE_INTEGER so JavaScript peers cannot silently corrupt them.
     */
    public const MAX_SAFE_INTEGER = 9007199254740991;

    /**
     * Whether an integer is within the SEP-2243 JavaScript-safe range.
     */
    public static function isSafeInteger(int $value): bool
    {
        return $value >= -self::MAX_SAFE_INTEGER && $value <= self::MAX_SAFE_INTEGER;
    }

    /**
     * Whether a numeric value is a valid SEP-2243 designated INTEGER
     * value: large JSON integers decode as floats in PHP, so an
     * integer-typed designated parameter may arrive as either type —
     * floats must additionally be finite and integral before the
     * ±(2^53 - 1) bound applies.
     */
    public static function isSafeIntegerValue(int|float $value): bool
    {
        if (is_int($value)) {
            return self::isSafeInteger($value);
        }
        return is_finite($value)
            && floor($value) === $value
            && abs($value) <= (float) self::MAX_SAFE_INTEGER;
    }

    /**
     * Collect the x-mcp-header annotations from a tool inputSchema,
     * validating the spec's constraints.
     *
     * Returns ['map' => [path => {annotation, type, segments}], 'errors' =>
     * [message, ...]] where `path` is the dot-joined property path (the
     * annotation MAY appear at any nesting depth — nested object
     * properties are scanned recursively). A non-empty errors list means
     * the tool definition is invalid: HTTP clients MUST reject such a
     * tool (exclude it from use) rather than guess at the author's
     * intent; one invalid tool must not block valid siblings.
     *
     * Constraints enforced: annotation is a non-empty RFC 9110 token; the
     * annotated property has a primitive type — string, integer, or
     * boolean (`number` is explicitly NOT permitted by SEP-2243; object,
     * array, and null are rejected too); annotations are unique
     * case-insensitively across the whole schema. Note: the pinned
     * 0.2.0-alpha.7 conformance tool's http-custom-headers scenario still
     * mirrors number-typed parameters — that scenario predates the final
     * type restriction (upstream fix #371 lands at alpha.8) and its failure
     * is documented in the draft baseline rather than worked around here
     * (official text wins).
     *
     * @param array<string, mixed>|null $inputSchema Decoded tool inputSchema
     * @return array{map: array<string, array{annotation: string, type: string, segments: list<string>}>, errors: list<string>}
     */
    public static function collectAnnotations(?array $inputSchema): array
    {
        $map = [];
        $errors = [];
        $seen = [];

        $properties = $inputSchema['properties'] ?? null;
        if (is_array($properties)) {
            self::scanAnnotatedProperties($properties, [], $map, $errors, $seen);
        }

        return ['map' => $map, 'errors' => $errors];
    }

    /**
     * Recursive worker for collectAnnotations(): validates annotations on
     * this level's properties and descends into nested object properties
     * (annotations MAY be applied at any nesting depth).
     *
     * @param array<string, mixed> $properties
     * @param list<string> $prefix
     * @param array<string, array{annotation: string, type: string, segments: list<string>}> $map
     * @param list<string> $errors
     * @param array<string, string> $seen
     */
    private static function scanAnnotatedProperties(array $properties, array $prefix, array &$map, array &$errors, array &$seen): void
    {
        foreach ($properties as $property => $schema) {
            if (!is_array($schema)) {
                continue;
            }
            $segments = array_merge($prefix, [(string) $property]);
            $label = implode('.', $segments);

            if (array_key_exists(self::SCHEMA_ANNOTATION, $schema)) {
                $annotation = $schema[self::SCHEMA_ANNOTATION];
                $type = $schema['type'] ?? null;
                if (!is_string($annotation) || $annotation === '') {
                    $errors[] = "x-mcp-header on '$label' must be a non-empty string";
                } elseif (!self::isValidAnnotationName($annotation)) {
                    $errors[] = "x-mcp-header '$annotation' on '$label' is not a valid header token";
                } elseif ($type === 'number') {
                    $errors[] = "x-mcp-header on '$label' is not permitted on type 'number' (SEP-2243 allows string, integer, boolean)";
                } elseif (!is_string($type) || !in_array($type, ['string', 'integer', 'boolean'], true)) {
                    $errors[] = "x-mcp-header on '$label' requires a primitive type (string, integer, boolean), got "
                        . (is_string($type) ? "'$type'" : 'none');
                } else {
                    $lower = strtolower($annotation);
                    if (isset($seen[$lower])) {
                        $errors[] = "x-mcp-header '$annotation' duplicates '{$seen[$lower]}' (names are case-insensitively unique)";
                    } else {
                        $seen[$lower] = $annotation;
                        $map[$label] = [
                            'annotation' => $annotation,
                            'type' => $type,
                            'segments' => $segments,
                        ];
                    }
                }
            }

            $nested = $schema['properties'] ?? null;
            if (is_array($nested)) {
                self::scanAnnotatedProperties($nested, $segments, $map, $errors, $seen);
            }
        }
    }

    /**
     * Resolve the argument value a (possibly nested) annotation path
     * designates. Returns [found, value]; found is false when any path
     * segment is missing.
     *
     * @param array<string, mixed> $arguments
     * @param list<string> $segments
     * @return array{0: bool, 1: mixed}
     */
    public static function argumentAtPath(array $arguments, array $segments): array
    {
        $cursor = $arguments;
        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return [false, null];
            }
            $cursor = $cursor[$segment];
        }
        return [true, $cursor];
    }

    /**
     * Compare a decoded Mcp-Param-* header value against the body argument
     * it mirrors. Integer/number parameters are compared numerically (the
     * spec: `42.0` matches `42`); booleans expect lowercase true/false;
     * strings compare byte-for-byte.
     */
    public static function paramValueMatches(string $decodedHeader, mixed $bodyValue, string $schemaType): bool
    {
        if ($schemaType === 'integer') {
            if (!is_int($bodyValue) && !is_float($bodyValue)) {
                return false;
            }
            if (!is_numeric($decodedHeader)) {
                return false;
            }
            return abs(((float) $decodedHeader) - ((float) $bodyValue)) < 1e-9;
        }
        if ($schemaType === 'boolean') {
            if (!is_bool($bodyValue)) {
                return false;
            }
            return $decodedHeader === ($bodyValue ? 'true' : 'false');
        }
        return is_string($bodyValue) && $decodedHeader === $bodyValue;
    }
}
