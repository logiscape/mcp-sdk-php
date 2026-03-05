<?php

declare(strict_types=1);

namespace Mcp\Tests\Types;

use Mcp\Types\AudioContent;
use Mcp\Types\CreateMessageResult;
use Mcp\Types\ImageContent;
use Mcp\Types\Role;
use Mcp\Types\SamplingMessage;
use Mcp\Types\ServerRequest;
use Mcp\Types\TextContent;
use Mcp\Types\ToolResultContent;
use Mcp\Types\ToolUseContent;
use PHPUnit\Framework\TestCase;

/**
 * Tests that SamplingMessage and CreateMessageResult support both single
 * content blocks and arrays of content blocks, as required by the MCP spec
 * for tool use in sampling.
 */
final class ArrayContentTest extends TestCase
{
    // -----------------------------------------------------------------------
    // SamplingMessage: single content (existing behavior)
    // -----------------------------------------------------------------------

    public function testSamplingMessageSingleContent(): void {
        $msg = new SamplingMessage(
            role: Role::USER,
            content: new TextContent(text: 'Hello'),
        );

        $this->assertInstanceOf(TextContent::class, $msg->content);
        $json = $msg->jsonSerialize();
        $this->assertEquals('user', $json['role']);
        $this->assertInstanceOf(TextContent::class, $json['content']);
    }

    // -----------------------------------------------------------------------
    // SamplingMessage: array content for tool use
    // -----------------------------------------------------------------------

    public function testSamplingMessageArrayOfToolUseContent(): void {
        $msg = new SamplingMessage(
            role: Role::ASSISTANT,
            content: [
                new ToolUseContent(id: 'call_1', name: 'get_weather', input: ['city' => 'Paris']),
                new ToolUseContent(id: 'call_2', name: 'get_weather', input: ['city' => 'London']),
            ],
        );

        $this->assertIsArray($msg->content);
        $this->assertCount(2, $msg->content);
        $this->assertInstanceOf(ToolUseContent::class, $msg->content[0]);
        $this->assertInstanceOf(ToolUseContent::class, $msg->content[1]);

        $json = $msg->jsonSerialize();
        $this->assertEquals('assistant', $json['role']);
        $this->assertIsArray($json['content']);
        $this->assertCount(2, $json['content']);
    }

    public function testSamplingMessageArrayOfToolResultContent(): void {
        $msg = new SamplingMessage(
            role: Role::USER,
            content: [
                new ToolResultContent(toolUseId: 'call_1', content: [new TextContent(text: '18°C')]),
                new ToolResultContent(toolUseId: 'call_2', content: [new TextContent(text: '15°C')]),
            ],
        );

        $this->assertIsArray($msg->content);
        $this->assertCount(2, $msg->content);
        $this->assertInstanceOf(ToolResultContent::class, $msg->content[0]);
        $this->assertInstanceOf(ToolResultContent::class, $msg->content[1]);
    }

