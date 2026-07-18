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
 * Filename: Types/DiscoverRequest.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * The `server/discover` request (SEP-2575, protocol revision 2026-07-28).
 *
 * Replaces the metadata half of the legacy initialize handshake: servers MUST
 * implement it, and clients MAY call it to learn the server's supported
 * protocol versions, capabilities, and implementation info before (or
 * instead of) other requests.
 *
 * Like every modern request, its params carry the required `_meta` envelope:
 * protocol version and client capabilities are mandatory, client info is a
 * SHOULD since spec PR #3002 (see {@see MetaKeys}). Envelope validation
 * happens server-side so a malformed request can be answered with the
 * spec's -32602 error rather than failing to parse.
 */
class DiscoverRequest extends Request {
    public function __construct(?RequestParams $params = null) {
        parent::__construct('server/discover', $params);
    }
}
