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
 * Filename: Types/MetaKeys.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Reserved `_meta` key names defined by the MCP specification.
 *
 * The 2026-07-28 revision (SEP-2575) replaces the initialize handshake with a
 * per-request envelope carried in `params._meta`: every modern request MUST
 * include the protocol version and client capabilities under the
 * `io.modelcontextprotocol/`-prefixed keys below, and SHOULD include the
 * client info (optional since spec PR #3002).
 *
 * The bare `traceparent`, `tracestate`, and `baggage` keys are a documented
 * exception to the spec's DNS-prefix convention for `_meta` keys (SEP-414):
 * they are reserved for W3C Trace Context / Baggage propagation and MUST NOT
 * be namespaced, because renaming them would break trace correlation.
 */
final class MetaKeys {
    /** Protocol revision for this request (string, required on modern requests). */
    public const PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';

    /**
     * Client implementation info for this request (Implementation).
     *
     * A SHOULD since spec PR #3002: modern requests are valid without it,
     * but a present value must still be a well-formed Implementation.
     */
    public const CLIENT_INFO = 'io.modelcontextprotocol/clientInfo';

    /** Client capabilities for this request (ClientCapabilities, required on modern requests). */
    public const CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';

    /**
     * Server implementation info on a result's `_meta` (spec PR #3002).
     *
     * Servers SHOULD include this on every 2026-07-28 response unless
     * specifically configured not to do so. The value is self-reported and
     * unverified: clients SHOULD NOT change behavior or make security
     * decisions based on it — display and diagnostics only.
     */
    public const SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

    /**
     * Desired log level for this request (optional; replaces logging/setLevel
     * under 2026-07-28).
     *
     * @deprecated Deprecated from introduction as of protocol version
     *             2026-07-28 (SEP-2577 deprecates the Logging feature
     *             wholesale). The key remains the only way modern requests
     *             opt in to notifications/message for at least the
     *             twelve-month window; migrate to stderr logging for stdio
     *             transports and OpenTelemetry for observability.
     */
    public const LOG_LEVEL = 'io.modelcontextprotocol/logLevel';

    /** Subscription correlation id on notifications delivered via subscriptions/listen. */
    public const SUBSCRIPTION_ID = 'io.modelcontextprotocol/subscriptionId';

    /** W3C Trace Context traceparent (SEP-414, reserved unprefixed key). */
    public const TRACEPARENT = 'traceparent';

    /** W3C Trace Context tracestate (SEP-414, reserved unprefixed key). */
    public const TRACESTATE = 'tracestate';

    /** W3C Baggage (SEP-414, reserved unprefixed key). */
    public const BAGGAGE = 'baggage';

    private function __construct() {
    }
}
