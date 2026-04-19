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

namespace Mcp\Tests\Server\Transport\Http;

use PHPUnit\Framework\TestCase;
use Mcp\Server\Transport\Http\Config;

/**
 * Tests for HTTP transport Config defaults.
 *
 * Guards against silent behavior changes in `enable_sse`, which governs
 * whether POST responses come back as `text/event-stream` or `application/json`.
 * Because both media types are spec-compliant (2025-11-25 Streamable HTTP),
 * the wire format must follow the user's explicit opt-in — never auto-detect.
 */
final class ConfigTest extends TestCase
{
    /**
     * A bare Config must leave SSE disabled, matching the class-level comment
     * ("Default disabled for compatibility") and the documented default in
     * docs/server-dev.md.
     */
    public function testDefaultEnableSseIsFalse(): void
    {
        $config = new Config([]);
        $this->assertFalse($config->isSseEnabled());
    }

    /**
     * Explicit opt-in must be honored. This is the path exercised by
     * conformance/everything-server.php and every SSE test in the suite.
     */
    public function testExplicitEnableSseTrueIsRespected(): void
    {
        $config = new Config(['enable_sse' => true]);
        $this->assertTrue($config->isSseEnabled());
    }

    /**
     * With `auto_detect` on (the default), `enable_sse` must NOT be silently
     * flipped to true by environment probing. The wire Content-Type of POST
     * responses (JSON vs. text/event-stream) is user-visible under spec-
     * compliant clients, so it must only change when the caller explicitly
     * opts in.
     */
    public function testAutoDetectDoesNotEnableSse(): void
    {
        $config = new Config(['auto_detect' => true]);
        $this->assertFalse($config->isSseEnabled());
    }

    /**
     * An explicit `enable_sse => false` must win even when `auto_detect` is
     * on, so callers can reliably force JSON-only responses.
     */
    public function testExplicitEnableSseFalseBeatsAutoDetect(): void
    {
        $config = new Config(['auto_detect' => true, 'enable_sse' => false]);
        $this->assertFalse($config->isSseEnabled());
    }
}
