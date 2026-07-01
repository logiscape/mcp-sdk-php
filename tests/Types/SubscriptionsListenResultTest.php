<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Filename: tests/Types/SubscriptionsListenResultTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Types;

use Mcp\Types\MetaKeys;
use Mcp\Types\Result;
use Mcp\Types\SubscriptionsListenResult;
use PHPUnit\Framework\TestCase;

/**
 * SubscriptionsListenResult (SEP-2575, spec PR #2953): the graceful
 * end-of-subscription response to a `subscriptions/listen` request. Wire
 * shape is exactly { "resultType": "complete", "_meta": {
 * "io.modelcontextprotocol/subscriptionId": <id> } }. The _meta key is
 * typed RequestId in the schema and MUST equal the listen request's
 * JSON-RPC id — and therefore this response's own id — in its ORIGINAL
 * wire type: an integer id stays a JSON number, never stringified.
 */
final class SubscriptionsListenResultTest extends TestCase
{
    public function testSerializesToSpecWireShape(): void
    {
        $result = new SubscriptionsListenResult('listen-1');

        $wire = json_decode((string) json_encode($result), true);

        $this->assertSame(Result::RESULT_TYPE_COMPLETE, $wire['resultType']);
        $this->assertSame('listen-1', $wire['_meta'][MetaKeys::SUBSCRIPTION_ID]);
        $this->assertSame(['resultType', '_meta'], array_keys($wire), 'No other fields on the wire');
        $this->assertSame([MetaKeys::SUBSCRIPTION_ID], array_keys($wire['_meta']), 'The required _meta key only');
    }

    public function testIntegerIdIsPreservedAsJsonNumber(): void
    {
        // Schema: the _meta key is RequestId and equals the response id —
        // typed equality. An integer listen id 51 must serialize as the
        // JSON number 51, never the string "51".
        $result = new SubscriptionsListenResult(51);

        $wire = json_decode((string) json_encode($result), true);

        $this->assertSame(51, $wire['_meta'][MetaKeys::SUBSCRIPTION_ID]);
        $this->assertSame(51, $result->subscriptionId());
    }

    public function testRoundTripsThroughFromResponseData(): void
    {
        foreach (['listen-42', 42] as $id) {
            $original = new SubscriptionsListenResult($id);
            $wire = json_decode((string) json_encode($original), true);

            $restored = SubscriptionsListenResult::fromResponseData($wire);

            $this->assertSame($id, $restored->subscriptionId(), 'Original wire type survives the round trip');
            $this->assertSame($wire, json_decode((string) json_encode($restored), true));
        }
    }
}
