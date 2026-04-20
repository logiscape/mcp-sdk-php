<?php

declare(strict_types=1);

namespace Mcp\Tests\Types;

use Mcp\Types\CreateMessageRequest;
use Mcp\Types\Role;
use Mcp\Types\SamplingMessage;
use Mcp\Types\TextContent;
use Mcp\Types\Tool;
use Mcp\Types\ToolChoice;
use Mcp\Types\ToolInputSchema;
use Mcp\Types\ToolResultContent;
use Mcp\Types\ToolUseContent;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the cross-message invariants `CreateMessageRequest::validate()`
 * enforces for tool-use transcripts:
 *
 *   - Tool-result content purity: a user message with any tool_result block
 *     contains only tool_result blocks.
 *   - Bidirectional adjacent id matching: assistant tool_use blocks and the
 *     immediately following user tool_result blocks pair 1:1 by id, checked
 *     from both directions with multiset equality.
 *
 * Per-message validation (empty messages array, invalid maxTokens, etc.) is
 * covered elsewhere.
 */
final class CreateMessageRequestValidationTest extends TestCase
{
    public function testValidPlainTranscriptPasses(): void
    {
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'hello')),
            ],
            maxTokens: 100,
        );
        $req->validate();
        $this->assertTrue(true); // validate() didn't throw
    }

    public function testValidCompleteToolLoopPasses(): void
    {
        // Server sends: user question → assistant with parallel tool_use → user with both tool_results.
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'Weather in Paris and London?')),
                new SamplingMessage(role: Role::ASSISTANT, content: [
                    new ToolUseContent(id: 'call_1', name: 'get_weather', input: ['city' => 'Paris']),
                    new ToolUseContent(id: 'call_2', name: 'get_weather', input: ['city' => 'London']),
                ]),
                new SamplingMessage(role: Role::USER, content: [
                    new ToolResultContent(toolUseId: 'call_1', content: [new TextContent(text: '18C')]),
                    new ToolResultContent(toolUseId: 'call_2', content: [new TextContent(text: '15C')]),
                ]),
            ],
            maxTokens: 100,
        );
        $req->validate();
        $this->assertTrue(true);
    }

    public function testUserMessageMixingToolResultWithTextIsRejected(): void
    {
        // A user message containing a tool_result block must contain only tool_results.
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'q')),
                new SamplingMessage(role: Role::ASSISTANT, content: [
                    new ToolUseContent(id: 'call_1', name: 'get_weather', input: []),
                ]),
                new SamplingMessage(role: Role::USER, content: [
                    new TextContent(text: 'here it is:'),
                    new ToolResultContent(toolUseId: 'call_1', content: [new TextContent(text: '18C')]),
                ]),
            ],
            maxTokens: 100,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tool_result.*(only tool_results|only tool_result)/i');
        $req->validate();
    }

    public function testAssistantToolUseNotFollowedByUserMessageIsRejected(): void
    {
        // Assistant tool_use must be followed by a user tool_result message;
        // a transcript that ends on tool_use has nothing to resolve the call.
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'q')),
                new SamplingMessage(role: Role::ASSISTANT, content: [
                    new ToolUseContent(id: 'call_1', name: 'get_weather', input: []),
                ]),
                // Missing user tool_result message.
            ],
            maxTokens: 100,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not followed by a user message/i');
        $req->validate();
    }

    public function testAssistantToolUseFollowedByAssistantIsRejected(): void
    {
        // The message immediately after an assistant tool_use must be role=user.
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'q')),
                new SamplingMessage(role: Role::ASSISTANT, content: [
                    new ToolUseContent(id: 'call_1', name: 'get_weather', input: []),
                ]),
                new SamplingMessage(role: Role::ASSISTANT, content: new TextContent(text: 'continuing')),
            ],
            maxTokens: 100,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be a user message/i');
        $req->validate();
    }

    public function testFollowUpUserMessageWithNonToolResultBlockIsRejected(): void
    {
        // The user message answering a tool_use batch must consist entirely of
        // tool_result blocks — no interleaved text/image/audio.
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'q')),
                new SamplingMessage(role: Role::ASSISTANT, content: [
                    new ToolUseContent(id: 'call_1', name: 'get_weather', input: []),
                ]),
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'not a tool result')),
            ],
            maxTokens: 100,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must consist entirely of tool_result/i');
        $req->validate();
    }

    public function testMissingToolResultForParallelToolUseIsRejected(): void
    {
        // Every tool_use id in the assistant batch must have a matching
        // tool_result toolUseId in the follow-up user message.
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'q')),
                new SamplingMessage(role: Role::ASSISTANT, content: [
                    new ToolUseContent(id: 'call_1', name: 'get_weather', input: ['city' => 'Paris']),
                    new ToolUseContent(id: 'call_2', name: 'get_weather', input: ['city' => 'London']),
                ]),
                new SamplingMessage(role: Role::USER, content: [
                    new ToolResultContent(toolUseId: 'call_1', content: [new TextContent(text: '18C')]),
                    // Missing result for call_2.
                ]),
            ],
            maxTokens: 100,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/'call_2'.*no matching tool_result/i");
        $req->validate();
    }

    public function testExtraToolResultWithoutMatchingToolUseIsRejected(): void
    {
        // Every tool_result toolUseId must match a tool_use id in the
        // preceding assistant message — unsolicited result ids (even alongside
        // every requested one) violate the ToolResultContent.toolUseId schema
        // constraint and are rejected by provider-native tool-result roles.
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'q')),
                new SamplingMessage(role: Role::ASSISTANT, content: [
                    new ToolUseContent(id: 'call_1', name: 'get_weather', input: ['city' => 'Paris']),
                ]),
                new SamplingMessage(role: Role::USER, content: [
                    new ToolResultContent(toolUseId: 'call_1', content: [new TextContent(text: '18C')]),
                    new ToolResultContent(toolUseId: 'call_extra', content: [new TextContent(text: 'unsolicited')]),
                ]),
            ],
            maxTokens: 100,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/toolUseId 'call_extra'.*does not match any tool_use/i");
        $req->validate();
    }

    public function testToolResultAtIndexZeroIsRejected(): void
    {
        // A user tool_result at the very start of the transcript has no
        // preceding tool_use for toolUseId to match.
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: [
                    new ToolResultContent(toolUseId: 'call_orphan', content: [new TextContent(text: 'x')]),
                ]),
            ],
            maxTokens: 100,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/index 0.*no preceding assistant tool_use/i');
        $req->validate();
    }

    public function testToolResultPrecededByUserTextIsRejected(): void
    {
        // The message immediately before a user tool_result message must be
        // an assistant message, not another user message.
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'hi')),
                new SamplingMessage(role: Role::USER, content: [
                    new ToolResultContent(toolUseId: 'call_extra', content: [new TextContent(text: 'x')]),
                ]),
            ],
            maxTokens: 100,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/preceding message must be an assistant/i');
        $req->validate();
    }

    public function testToolResultPrecededByAssistantWithoutToolUseIsRejected(): void
    {
        // The preceding assistant message must actually contain tool_use
        // blocks — plain assistant text has no tool_use.id for the following
        // tool_result.toolUseId to match.
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'q')),
                new SamplingMessage(role: Role::ASSISTANT, content: new TextContent(text: 'plain reply')),
                new SamplingMessage(role: Role::USER, content: [
                    new ToolResultContent(toolUseId: 'call_x', content: [new TextContent(text: 'x')]),
                ]),
            ],
            maxTokens: 100,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/preceding assistant.*has no tool_use/i');
        $req->validate();
    }

    public function testDuplicateToolUseIdsInAssistantMessageAreRejected(): void
    {
        // tool_use.id is a unique identifier per the schema. Two tool_use
        // blocks with the same id make the pairing with tool_results
        // ambiguous even when the result count matches.
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'q')),
                new SamplingMessage(role: Role::ASSISTANT, content: [
                    new ToolUseContent(id: 'c1', name: 'lookup', input: ['x' => 1]),
                    new ToolUseContent(id: 'c1', name: 'lookup', input: ['x' => 2]),
                ]),
                new SamplingMessage(role: Role::USER, content: [
                    new ToolResultContent(toolUseId: 'c1', content: [new TextContent(text: 'a')]),
                    new ToolResultContent(toolUseId: 'c1', content: [new TextContent(text: 'b')]),
                ]),
            ],
            maxTokens: 100,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/'c1' appears 2 times.*unique within a message/i");
        $req->validate();
    }

    public function testDuplicateToolResultForSameToolUseIsRejected(): void
    {
        // Pairing is 1:1: one tool_use answered by two tool_results for the
        // same id has set membership both ways but violates the multiset
        // equality the spec requires.
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'q')),
                new SamplingMessage(role: Role::ASSISTANT, content: [
                    new ToolUseContent(id: 'c1', name: 'lookup', input: []),
                ]),
                new SamplingMessage(role: Role::USER, content: [
                    new ToolResultContent(toolUseId: 'c1', content: [new TextContent(text: 'first')]),
                    new ToolResultContent(toolUseId: 'c1', content: [new TextContent(text: 'second')]),
                ]),
            ],
            maxTokens: 100,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/'c1'.*referenced by 2 tool_result.*expected 1/i");
        $req->validate();
    }

    public function testToolsWithWellFormedEntriesPasses(): void
    {
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'hi')),
            ],
            maxTokens: 100,
            tools: [
                new Tool(name: 'lookup', inputSchema: new ToolInputSchema(), description: 'test'),
            ],
            toolChoice: new ToolChoice(mode: 'auto'),
        );
        $req->validate();
        $this->assertTrue(true);
    }

    public function testNonToolEntryInToolsArrayIsRejected(): void
    {
        // The Tool[]|null contract is declared in the docblock; enforce it at
        // runtime so malformed arrays can't reach the wire.
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'hi')),
            ],
            maxTokens: 100,
            tools: ['not a Tool'],  /* @phpstan-ignore-line */
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tools\[0\] must be an instance of .*Tool/');
        $req->validate();
    }

    public function testToolWithEmptyNameIsRejected(): void
    {
        // Delegates to Tool::validate() — empty name is already rejected there;
        // this test anchors the delegation.
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'hi')),
            ],
            maxTokens: 100,
            tools: [
                new Tool(name: '', inputSchema: new ToolInputSchema()),
            ],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Tool name cannot be empty/i');
        $req->validate();
    }

    public function testInvalidToolChoiceModeIsRejected(): void
    {
        // Delegates to ToolChoice::validate() — mode must be one of
        // {auto, required, none}.
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'hi')),
            ],
            maxTokens: 100,
            toolChoice: new ToolChoice(mode: 'bogus'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid tool choice mode: bogus/i');
        $req->validate();
    }

    public function testToolResultMessageWithOnlyResultsPasses(): void
    {
        // Control: user message containing only tool_results is allowed (single block form).
        $req = new CreateMessageRequest(
            messages: [
                new SamplingMessage(role: Role::USER, content: new TextContent(text: 'q')),
                new SamplingMessage(role: Role::ASSISTANT, content: [
                    new ToolUseContent(id: 'c1', name: 'lookup', input: []),
                ]),
                new SamplingMessage(
                    role: Role::USER,
                    content: new ToolResultContent(toolUseId: 'c1', content: [new TextContent(text: 'ok')]),
                ),
            ],
            maxTokens: 100,
        );
        $req->validate();
        $this->assertTrue(true);
    }
}
