<?php

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Graceful end-of-subscription response to `subscriptions/listen`
 * (SEP-2575, revision 2026-07-28; added post-RC by spec PR #2953).
 *
 * When the server ends a subscription on its own initiative (for example,
 * during shutdown, or when an HTTP listen stream's lifetime budget
 * elapses), it SHOULD respond to the original `subscriptions/listen`
 * request with this result before closing the channel. The response
 * signals that the subscription ended gracefully — as opposed to an abrupt
 * transport drop, which carries no response and which the client MAY treat
 * as a trigger to reconnect.
 *
 * Wire shape: `{ "resultType": "complete", "_meta": {
 * "io.modelcontextprotocol/subscriptionId": <id> } }` — the required
 * `_meta` key is typed RequestId in the schema and MUST equal the JSON-RPC
 * id of the originating listen request (and therefore this response's own
 * id), preserving the original wire type: an integer listen id is carried
 * as a JSON number, a string id as a JSON string — never stringified.
 */
class SubscriptionsListenResult extends Result {
    public function __construct(string|int $subscriptionId) {
        $meta = new Meta();
        $meta->setField(MetaKeys::SUBSCRIPTION_ID, $subscriptionId);
        parent::__construct($meta);
        $this->resultType = Result::RESULT_TYPE_COMPLETE;
    }

    /**
     * The subscription-correlation id carried in `_meta`
     * (`io.modelcontextprotocol/subscriptionId`), in its original
     * RequestId wire type.
     */
    public function subscriptionId(): string|int {
        $value = $this->_meta?->getField(MetaKeys::SUBSCRIPTION_ID);
        return is_string($value) || is_int($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromResponseData(array $data): self {
        $meta = isset($data['_meta']) && is_array($data['_meta']) ? $data['_meta'] : [];
        $id = $meta[MetaKeys::SUBSCRIPTION_ID] ?? '';
        return new self(is_string($id) || is_int($id) ? $id : '');
    }

    public function jsonSerialize(): mixed {
        return [
            'resultType' => Result::RESULT_TYPE_COMPLETE,
            '_meta' => $this->_meta,
        ];
    }
}
