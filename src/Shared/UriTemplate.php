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
 * Filename: Shared/UriTemplate.php
 */

declare(strict_types=1);

namespace Mcp\Shared;

use InvalidArgumentException;

/**
 * Minimal, dependency-free URI template matcher.
 *
 * Supports a deliberately small subset of RFC 6570 sufficient for MCP resource
 * templates. The template is compiled once, in the constructor, into a single
 * anchored regular expression that relies solely on ext-pcre and core string
 * functions (cPanel/Apache safe — no extra extensions, no PHP 8.2+ regex
 * features).
 *
 * Supported syntax:
 *  - Level 1 simple expansion {var}: matches a SINGLE path segment, i.e. one or
 *    more characters that are not "/". Compiled to (?P<var>[^/]+).
 *  - Reserved operator {+var}: matches greedily INCLUDING "/", for
 *    filesystem-like templates such as file:///{+path}. Compiled to (?P<var>.+).
 *
 * Segment semantics (important): {var} will NOT match a value containing "/".
 * The spec's own example file:///{path} therefore only matches a single-segment
 * path under a strict reading — authors who need multi-segment values (real file
 * paths) must use {+path}.
 *
 * Explicitly out of scope and REJECTED at construction with an
 * InvalidArgumentException (rather than silently demoted to literal text):
 *  - Other RFC 6570 operators: {#var}, {.var}, {/var}, {;var}, {?query},
 *    {&var}, {=var}.
 *  - Prefix/explode modifiers: {var:3}, {var*}.
 *  - Comma-separated multi-variable expressions: {a,b}.
 *
 * Rationale: silently treating an unsupported operator as a literal would let a
 * server advertise (via resources/templates/list) a template the read path can
 * never match — an advertised-but-unreadable resource. Failing fast at
 * registration is the safer contract.
 *
 * The class holds no state and performs no I/O, making it trivially unit
 * testable and safe under the SDK's synchronous execution model.
 */
final class UriTemplate
{
    /** @var string The compiled, anchored regex (delimiter "#"). */
    private readonly string $regex;

    /** @var string[] Variable names declared in the template, in order. */
    private readonly array $varNames;

    public function __construct(string $template)
    {
        [$this->regex, $this->varNames] = $this->compile($template);
    }

    /**
     * Does the given concrete URI match this template?
     */
    public function matches(string $uri): bool
    {
        return preg_match($this->regex, $uri) === 1;
    }

    /**
     * Extract the variable values from a concrete URI.
     *
     * @return array<string,string>|null Extracted variables (rawurldecode()'d),
     *         or null if the URI does not match. Returns [] for a literal
     *         template that declares no variables.
     */
    public function extract(string $uri): ?array
    {
        if (preg_match($this->regex, $uri, $captures) !== 1) {
            return null;
        }

        $result = [];
        foreach ($this->varNames as $name) {
            $result[$name] = rawurldecode($captures[$name] ?? '');
        }

        return $result;
    }

    /**
     * The variable names declared in the template, in declaration order.
     *
     * @return string[]
     */
    public function variableNames(): array
    {
        return $this->varNames;
    }

    /**
     * Compile a template string into an anchored regex plus the ordered list of
     * variable names.
     *
     * @return array{0: string, 1: string[]}
     * @throws InvalidArgumentException on an unsupported operator/modifier or a
     *         duplicate variable name.
     */
    private function compile(string $template): array
    {
        $pattern = '';
        $varNames = [];
        $offset = 0;

        // Each {...} token (no nested braces). Anything outside is a literal.
        if (preg_match_all('/\{([^{}]*)\}/', $template, $matches, PREG_OFFSET_CAPTURE) === false) {
            throw new InvalidArgumentException("Invalid URI template: {$template}");
        }

        foreach ($matches[0] as $i => $token) {
            $tokenText = $token[0];
            $tokenPos = $token[1];

            // Literal span preceding this token.
            $literal = substr($template, $offset, $tokenPos - $offset);
            $pattern .= preg_quote($literal, '#');

            $expr = $matches[1][$i][0];
            [$name, $groupBody] = $this->parseExpression($expr, $template);

            if (in_array($name, $varNames, true)) {
                throw new InvalidArgumentException(
                    "Duplicate variable name '{$name}' in URI template: {$template}"
                );
            }

            $varNames[] = $name;
            $pattern .= '(?P<' . $name . '>' . $groupBody . ')';

            $offset = $tokenPos + strlen($tokenText);
        }

        // Trailing literal span.
        $pattern .= preg_quote(substr($template, $offset), '#');

        return ['#^' . $pattern . '$#', $varNames];
    }

    /**
     * Parse a single {...} expression body into a variable name and the regex
     * body for its capturing group.
     *
     * @return array{0: string, 1: string} [variable name, group body regex]
     * @throws InvalidArgumentException if the expression uses an unsupported
     *         operator or modifier.
     */
    private function parseExpression(string $expr, string $template): array
    {
        if ($expr === '') {
            throw new InvalidArgumentException(
                "Empty expression '{}' is not supported in URI template: {$template}"
            );
        }

        $first = $expr[0];

        if ($first === '+') {
            // Reserved expansion: matches everything, including "/".
            $name = substr($expr, 1);
            $this->assertSupportedName($name, $expr, $template);
            return [$name, '.+'];
        }

        // A leading RFC 6570 operator other than "+" is unsupported.
        if (!$this->isNameStart($first)) {
            throw new InvalidArgumentException(
                "Unsupported URI template operator '{$first}' in '{{$expr}}' "
                . "(template: {$template}). Supported syntax is {var} (single "
                . "segment) and {+var} (multi-segment / reserved)."
            );
        }

        // Bare {var}: a name with no operator and no modifier. Any modifier
        // (":" prefix, "*" explode) or comma list lands here and is rejected by
        // the name check.
        $this->assertSupportedName($expr, $expr, $template);
        return [$expr, '[^/]+'];
    }

    /**
     * Validate that $name is a plain variable name (and thus a legal PCRE named
     * group). Rejects modifiers, comma lists, and dotted/percent-encoded names.
     *
     * @throws InvalidArgumentException
     */
    private function assertSupportedName(string $name, string $expr, string $template): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(
                "Unsupported URI template expression '{{$expr}}' in template: "
                . "{$template}. Modifiers (e.g. {var:3}, {var*}), multi-variable "
                . "lists (e.g. {a,b}) and non-simple names are not supported; use "
                . "{var} (single segment) or {+var} (multi-segment / reserved)."
            );
        }
    }

    /**
     * Is $char a legal first character for a simple variable name?
     */
    private function isNameStart(string $char): bool
    {
        return preg_match('/[A-Za-z_]/', $char) === 1;
    }
}
