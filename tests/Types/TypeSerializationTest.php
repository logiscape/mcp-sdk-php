<?php

declare(strict_types=1);

namespace Mcp\Tests\Types;

use Mcp\Types\CallToolResult;
use Mcp\Types\CancelledNotification;
use Mcp\Types\ClientNotification;
use Mcp\Types\ProgressNotification;
use Mcp\Types\ResourceUpdatedNotification;
use Mcp\Types\ServerNotification;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\TextContent;
use Mcp\Types\ImageContent;
use Mcp\Types\InitializeResult;
use Mcp\Types\RequestId;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\Implementation;
use Mcp\Types\Result;
use Mcp\Types\EmptyResult;
use Mcp\Types\Meta;
use PHPUnit\Framework\TestCase;

/**
 * Tests wire-format round-trips for critical MCP result types.
 *
 * Each test verifies that a type can be constructed from raw response data
 * (as it would arrive over the wire), produces correctly typed PHP objects,
 * and serializes back to a structure compatible with the wire format.
 */
final class TypeSerializationTest extends TestCase
{
    /**
     * Verify that a CallToolResult with a single text content item can be
     * deserialized from response data and serialized back to a matching structure.
     */
    public function testCallToolResultRoundTrip(): void
    {
        $data = [
            'content' => [['type' => 'text', 'text' => 'hello']],
            'isError' => false,
        ];

        $result = CallToolResult::fromResponseData($data);

        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('hello', $result->content[0]->text);
        $this->assertFalse($result->isError);

        $serialized = $result->jsonSerialize();
        $this->assertArrayHasKey('content', $serialized);
        $this->assertCount(1, $serialized['content']);
        $this->assertSame('hello', $serialized['content'][0]->text);
    }

    /**
     * Verify that a CallToolResult containing both text and image content
     * items correctly deserializes each into its respective typed object.
     */
    public function testCallToolResultMultipleContentTypes(): void
    {
        $data = [
            'content' => [
                ['type' => 'text', 'text' => 'description'],
                ['type' => 'image', 'data' => 'iVBORw0KGgo=', 'mimeType' => 'image/png'],
            ],
            'isError' => false,
        ];

        $result = CallToolResult::fromResponseData($data);

        $this->assertCount(2, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('description', $result->content[0]->text);
        $this->assertInstanceOf(ImageContent::class, $result->content[1]);
        $this->assertSame('iVBORw0KGgo=', $result->content[1]->data);
        $this->assertSame('image/png', $result->content[1]->mimeType);
    }

    /**
     * Verify that the structuredContent field is preserved through
     * deserialization and serialization of a CallToolResult.
     */
    public function testCallToolResultStructuredContent(): void
    {
        $structured = ['key' => 'value', 'nested' => ['a' => 1]];
        $data = [
            'content' => [['type' => 'text', 'text' => 'result']],
            'isError' => false,
            'structuredContent' => $structured,
        ];

        $result = CallToolResult::fromResponseData($data);

        $this->assertSame($structured, $result->structuredContent);

        $serialized = $result->jsonSerialize();
        $this->assertArrayHasKey('structuredContent', $serialized);
        $this->assertSame($structured, $serialized['structuredContent']);
    }

    /**
     * Verify that the isError flag set to true is preserved through
     * the deserialization and serialization round-trip.
     */
    public function testCallToolResultIsErrorFlag(): void
    {
        $data = [
            'content' => [['type' => 'text', 'text' => 'something went wrong']],
            'isError' => true,
        ];

        $result = CallToolResult::fromResponseData($data);

        $this->assertTrue($result->isError);

        $serialized = $result->jsonSerialize();
        $this->assertTrue($serialized['isError']);
    }

    /**
     * Verify that a content item missing the required 'type' field
     * throws an InvalidArgumentException during deserialization.
     */
    public function testCallToolResultInvalidContentThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $data = [
            'content' => [['text' => 'no type field']],
            'isError' => false,
        ];

        CallToolResult::fromResponseData($data);
    }

