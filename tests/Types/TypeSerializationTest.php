<?php

declare(strict_types=1);

namespace Mcp\Tests\Types;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Mcp\Types\ImageContent;
use Mcp\Types\InitializeResult;
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
}
