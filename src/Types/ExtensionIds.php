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
 * Filename: Types/ExtensionIds.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Reverse-DNS identifiers for MCP extensions declared through the SEP-2133
 * extensions-capability framework (revision 2026-07-28).
 *
 * An extension is advertised as a key under `capabilities.extensions` whose
 * value is an object of extension-specific settings; the empty object `{}`
 * means "supported, no settings". Clients declare the extensions they
 * support per-request in the `_meta` clientCapabilities envelope; servers
 * advertise theirs in the `server/discover` result.
 */
final class ExtensionIds {
    /**
     * The Tasks extension (SEP-2663, repository `modelcontextprotocol/
     * ext-tasks`). Its capability value is the empty object `{}` — no
     * extension-specific settings are defined.
     */
    public const TASKS = 'io.modelcontextprotocol/tasks';

    /**
     * The MCP Apps extension (SEP-1865, repository `modelcontextprotocol/
     * ext-apps`, stable revision `2026-01-26`). The capability value carries
     * a `mimeTypes` array naming the UI template profiles in play — it MUST
     * include {@see \Mcp\Server\McpServer::UI_MIME_TYPE}
     * (`text/html;profile=mcp-app`), the only profile defined in the MVP.
     *
     * The extension adds NO new RPC methods: a server declares it, registers
     * `ui://` template resources, and links them to tools through the
     * `_meta.ui` metadata; UI-originated interactions arrive as ordinary
     * `tools/call` requests. The host↔iframe `ui/*` postMessage envelope is
     * entirely host-side and never reaches the server.
     */
    public const UI = 'io.modelcontextprotocol/ui';

    private function __construct() {
    }
}