    /**
     * Verify that an InitializeResult can be deserialized from response data
     * with protocolVersion, capabilities, and serverInfo, and that the resulting
     * object contains correctly typed nested objects.
     */
    public function testInitializeResultRoundTrip(): void
    {
        $data = [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [],
            'serverInfo' => ['name' => 'test-server', 'version' => '1.0.0'],
        ];

        $result = InitializeResult::fromResponseData($data);

        $this->assertSame('2025-03-26', $result->protocolVersion);
        $this->assertInstanceOf(ServerCapabilities::class, $result->capabilities);
        $this->assertInstanceOf(Implementation::class, $result->serverInfo);
        $this->assertSame('test-server', $result->serverInfo->name);
        $this->assertSame('1.0.0', $result->serverInfo->version);
        $this->assertNull($result->instructions);

        $serialized = $result->jsonSerialize();
        $this->assertSame('2025-03-26', $serialized['protocolVersion']);
    }

    /**
     * Verify that the optional instructions field on InitializeResult is
     * preserved through the deserialization and serialization round-trip.
     */
    public function testInitializeResultWithInstructions(): void
    {
        $data = [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [],
            'serverInfo' => ['name' => 'test-server', 'version' => '1.0.0'],
            'instructions' => 'Use this server for math operations only.',
        ];

        $result = InitializeResult::fromResponseData($data);

        $this->assertSame('Use this server for math operations only.', $result->instructions);

        $serialized = $result->jsonSerialize();
        $this->assertArrayHasKey('instructions', $serialized);
        $this->assertSame('Use this server for math operations only.', $serialized['instructions']);
    }

    /**
     * Verify that an EmptyResult can be constructed from an empty array,
     * producing a valid object with no meta or extra fields.
     */
    public function testEmptyResultFromResponseData(): void
    {
        $result = EmptyResult::fromResponseData([]);

        $this->assertInstanceOf(EmptyResult::class, $result);
        $this->assertNull($result->_meta);
    }

    /**
     * Verify that an EmptyResult preserves _meta data through
     * deserialization and serialization.
     */
    public function testEmptyResultWithMeta(): void
    {
        $data = [
            '_meta' => ['progressToken' => 'abc-123'],
        ];

        $result = EmptyResult::fromResponseData($data);

        $this->assertInstanceOf(EmptyResult::class, $result);
        $this->assertNotNull($result->_meta);
        $this->assertSame('abc-123', $result->_meta->progressToken);

        $serialized = $result->jsonSerialize();
        $this->assertArrayHasKey('_meta', $serialized);
    }

    /**
     * Verify that the base Result class stores unknown/extra fields via
     * ExtraFieldsTrait and makes them accessible as dynamic properties.
     */
    public function testResultPreservesExtraFields(): void
    {
        $data = [
            'customField' => 'custom-value',
            'anotherField' => 42,
        ];

        $result = Result::fromResponseData($data);

        $this->assertSame('custom-value', $result->customField);
        $this->assertSame(42, $result->anotherField);
    }

    /**
     * Verify that the _meta field on a CallToolResult is correctly
     * preserved through fromResponseData deserialization.
     */
    public function testCallToolResultFromResponseDataWithMeta(): void
    {
        $data = [
            'content' => [['type' => 'text', 'text' => 'with meta']],
            'isError' => false,
            '_meta' => ['progressToken' => 'tok-456'],
        ];

        $result = CallToolResult::fromResponseData($data);

        $this->assertNotNull($result->_meta);
        $this->assertInstanceOf(Meta::class, $result->_meta);
        $this->assertSame('tok-456', $result->_meta->progressToken);

        $serialized = $result->jsonSerialize();
        $this->assertArrayHasKey('_meta', $serialized);
    }

    /**
     * Verify that an InitializeResult with an empty protocolVersion string
     * throws an InvalidArgumentException during validation.
     */
    public function testInitializeResultValidationFailsOnEmptyProtocolVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $data = [
            'protocolVersion' => '',
            'capabilities' => [],
            'serverInfo' => ['name' => 'test-server', 'version' => '1.0.0'],
        ];

