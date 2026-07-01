<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Filename: tests/Shared/McpHeadersTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Shared;

use Mcp\Shared\McpHeaders;
use PHPUnit\Framework\TestCase;

/**
 * SEP-2243 shared header rules: Mcp-Name source mapping, the Mcp-Param-*
 * value encoding (base64 sentinel for unsafe values, case-sensitive
 * lowercase sentinel per spec PR #2937), and x-mcp-header annotation
 * validation (token charset, primitive types, case-insensitive
 * uniqueness).
 */
final class McpHeadersTest extends TestCase
{
    public function testNameSourceMapping(): void
    {
        $this->assertSame('add', McpHeaders::expectedNameValue('tools/call', ['name' => 'add']));
        $this->assertSame('greet', McpHeaders::expectedNameValue('prompts/get', ['name' => 'greet']));
        $this->assertSame(
            'file:///path/to/file%20name.txt',
            McpHeaders::expectedNameValue('resources/read', ['uri' => 'file:///path/to/file%20name.txt']),
            'URIs are mirrored verbatim, never re-encoded'
        );
        $this->assertSame('task-1', McpHeaders::expectedNameValue('tasks/get', ['taskId' => 'task-1']));
        $this->assertNull(McpHeaders::expectedNameValue('tools/list', ['name' => 'x']));
        $this->assertNull(McpHeaders::expectedNameValue('tools/call', null));
    }

    public function testEncodeParamValueTypes(): void
    {
        $this->assertSame('42', McpHeaders::encodeParamValue(42));
        $this->assertSame('3.14159', McpHeaders::encodeParamValue(3.14159));
        $this->assertSame('true', McpHeaders::encodeParamValue(true));
        $this->assertSame('false', McpHeaders::encodeParamValue(false));
        $this->assertSame('us-west1', McpHeaders::encodeParamValue('us-west1'));
        $this->assertSame('', McpHeaders::encodeParamValue(''), 'Empty string stays an empty header value');
        $this->assertSame('us west 1', McpHeaders::encodeParamValue('us west 1'), 'Internal spaces stay plain');
    }

    public function testEncodeParamValueBase64Cases(): void
    {
        // Leading/trailing whitespace, non-ASCII, control chars → wrapped.
        foreach ([' padded ', ' lead', 'trail ', "\tindented", "line1\nline2", "line1\r\nline2", 'Hello, 世界'] as $value) {
            $encoded = McpHeaders::encodeParamValue($value);
            $this->assertMatchesRegularExpression('/^=\?base64\?.*\?=$/', $encoded, "'$value' must be wrapped");
            $this->assertSame($value, McpHeaders::decodeParamValue($encoded), 'Round-trip');
        }
        // A literal that itself matches the sentinel must be wrapped too.
        $tricky = '=?base64?SGVsbG8=?=';
        $encoded = McpHeaders::encodeParamValue($tricky);
        $this->assertNotSame($tricky, $encoded);
        $this->assertSame($tricky, McpHeaders::decodeParamValue($encoded));
        // The sentinel is case-sensitive (spec PR #2937): a non-lowercase
        // prefix is already an unambiguous literal, so it is emitted as-is
        // and round-trips through the case-sensitive decoder untouched.
        $upper = '=?BASE64?SGVsbG8=?=';
        $this->assertSame($upper, McpHeaders::encodeParamValue($upper));
        $this->assertSame($upper, McpHeaders::decodeParamValue($upper));
    }

    public function testDecodeStrictness(): void
    {
        $this->assertSame('Hello', McpHeaders::decodeParamValue('=?base64?SGVsbG8=?='));
        $this->assertSame('=?BASE64?SGVsbG8=?=', McpHeaders::decodeParamValue('=?BASE64?SGVsbG8=?='), 'Non-lowercase prefix is a literal value, never decoded (spec PR #2937)');
        $this->assertSame('=?Base64?SGVsbG8=?=', McpHeaders::decodeParamValue('=?Base64?SGVsbG8=?='), 'Mixed-case prefix is a literal value (spec PR #2937)');
        $this->assertNull(McpHeaders::decodeParamValue('=?base64?SGVsbG8?='), 'Bad padding rejected');
        $this->assertNull(McpHeaders::decodeParamValue('=?base64?SGVs!!!bG8=?='), 'Invalid characters rejected');
        $this->assertSame('SGVsbG8=', McpHeaders::decodeParamValue('SGVsbG8='), 'No wrapper → literal');
        $this->assertSame('=?base64?SGVsbG8=', McpHeaders::decodeParamValue('=?base64?SGVsbG8='), 'Incomplete wrapper → literal');
    }

    public function testAnnotationValidation(): void
    {
        $schema = [
            'properties' => [
                'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                'priority' => ['type' => 'integer', 'x-mcp-header' => 'Priority'],
                'verbose' => ['type' => 'boolean', 'x-mcp-header' => 'Verbose'],
                'plain' => ['type' => 'string'],
            ],
        ];
        $result = McpHeaders::collectAnnotations($schema);
        $this->assertSame([], $result['errors']);
        $this->assertSame(['region', 'priority', 'verbose'], array_keys($result['map']));
        $this->assertSame('Region', $result['map']['region']['annotation']);
        $this->assertSame('integer', $result['map']['priority']['type']);
        $this->assertSame(['verbose'], $result['map']['verbose']['segments']);
    }

