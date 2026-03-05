<?php

declare(strict_types=1);

namespace Mcp\Tests\Types;

use Mcp\Types\Icon;
use Mcp\Types\Implementation;
use Mcp\Types\Tool;
use Mcp\Types\ToolInputSchema;
use Mcp\Types\ToolInputProperties;
use Mcp\Types\ToolAnnotations;
use Mcp\Types\Prompt;
use Mcp\Types\Resource;
use Mcp\Types\ResourceTemplate;
use Mcp\Types\Annotations;
use Mcp\Types\Role;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Mcp\Types\ResourceLinkContent;
use Mcp\Types\ToolUseContent;
use Mcp\Types\ToolResultContent;
use Mcp\Types\ToolChoice;
use Mcp\Types\ElicitationCreateResult;
use Mcp\Types\ElicitationCapability;
use Mcp\Types\Task;
use Mcp\Types\TaskStatus;
use Mcp\Types\CreateTaskResult;
use Mcp\Types\TaskListResult;
use Mcp\Types\TaskCapability;
use Mcp\Types\ClientCapabilities;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\SamplingMessage;
use Mcp\Types\CreateMessageResult;
use Mcp\Shared\Version;
use PHPUnit\Framework\TestCase;

/**
 * Tests for new types added in 2025-06-18 and 2025-11-25 spec revisions.
 */
