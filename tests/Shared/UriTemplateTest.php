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
 */

declare(strict_types=1);

namespace Mcp\Tests\Shared;

use InvalidArgumentException;
use Mcp\Shared\UriTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the {@see UriTemplate} matcher (RFC 6570 Level 1 + reserved
 * {+var} subset).
 */
final class UriTemplateTest extends TestCase
{
    /**
     * A simple {id} variable matches a single segment and extracts its value.
     */
    public function testSimpleVariableExtractsSingleSegment(): void
    {
        $template = new UriTemplate('test://template/{id}/data');

        $this->assertTrue($template->matches('test://template/abc/data'));
        $this->assertSame(['id' => 'abc'], $template->extract('test://template/abc/data'));
    }

    /**
     * A simple {id} variable must NOT span a "/" — an extra segment in the
     * variable slot fails to match (documents the single-segment contract).
     */
    public function testSimpleVariableDoesNotSpanSlash(): void
    {
        $template = new UriTemplate('test://template/{id}/data');

        $this->assertFalse($template->matches('test://template/abc/def/data'));
        $this->assertNull($template->extract('test://template/abc/def/data'));
    }

    /**
     * The reserved {+path} operator matches greedily, including "/".
     */
    public function testReservedOperatorMatchesMultipleSegments(): void
    {
        $template = new UriTemplate('file:///{+path}');

        $this->assertTrue($template->matches('file:///a/b/c.txt'));
        $this->assertSame(['path' => 'a/b/c.txt'], $template->extract('file:///a/b/c.txt'));
    }

    /**
     * Multiple variables in a single template are each extracted by name.
     */
    public function testMultipleVariables(): void
    {
        $template = new UriTemplate('db://{table}/{id}');

        $this->assertSame(
            ['table' => 'users', 'id' => '42'],
            $template->extract('db://users/42')
        );
    }

    /**
     * A URI whose literal/scheme portion differs does not match.
     */
    public function testNonMatchingLiteralReturnsNull(): void
    {
        $template = new UriTemplate('test://template/{id}/data');

        $this->assertFalse($template->matches('other://template/abc/data'));
        $this->assertNull($template->extract('other://template/abc/data'));
    }

    /**
     * Percent-encoded values are rawurldecode()'d on extraction.
     */
    public function testPercentEncodedValueIsDecoded(): void
    {
        $template = new UriTemplate('test://{name}');

        $this->assertSame(['name' => 'hello world'], $template->extract('test://hello%20world'));
    }

    /**
     * A literal-only template (no braces) matches exactly and extracts [].
     */
    public function testLiteralOnlyTemplate(): void
    {
        $template = new UriTemplate('info://php');

        $this->assertTrue($template->matches('info://php'));
        $this->assertSame([], $template->extract('info://php'));
        $this->assertFalse($template->matches('info://python'));
        $this->assertSame([], $template->variableNames());
    }

    /**
     * Regex metacharacters in the literal portion are treated literally.
     */
    public function testLiteralMetacharactersAreEscaped(): void
    {
        $template = new UriTemplate('test://a.b/{id}');

        $this->assertSame(['id' => 'x'], $template->extract('test://a.b/x'));
        // The "." must be literal, not "any character".
        $this->assertNull($template->extract('test://aXb/x'));
    }

    /**
     * A duplicate variable name is rejected at construction.
     */
    public function testDuplicateVariableNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UriTemplate('test://{id}/{id}');
    }

    /**
     * variableNames() returns the declared names in order.
     */
    public function testVariableNamesInOrder(): void
    {
        $template = new UriTemplate('db://{table}/{id}/{field}');

        $this->assertSame(['table', 'id', 'field'], $template->variableNames());
    }

    /**
     * Every unsupported RFC 6570 operator/modifier throws from the constructor
     * (rec #4 — never advertise a template the read path cannot match).
     *
     * @dataProvider unsupportedTemplates
     */
    public function testUnsupportedExpressionThrows(string $template): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UriTemplate($template);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsupportedTemplates(): array
    {
        return [
            'fragment {#var}'   => ['test://{#var}'],
            'query {?q}'        => ['test://x{?q}'],
            'path {/p}'         => ['test://x{/p}'],
            'label {.x}'        => ['test://x{.x}'],
            'param {;m}'        => ['test://x{;m}'],
            'continuation {&n}' => ['test://x{&n}'],
            'multi {a,b}'       => ['test://{a,b}'],
            'prefix {var:3}'    => ['test://{var:3}'],
            'explode {var*}'    => ['test://{var*}'],
            'empty {}'          => ['test://{}'],
        ];
    }

    /**
     * The two supported forms do NOT throw at construction.
     */
    public function testSupportedFormsDoNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        new UriTemplate('test://{var}');
        new UriTemplate('test://{+var}');
    }
}