    public function testAnnotationsCollectedAtAnyNestingDepth(): void
    {
        // SEP-2243: x-mcp-header MAY be applied at any nesting depth;
        // uniqueness is case-insensitive across the WHOLE schema.
        $schema = [
            'properties' => [
                'config' => [
                    'type' => 'object',
                    'properties' => [
                        'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                    ],
                ],
                'top' => ['type' => 'string', 'x-mcp-header' => 'Top'],
            ],
        ];
        $result = McpHeaders::collectAnnotations($schema);
        $this->assertSame([], $result['errors']);
        $this->assertArrayHasKey('config.region', $result['map']);
        $this->assertSame(['config', 'region'], $result['map']['config.region']['segments']);

        // Duplicate across nesting levels is still a violation.
        $schema['properties']['top']['x-mcp-header'] = 'region';
        $this->assertNotSame([], McpHeaders::collectAnnotations($schema)['errors']);

        // argumentAtPath resolves the nested value.
        [$found, $value] = McpHeaders::argumentAtPath(
            ['config' => ['region' => 'us-west1']],
            ['config', 'region']
        );
        $this->assertTrue($found);
        $this->assertSame('us-west1', $value);
        [$found] = McpHeaders::argumentAtPath(['config' => []], ['config', 'region']);
        $this->assertFalse($found);
    }

    public function testNumberTypeAnnotationIsProhibited(): void
    {
        // SEP-2243 final text: string, integer, boolean only — `number`
        // is explicitly not permitted (the pinned alpha conformance tool
        // predates this restriction; see the draft baseline).
        $result = McpHeaders::collectAnnotations([
            'properties' => ['f' => ['type' => 'number', 'x-mcp-header' => 'F']],
        ]);
        $this->assertSame([], $result['map']);
        $this->assertNotSame([], $result['errors']);
        $this->assertStringContainsString("type 'number'", $result['errors'][0]);
    }

    public function testSafeIntegerBounds(): void
    {
        $this->assertTrue(McpHeaders::isSafeInteger(McpHeaders::MAX_SAFE_INTEGER));
        $this->assertTrue(McpHeaders::isSafeInteger(-McpHeaders::MAX_SAFE_INTEGER));
        $this->assertFalse(McpHeaders::isSafeInteger(McpHeaders::MAX_SAFE_INTEGER + 1));
        $this->assertFalse(McpHeaders::isSafeInteger(-McpHeaders::MAX_SAFE_INTEGER - 1));
    }

    public function testSafeIntegerValueCoversFloatRepresentations(): void
    {
        // Large JSON integers decode as floats in PHP — the bound and
        // integrality apply to them too.
        $this->assertTrue(McpHeaders::isSafeIntegerValue(42));
        $this->assertTrue(McpHeaders::isSafeIntegerValue(42.0));
        $this->assertTrue(McpHeaders::isSafeIntegerValue((float) McpHeaders::MAX_SAFE_INTEGER));
        $this->assertFalse(McpHeaders::isSafeIntegerValue(9.1e15), 'Beyond 2^53 - 1');
        $this->assertFalse(McpHeaders::isSafeIntegerValue(-9.1e15));
        $this->assertFalse(McpHeaders::isSafeIntegerValue(42.5), 'Non-integral');
        $this->assertFalse(McpHeaders::isSafeIntegerValue(INF));
        $this->assertFalse(McpHeaders::isSafeIntegerValue(-INF));
        $this->assertFalse(McpHeaders::isSafeIntegerValue(NAN));
        $this->assertFalse(McpHeaders::isSafeIntegerValue(McpHeaders::MAX_SAFE_INTEGER + 1));
    }

    /**
     * Every annotation-constraint violation class the conformance suite's
     * http-invalid-tool-headers scenario enumerates must be detected.
     */
    public function testAnnotationViolations(): void
    {
        $violations = [
            'empty' => ['type' => 'string', 'x-mcp-header' => ''],
            'object type' => ['type' => 'object', 'x-mcp-header' => 'Obj'],
            'array type' => ['type' => 'array', 'x-mcp-header' => 'Arr'],
            'null type' => ['type' => 'null', 'x-mcp-header' => 'Nul'],
            'number type' => ['type' => 'number', 'x-mcp-header' => 'Num'],
            'space in name' => ['type' => 'string', 'x-mcp-header' => 'My Region'],
            'colon in name' => ['type' => 'string', 'x-mcp-header' => 'Region:Primary'],
            'non-ascii name' => ['type' => 'string', 'x-mcp-header' => 'Région'],
            'control char name' => ['type' => 'string', 'x-mcp-header' => "Region\t1"],
        ];
        foreach ($violations as $label => $property) {
            $result = McpHeaders::collectAnnotations(['properties' => ['p' => $property]]);
            $this->assertNotSame([], $result['errors'], "Violation not detected: $label");
            $this->assertSame([], $result['map'], "Invalid annotation must not land in the map: $label");
        }

        // Case-insensitive duplicates.
        $result = McpHeaders::collectAnnotations(['properties' => [
            'a' => ['type' => 'string', 'x-mcp-header' => 'MyField'],
            'b' => ['type' => 'string', 'x-mcp-header' => 'myfield'],
        ]]);
        $this->assertNotSame([], $result['errors']);
    }

    public function testParamValueMatching(): void
    {
        $this->assertTrue(McpHeaders::paramValueMatches('42', 42, 'integer'));
        $this->assertTrue(McpHeaders::paramValueMatches('42.0', 42, 'integer'), 'Numeric comparison per spec');
        $this->assertFalse(McpHeaders::paramValueMatches('43', 42, 'integer'));
        $this->assertTrue(McpHeaders::paramValueMatches('true', true, 'boolean'));
        $this->assertFalse(McpHeaders::paramValueMatches('false', true, 'boolean'));
        $this->assertTrue(McpHeaders::paramValueMatches('abc', 'abc', 'string'));
        $this->assertFalse(McpHeaders::paramValueMatches('ABC', 'abc', 'string'), 'Values are case-sensitive');
    }
}