final class NewTypesTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Version Constants
    // -----------------------------------------------------------------------

    public function testVersionConstants(): void {
        $this->assertEquals('2025-11-25', Version::LATEST_PROTOCOL_VERSION);
        $this->assertContains('2024-11-05', Version::SUPPORTED_PROTOCOL_VERSIONS);
        $this->assertContains('2025-03-26', Version::SUPPORTED_PROTOCOL_VERSIONS);
        $this->assertContains('2025-06-18', Version::SUPPORTED_PROTOCOL_VERSIONS);
        $this->assertContains('2025-11-25', Version::SUPPORTED_PROTOCOL_VERSIONS);
        $this->assertCount(4, Version::SUPPORTED_PROTOCOL_VERSIONS);
    }

    // -----------------------------------------------------------------------
    // Icon
    // -----------------------------------------------------------------------

    public function testIconSerialization(): void {
        $icon = new Icon('https://example.com/icon.png', 'image/png');
        $json = $icon->jsonSerialize();
        $this->assertEquals('https://example.com/icon.png', $json['src']);
        $this->assertEquals('image/png', $json['mimeType']);
    }

    public function testIconFromArray(): void {
        $icon = Icon::fromArray(['src' => 'https://example.com/icon.svg', 'mimeType' => 'image/svg+xml']);
        $this->assertEquals('https://example.com/icon.svg', $icon->src);
        $this->assertEquals('image/svg+xml', $icon->mimeType);
    }

    public function testIconValidationFailsWithEmptySrc(): void {
        $this->expectException(\InvalidArgumentException::class);
        $icon = new Icon('');
        $icon->validate();
    }

    // -----------------------------------------------------------------------
    // Implementation with Rich Metadata
    // -----------------------------------------------------------------------

    public function testImplementationWithMetadata(): void {
        $impl = new Implementation(
            name: 'test-server',
            version: '1.0',
            title: 'Test Server',
            description: 'A test MCP server',
            icons: [new Icon('https://example.com/icon.png')],
            websiteUrl: 'https://example.com'
        );

        $json = $impl->jsonSerialize();
        $this->assertEquals('test-server', $json['name']);
        $this->assertEquals('1.0', $json['version']);
        $this->assertEquals('Test Server', $json['title']);
        $this->assertEquals('A test MCP server', $json['description']);
        $this->assertEquals('https://example.com', $json['websiteUrl']);
        $this->assertCount(1, $json['icons']);
    }

    public function testImplementationFromArrayWithMetadata(): void {
        $impl = Implementation::fromArray([
            'name' => 'srv',
            'version' => '2.0',
            'title' => 'My Server',
            'websiteUrl' => 'https://srv.test',
            'icons' => [['src' => 'https://srv.test/icon.png']],
        ]);
        $this->assertEquals('My Server', $impl->title);
        $this->assertEquals('https://srv.test', $impl->websiteUrl);
        $this->assertCount(1, $impl->icons);
    }

    // -----------------------------------------------------------------------
    // Tool with Rich Metadata & Output Schema
    // -----------------------------------------------------------------------

    public function testToolWithTitleIconsOutputSchema(): void {
        $tool = new Tool(
            name: 'compute',
            inputSchema: new ToolInputSchema(properties: ToolInputProperties::fromArray([])),
            description: 'Compute something',
            title: 'Compute Tool',
            icons: [new Icon('https://example.com/tool.png')],
            outputSchema: ['type' => 'object', 'properties' => ['result' => ['type' => 'number']]],
        );

        $json = $tool->jsonSerialize();
        $this->assertEquals('Compute Tool', $json['title']);
        $this->assertCount(1, $json['icons']);
        $this->assertEquals('object', $json['outputSchema']['type']);
    }

    public function testToolFromArrayWithNewFields(): void {
        $tool = Tool::fromArray([
            'name' => 'test-tool',
            'inputSchema' => ['type' => 'object', 'properties' => []],
            'title' => 'Test Tool Title',
            'outputSchema' => ['type' => 'object'],
            'execution' => ['taskSupport' => 'optional'],
            'icons' => [['src' => 'https://test.com/icon.png']],
        ]);
        $this->assertEquals('Test Tool Title', $tool->title);
        $this->assertNotNull($tool->outputSchema);
        $this->assertEquals('optional', $tool->execution['taskSupport']);
        $this->assertCount(1, $tool->icons);
    }

    // -----------------------------------------------------------------------
    // Prompt with Rich Metadata
    // -----------------------------------------------------------------------

    public function testPromptWithTitleIcons(): void {
        $prompt = new Prompt(
            name: 'greet',
            description: 'Greeting prompt',
            title: 'Greeting',
            icons: [new Icon('https://example.com/greet.png')],
        );

        $json = $prompt->jsonSerialize();
        $this->assertEquals('Greeting', $json['title']);
        $this->assertCount(1, $json['icons']);
    }

    // -----------------------------------------------------------------------
    // Resource with Rich Metadata & Size
    // -----------------------------------------------------------------------

    public function testResourceWithTitleIconsSize(): void {
        $resource = new Resource(
            name: 'readme',
            uri: 'file://readme.md',
            title: 'README',
            icons: [new Icon('https://example.com/file.png')],
            size: 1024,
        );

        $json = $resource->jsonSerialize();
        $this->assertEquals('README', $json['title']);
        $this->assertEquals(1024, $json['size']);
        $this->assertCount(1, $json['icons']);
    }

    // -----------------------------------------------------------------------
    // ResourceTemplate with Rich Metadata
    // -----------------------------------------------------------------------

    public function testResourceTemplateWithTitleIcons(): void {
        $tmpl = new ResourceTemplate(
            name: 'user-profile',
            uriTemplate: 'users://{userId}',
            title: 'User Profile',
            icons: [new Icon('https://example.com/user.png')],
        );

        $json = $tmpl->jsonSerialize();
        $this->assertEquals('User Profile', $json['title']);
        $this->assertCount(1, $json['icons']);
    }

    // -----------------------------------------------------------------------
    // Annotations with lastModified
    // -----------------------------------------------------------------------

    public function testAnnotationsWithLastModified(): void {
        $ann = new Annotations(
            audience: [Role::USER],
            priority: 0.5,
            lastModified: '2025-01-01T00:00:00Z'
        );

        $json = $ann->jsonSerialize();
        $this->assertEquals('2025-01-01T00:00:00Z', $json['lastModified']);
        $this->assertEquals(0.5, $json['priority']);
    }

    public function testAnnotationsFromArrayWithLastModified(): void {
        $ann = Annotations::fromArray([
            'audience' => ['user'],
            'lastModified' => '2025-06-01T12:00:00Z',
        ]);
        $this->assertEquals('2025-06-01T12:00:00Z', $ann->lastModified);
    }

    // -----------------------------------------------------------------------
    // CallToolResult with structuredContent & ResourceLinkContent
    // -----------------------------------------------------------------------

    public function testCallToolResultWithStructuredContent(): void {
        $result = new CallToolResult(
            content: [new TextContent(text: '{"sum": 42}')],
            structuredContent: ['sum' => 42]
        );

        $json = $result->jsonSerialize();
        $this->assertEquals(['sum' => 42], $json['structuredContent']);
    }

    public function testCallToolResultFromArrayWithResourceLink(): void {
        $result = CallToolResult::fromResponseData([
            'content' => [
                ['type' => 'resource_link', 'uri' => 'file://test.txt', 'name' => 'Test File'],
            ],
        ]);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(ResourceLinkContent::class, $result->content[0]);
        $this->assertEquals('file://test.txt', $result->content[0]->uri);
    }

    public function testCallToolResultFromArrayWithStructuredContent(): void {
        $result = CallToolResult::fromResponseData([
            'content' => [['type' => 'text', 'text' => '{"x":1}']],
            'structuredContent' => ['x' => 1],
        ]);
        $this->assertEquals(['x' => 1], $result->structuredContent);
    }

    // -----------------------------------------------------------------------
    // ResourceLinkContent
    // -----------------------------------------------------------------------

    public function testResourceLinkContent(): void {
        $rlc = new ResourceLinkContent(
            uri: 'file://data.csv',
            name: 'Data',
            description: 'A CSV file',
            mimeType: 'text/csv'
        );

        $json = $rlc->jsonSerialize();
        $this->assertEquals('resource_link', $json['type']);
        $this->assertEquals('file://data.csv', $json['uri']);
        $this->assertEquals('Data', $json['name']);
        $this->assertEquals('text/csv', $json['mimeType']);
    }

    public function testResourceLinkContentFromArray(): void {
        $rlc = ResourceLinkContent::fromArray([
            'type' => 'resource_link',
            'uri' => 'https://example.com/resource',
            'name' => 'Example',
        ]);
        $this->assertEquals('https://example.com/resource', $rlc->uri);
        $this->assertEquals('Example', $rlc->name);
    }

    // -----------------------------------------------------------------------
    // ToolUseContent
    // -----------------------------------------------------------------------

    public function testToolUseContent(): void {
        $tuc = new ToolUseContent(
            id: 'tu_123',
            name: 'calculator',
            input: ['a' => 1, 'b' => 2]
        );

        $json = $tuc->jsonSerialize();
        $this->assertEquals('tool_use', $json['type']);
        $this->assertEquals('tu_123', $json['id']);
        $this->assertEquals('calculator', $json['name']);
        $this->assertEquals(['a' => 1, 'b' => 2], $json['input']);
    }

    public function testToolUseContentFromArray(): void {
        $tuc = ToolUseContent::fromArray([
            'type' => 'tool_use',
            'id' => 'tu_456',
            'name' => 'search',
            'input' => ['query' => 'test'],
        ]);
        $this->assertEquals('tu_456', $tuc->id);
        $this->assertEquals('search', $tuc->name);
    }

    // -----------------------------------------------------------------------
    // ToolResultContent
    // -----------------------------------------------------------------------

    public function testToolResultContent(): void {
        $trc = new ToolResultContent(
            toolUseId: 'tu_123',
            content: [new TextContent(text: 'Result')],
            isError: false
        );

        $json = $trc->jsonSerialize();
        $this->assertEquals('tool_result', $json['type']);
        $this->assertEquals('tu_123', $json['toolUseId']);
        $this->assertFalse($json['isError']);
    }

    public function testToolResultContentFromArray(): void {
        $trc = ToolResultContent::fromArray([
            'type' => 'tool_result',
            'toolUseId' => 'tu_789',
            'content' => [['type' => 'text', 'text' => 'Done']],
            'isError' => true,
        ]);
        $this->assertEquals('tu_789', $trc->toolUseId);
        $this->assertTrue($trc->isError);
        $this->assertCount(1, $trc->content);
    }

    // -----------------------------------------------------------------------
    // ToolChoice
    // -----------------------------------------------------------------------

    public function testToolChoice(): void {
        $tc = new ToolChoice('auto');
        $json = $tc->jsonSerialize();
        $this->assertEquals('auto', $json['mode']);
    }

    public function testToolChoiceInvalidMode(): void {
        $this->expectException(\InvalidArgumentException::class);
        $tc = new ToolChoice('invalid');
        $tc->validate();
    }

    // -----------------------------------------------------------------------
    // ElicitationCreateResult
    // -----------------------------------------------------------------------

    public function testElicitationCreateResult(): void {
        $result = new ElicitationCreateResult(
            action: 'accept',
            content: ['name' => 'John', 'age' => 30]
        );

        $json = $result->jsonSerialize();
        $this->assertEquals('accept', $json['action']);
        $this->assertEquals(['name' => 'John', 'age' => 30], $json['content']);
    }

    public function testElicitationCreateResultFromArray(): void {
        $result = ElicitationCreateResult::fromResponseData([
            'action' => 'decline',
        ]);
        $this->assertEquals('decline', $result->action);
        $this->assertNull($result->content);
    }

    public function testElicitationCreateResultInvalidAction(): void {
        $this->expectException(\InvalidArgumentException::class);
        $result = new ElicitationCreateResult(action: 'invalid');
        $result->validate();
    }

    // -----------------------------------------------------------------------
    // ElicitationCapability
    // -----------------------------------------------------------------------

    public function testElicitationCapability(): void {
        $cap = new ElicitationCapability(form: true, url: true);
        $json = $cap->jsonSerialize();
        $this->assertArrayHasKey('form', $json);
        $this->assertArrayHasKey('url', $json);
    }

    // -----------------------------------------------------------------------
    // Task Types
    // -----------------------------------------------------------------------

    public function testTaskStatus(): void {
        $this->assertTrue(TaskStatus::isValid('working'));
        $this->assertTrue(TaskStatus::isValid('completed'));
        $this->assertTrue(TaskStatus::isValid('failed'));
        $this->assertTrue(TaskStatus::isValid('cancelled'));
        $this->assertTrue(TaskStatus::isValid('input_required'));
        $this->assertFalse(TaskStatus::isValid('unknown'));
    }

    public function testTask(): void {
        $task = new Task(
            taskId: 'task_abc',
            status: TaskStatus::WORKING,
            statusMessage: 'Processing...',
            createdAt: '2025-01-01T00:00:00Z',
            ttl: 3600,
            pollInterval: 5,
        );

        $json = $task->jsonSerialize();
        $this->assertEquals('task_abc', $json['taskId']);
        $this->assertEquals('working', $json['status']);
        $this->assertEquals('Processing...', $json['statusMessage']);
        $this->assertEquals(3600, $json['ttl']);
        $this->assertEquals(5, $json['pollInterval']);
    }

    public function testTaskFromArray(): void {
        $task = Task::fromArray([
            'taskId' => 'task_xyz',
            'status' => 'completed',
            'lastUpdatedAt' => '2025-01-02T00:00:00Z',
        ]);
        $this->assertEquals('task_xyz', $task->taskId);
        $this->assertEquals('completed', $task->status);
    }

    public function testTaskInvalidStatus(): void {
        $this->expectException(\InvalidArgumentException::class);
        $task = new Task(taskId: 'task_1', status: 'bogus');
        $task->validate();
    }

    public function testCreateTaskResult(): void {
        $task = new Task(taskId: 't1', status: TaskStatus::WORKING);
        $result = new CreateTaskResult(task: $task);
        $json = $result->jsonSerialize();
        $this->assertEquals('t1', $json['task']->taskId);
    }

    public function testTaskListResult(): void {
        $tasks = [
            new Task(taskId: 't1', status: TaskStatus::WORKING),
            new Task(taskId: 't2', status: TaskStatus::COMPLETED),
        ];
        $result = new TaskListResult(tasks: $tasks);
        $json = $result->jsonSerialize();
        $this->assertCount(2, $json['tasks']);
    }

    public function testTaskListResultFromArray(): void {
        $result = TaskListResult::fromResponseData([
            'tasks' => [
                ['taskId' => 't1', 'status' => 'working'],
                ['taskId' => 't2', 'status' => 'completed'],
            ],
        ]);
        $this->assertCount(2, $result->tasks);
        $this->assertEquals('t1', $result->tasks[0]->taskId);
    }

    // -----------------------------------------------------------------------
    // TaskCapability
    // -----------------------------------------------------------------------

    public function testTaskCapability(): void {
        $cap = new TaskCapability(list: true, cancel: true);
        $json = $cap->jsonSerialize();
        $this->assertArrayHasKey('list', $json);
        $this->assertArrayHasKey('cancel', $json);
    }

    // -----------------------------------------------------------------------
    // ClientCapabilities with Elicitation & Tasks
    // -----------------------------------------------------------------------

    public function testClientCapabilitiesWithElicitationAndTasks(): void {
        $caps = new ClientCapabilities(
            elicitation: new ElicitationCapability(form: true),
            tasks: new TaskCapability(list: true),
        );

        $json = $caps->jsonSerialize();
        $this->assertArrayHasKey('elicitation', $json);
        $this->assertArrayHasKey('tasks', $json);
    }

    // -----------------------------------------------------------------------
    // ServerCapabilities with Tasks
    // -----------------------------------------------------------------------

    public function testServerCapabilitiesWithTasks(): void {
        $caps = new ServerCapabilities(
            tasks: new TaskCapability(list: true, cancel: true),
        );

        $json = $caps->jsonSerialize();
        $this->assertArrayHasKey('tasks', $json);
    }

    // -----------------------------------------------------------------------
    // SamplingMessage with new content types
    // -----------------------------------------------------------------------

    public function testSamplingMessageWithToolUseContent(): void {
        $msg = new SamplingMessage(
            role: Role::ASSISTANT,
            content: new ToolUseContent(id: 'tu_1', name: 'calc', input: ['x' => 1]),
        );

        $json = $msg->jsonSerialize();
        $this->assertEquals('assistant', $json['role']);
        $this->assertEquals('tool_use', $json['content']->type);
    }

    public function testSamplingMessageWithToolResultContent(): void {
        $msg = new SamplingMessage(
            role: Role::USER,
            content: new ToolResultContent(toolUseId: 'tu_1', content: [new TextContent(text: 'Done')]),
        );

        $json = $msg->jsonSerialize();
        $this->assertEquals('user', $json['role']);
        $this->assertEquals('tool_result', $json['content']->type);
    }

    // -----------------------------------------------------------------------
    // CreateMessageResult with ToolUseContent
    // -----------------------------------------------------------------------

    public function testCreateMessageResultWithToolUse(): void {
        $result = CreateMessageResult::fromResponseData([
            'content' => ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'calc', 'input' => ['a' => 1]],
            'model' => 'claude-3',
            'role' => 'assistant',
            'stopReason' => 'toolUse',
        ]);

        $this->assertInstanceOf(ToolUseContent::class, $result->content);
        $this->assertEquals('toolUse', $result->stopReason);
    }

    // -----------------------------------------------------------------------
    // McpError Constants
    // -----------------------------------------------------------------------

    public function testMcpErrorUrlElicitationCode(): void {
        $this->assertEquals(-32042, \Mcp\Shared\McpError::URL_ELICITATION_REQUIRED);
    }
}
