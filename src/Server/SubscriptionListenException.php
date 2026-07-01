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
 * Filename: Server/SubscriptionListenException.php
 */

declare(strict_types=1);

namespace Mcp\Server;

use Mcp\Types\RequestId;
use Mcp\Types\SubscriptionFilter;

/**
 * Control-flow signal raised when a validated `subscriptions/listen`
 * request arrives on the HTTP path (SEP-2575, revision 2026-07-28).
 *
 * The listen request's "response" is a long-lived SSE stream of
 * notifications that only the runner (which owns the SAPI output adapter)
 * can produce — the one JSON-RPC result it can ever receive is the
 * graceful end-of-subscription SubscriptionsListenResult the runner emits
 * when the server ends the stream on its own initiative (spec PR #2953).
 * HttpServerSession therefore
 * validates the request, computes the filter subset the server agrees to
 * honor, and throws this exception up through message processing;
 * HttpServerRunner catches it and runs the streaming loop: the
 * `notifications/subscriptions/acknowledged` first frame, then bus-polled
 * change notifications, every frame tagged with the subscription id.
 */
class SubscriptionListenException extends \RuntimeException
{
    public function __construct(
        public readonly RequestId $requestId,
        public readonly SubscriptionFilter $agreedFilter,
    ) {
        parent::__construct('subscriptions/listen stream requested');
    }

    /**
     * The stringified listen request id — a bookkeeping key, NOT the wire
     * value. On the wire, `io.modelcontextprotocol/subscriptionId` is
     * typed RequestId and MUST carry the listen id in its original
     * JSON-RPC type (use `$this->requestId->getValue()` for that).
     */
    public function subscriptionId(): string
    {
        return (string) $this->requestId->getValue();
    }
}