        InitializeResult::fromResponseData($data);
    }

    /**
     * Verify that jsonSerialize on a CallToolResult produces a 'content'
     * array whose items are Content objects with the correct type and fields.
     */
    public function testCallToolResultJsonSerializeIncludesContent(): void
    {
        $data = [
            'content' => [
                ['type' => 'text', 'text' => 'first'],
                ['type' => 'text', 'text' => 'second'],
            ],
            'isError' => false,
        ];

        $result = CallToolResult::fromResponseData($data);
        $serialized = $result->jsonSerialize();

        $this->assertArrayHasKey('content', $serialized);
        $this->assertCount(2, $serialized['content']);

        // Each item is a TextContent object that itself serializes correctly
        $this->assertInstanceOf(TextContent::class, $serialized['content'][0]);
        $this->assertInstanceOf(TextContent::class, $serialized['content'][1]);
        $this->assertSame('first', $serialized['content'][0]->text);
        $this->assertSame('second', $serialized['content'][1]->text);

        // Verify the nested jsonSerialize produces the expected wire format
        $itemSerialized = $serialized['content'][0]->jsonSerialize();
        $this->assertSame('text', $itemSerialized['type']);
        $this->assertSame('first', $itemSerialized['text']);
    }

    /**
     * Verify that jsonSerialize on a CallToolResult omits the structuredContent
     * field when it is null, keeping the wire format clean.
     */
    public function testCallToolResultJsonSerializeOmitsNullFields(): void
    {
        $data = [
            'content' => [['type' => 'text', 'text' => 'minimal']],
            'isError' => false,
        ];

        $result = CallToolResult::fromResponseData($data);

        $this->assertNull($result->structuredContent);

        $serialized = $result->jsonSerialize();
        $this->assertArrayNotHasKey('structuredContent', $serialized);
    }

    /**
     * Verify the wire format of a sent CancelledNotification.
     *
     * BaseSession::sendNotification() reads $notification->params off the typed
     * notification and copies it into the outgoing JSONRPCNotification. Per the
     * MCP spec, notifications/cancelled MUST carry params.requestId on the wire,
     * so the CancelledNotification constructor mirrors its direct properties
     * into the parent params slot. This test pins down that contract: a default
     * CancelledNotification must serialize to a frame whose params object
     * contains the expected requestId and reason.
     *
     * Without this, a notification produced as `new CancelledNotification(...)`
     * would serialize without a `params` key at all and silently be dropped by
     * spec-compliant peers.
     */
    public function testCancelledNotificationSerializesParamsOnWire(): void
    {
        $notification = new CancelledNotification(
            requestId: new RequestId(42),
            reason: 'user cancel',
        );

        $this->assertNotNull(
            $notification->params,
            'CancelledNotification constructor must populate Notification::$params for the send-side serializer'
        );

        // Mirror exactly what BaseSession::sendNotification() does to build
        // the outgoing wire frame.
        $jrn = new JSONRPCNotification(
            jsonrpc: '2.0',
            method: $notification->method,
            params: $notification->params,
        );
        $envelope = json_decode(json_encode(new JsonRpcMessage($jrn)), true);

        $this->assertSame('notifications/cancelled', $envelope['method']);
        $this->assertArrayHasKey('params', $envelope, 'Wire frame must include a params object');
        $this->assertSame(42, $envelope['params']['requestId']);
        $this->assertSame('user cancel', $envelope['params']['reason']);
    }

    /**
     * The reason field is optional per the MCP spec; verify it is omitted from
     * the wire frame when not supplied, while requestId remains present.
     */
    public function testCancelledNotificationOmitsReasonWhenAbsent(): void
    {
        $notification = new CancelledNotification(
            requestId: new RequestId('req-abc'),
        );

        $jrn = new JSONRPCNotification(
            jsonrpc: '2.0',
            method: $notification->method,
            params: $notification->params,
        );
        $envelope = json_decode(json_encode(new JsonRpcMessage($jrn)), true);

        $this->assertSame('req-abc', $envelope['params']['requestId']);
        $this->assertArrayNotHasKey('reason', $envelope['params']);
    }

    /**
     * A cancellation arriving with a `_meta` object must parse without fatal.
     *
     * `_meta` is a declared `?Meta` property on NotificationParams, so the
     * generic field-forwarding path must route it into a Meta object rather
     * than assigning the raw array onto the typed slot (which throws a
     * TypeError). This pins down the parse for the client-side union, and
     * verifies the value survives a wire round-trip.
     */
    public function testClientCancelledNotificationParsesMeta(): void
    {
        $client = ClientNotification::fromMethodAndParams(
            'notifications/cancelled',
            ['requestId' => 'r1', 'reason' => 'stop', '_meta' => ['trace' => 'x']],
        );

        $notification = $client->getNotification();
        $this->assertInstanceOf(CancelledNotification::class, $notification);

        $meta = $notification->params->_meta;
        $this->assertInstanceOf(Meta::class, $meta);
        $this->assertSame('x', $meta->trace);

        $jrn = new JSONRPCNotification(
            jsonrpc: '2.0',
            method: $notification->method,
            params: $notification->params,
        );
        $envelope = json_decode(json_encode(new JsonRpcMessage($jrn)), true);

        $this->assertSame('notifications/cancelled', $envelope['method']);
        $this->assertSame('r1', $envelope['params']['requestId']);
        $this->assertSame('stop', $envelope['params']['reason']);
        $this->assertSame(['trace' => 'x'], $envelope['params']['_meta']);
    }

    /**
     * Same regression as the client path, exercised through the server-side
     * notification union so both factory methods are covered.
     */
    public function testServerCancelledNotificationParsesMeta(): void
    {
        $server = ServerNotification::fromMethodAndParams(
            'notifications/cancelled',
            ['requestId' => 42, '_meta' => ['trace' => 'y']],
        );

        $notification = $server->getNotification();
        $this->assertInstanceOf(CancelledNotification::class, $notification);

        $meta = $notification->params->_meta;
        $this->assertInstanceOf(Meta::class, $meta);
        $this->assertSame('y', $meta->trace);

        $jrn = new JSONRPCNotification(
            jsonrpc: '2.0',
            method: $notification->method,
            params: $notification->params,
        );
        $envelope = json_decode(json_encode(new JsonRpcMessage($jrn)), true);

        $this->assertSame(42, $envelope['params']['requestId']);
        $this->assertSame(['trace' => 'y'], $envelope['params']['_meta']);
    }

    /**
     * The same `_meta` routing applies to the other notification factories
     * that forward leftover params; progress is the representative case.
     */
    public function testProgressNotificationParsesMeta(): void
    {
        $client = ClientNotification::fromMethodAndParams(
            'notifications/progress',
            ['progressToken' => 'tok', 'progress' => 0.5, '_meta' => ['trace' => 'z']],
        );

        $notification = $client->getNotification();
        $this->assertInstanceOf(ProgressNotification::class, $notification);

        $meta = $notification->params->_meta;
        $this->assertInstanceOf(Meta::class, $meta);
        $this->assertSame('z', $meta->trace);
    }

    /**
     * resources/updated forwards leftover params onto the params object after
     * construction (skipping `uri`), so it shared the same `_meta` fatal. The
     * uri must be preserved and `_meta` normalized into a Meta object.
     */
    public function testResourceUpdatedNotificationParsesMeta(): void
    {
        $server = ServerNotification::fromMethodAndParams(
            'notifications/resources/updated',
            ['uri' => 'file:///x', '_meta' => ['trace' => 'w']],
        );

        $notification = $server->getNotification();
        $this->assertInstanceOf(ResourceUpdatedNotification::class, $notification);
        $this->assertSame('file:///x', $notification->params->uri);

        $meta = $notification->params->_meta;
        $this->assertInstanceOf(Meta::class, $meta);
        $this->assertSame('w', $meta->trace);
    }
}
