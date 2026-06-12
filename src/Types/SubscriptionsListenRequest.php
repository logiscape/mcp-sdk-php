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
 * Filename: Types/SubscriptionsListenRequest.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * The `subscriptions/listen` request (SEP-2575, revision 2026-07-28).
 *
 * Opens the long-lived server→client notification channel of the stateless
 * revision, replacing the legacy standalone GET SSE stream and the removed
 * `resources/subscribe` RPC. The required `notifications` param (a
 * {@see SubscriptionFilter}) opts in to specific change-notification types;
 * the server answers with `notifications/subscriptions/acknowledged` as the
 * FIRST message on the stream and never sends a JSON-RPC response — the
 * stream itself is the response, terminated by transport close (HTTP) or
 * `notifications/cancelled` (stdio).
 *
 * Every notification on the channel (including the acknowledgement) carries
 * `_meta["io.modelcontextprotocol/subscriptionId"]` = the stringified
 * JSON-RPC id of this request, so stdio clients can demultiplex concurrent
 * subscriptions.
 */
class SubscriptionsListenRequest extends Request {
    public function __construct(?RequestParams $params = null) {
        parent::__construct('subscriptions/listen', $params);
    }
}