    public function testSamplingMessageArrayValidation(): void {
        $msg = new SamplingMessage(
            role: Role::ASSISTANT,
            content: [
                new ToolUseContent(id: 'call_1', name: 'search', input: ['q' => 'test']),
            ],
        );

        // Should not throw
        $msg->validate();
        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // CreateMessageResult: single content (existing behavior)
    // -----------------------------------------------------------------------

    public function testCreateMessageResultSingleContent(): void {
        $result = new CreateMessageResult(
            content: new TextContent(text: 'The capital is Paris.'),
            model: 'claude-3',
            role: Role::ASSISTANT,
            stopReason: 'endTurn',
        );

        $this->assertInstanceOf(TextContent::class, $result->content);
    }

    public function testCreateMessageResultFromResponseDataSingleContent(): void {
        $result = CreateMessageResult::fromResponseData([
            'content' => ['type' => 'text', 'text' => 'Hello'],
            'model' => 'claude-3',
            'role' => 'assistant',
            'stopReason' => 'endTurn',
        ]);

        $this->assertInstanceOf(TextContent::class, $result->content);
        $this->assertEquals('Hello', $result->content->text);
    }

    // -----------------------------------------------------------------------
    // CreateMessageResult: array content for tool use
    // -----------------------------------------------------------------------

    public function testCreateMessageResultArrayOfToolUse(): void {
        $result = new CreateMessageResult(
            content: [
                new ToolUseContent(id: 'call_abc', name: 'get_weather', input: ['city' => 'Paris']),
                new ToolUseContent(id: 'call_def', name: 'get_weather', input: ['city' => 'London']),
            ],
            model: 'claude-3',
            role: Role::ASSISTANT,
            stopReason: 'toolUse',
        );

        $this->assertIsArray($result->content);
        $this->assertCount(2, $result->content);
        $this->assertEquals('toolUse', $result->stopReason);
    }

    public function testCreateMessageResultFromResponseDataArrayContent(): void {
        $result = CreateMessageResult::fromResponseData([
            'content' => [
                ['type' => 'tool_use', 'id' => 'call_abc', 'name' => 'get_weather', 'input' => ['city' => 'Paris']],
                ['type' => 'tool_use', 'id' => 'call_def', 'name' => 'get_weather', 'input' => ['city' => 'London']],
            ],
            'model' => 'claude-3',
            'role' => 'assistant',
            'stopReason' => 'toolUse',
        ]);

        $this->assertIsArray($result->content);
        $this->assertCount(2, $result->content);
        $this->assertInstanceOf(ToolUseContent::class, $result->content[0]);
        $this->assertInstanceOf(ToolUseContent::class, $result->content[1]);
        $this->assertEquals('call_abc', $result->content[0]->id);
        $this->assertEquals('call_def', $result->content[1]->id);
    }

    public function testCreateMessageResultArrayValidation(): void {
        $result = new CreateMessageResult(
            content: [
                new ToolUseContent(id: 'call_1', name: 'search', input: ['q' => 'test']),
            ],
            model: 'claude-3',
            role: Role::ASSISTANT,
        );

        // Should not throw
        $result->validate();
        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // ServerRequest: parsing wire format with array content
    // -----------------------------------------------------------------------

    public function testServerRequestParsesSamplingWithArrayToolUseContent(): void {
        $request = ServerRequest::fromMethodAndParams('sampling/createMessage', [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => ['type' => 'text', 'text' => 'Weather in Paris and London?'],
                ],
                [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'get_weather', 'input' => ['city' => 'Paris']],
                        ['type' => 'tool_use', 'id' => 'call_2', 'name' => 'get_weather', 'input' => ['city' => 'London']],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'tool_result', 'toolUseId' => 'call_1', 'content' => [['type' => 'text', 'text' => '18°C']]],
                        ['type' => 'tool_result', 'toolUseId' => 'call_2', 'content' => [['type' => 'text', 'text' => '15°C']]],
                    ],
                ],
            ],
            'maxTokens' => 1000,
        ]);

        $inner = $request->getRequest();
        $this->assertInstanceOf(\Mcp\Types\CreateMessageRequest::class, $inner);

        $messages = $inner->messages;
        $this->assertCount(3, $messages);

        // First message: single text content
        $this->assertInstanceOf(TextContent::class, $messages[0]->content);

        // Second message: array of tool_use
        $this->assertIsArray($messages[1]->content);
        $this->assertCount(2, $messages[1]->content);
        $this->assertInstanceOf(ToolUseContent::class, $messages[1]->content[0]);
        $this->assertInstanceOf(ToolUseContent::class, $messages[1]->content[1]);
        $this->assertEquals('call_1', $messages[1]->content[0]->id);
        $this->assertEquals('call_2', $messages[1]->content[1]->id);

        // Third message: array of tool_result
        $this->assertIsArray($messages[2]->content);
        $this->assertCount(2, $messages[2]->content);
        $this->assertInstanceOf(ToolResultContent::class, $messages[2]->content[0]);
        $this->assertInstanceOf(ToolResultContent::class, $messages[2]->content[1]);
        $this->assertEquals('call_1', $messages[2]->content[0]->toolUseId);
        $this->assertEquals('call_2', $messages[2]->content[1]->toolUseId);
    }

    public function testServerRequestParsesSingleContentStillWorks(): void {
        $request = ServerRequest::fromMethodAndParams('sampling/createMessage', [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => ['type' => 'text', 'text' => 'Hello'],
                ],
            ],
            'maxTokens' => 100,
        ]);

        $inner = $request->getRequest();
        $messages = $inner->messages;
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(TextContent::class, $messages[0]->content);
        $this->assertEquals('Hello', $messages[0]->content->text);
    }
}
