<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\InitializationOptions;
use Mcp\Server\ServerSession;
use Mcp\Shared\Version;
use Mcp\Types\Annotations;
use Mcp\Types\AudioContent;
use Mcp\Types\CallToolResult;
use Mcp\Types\ImageContent;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\RequestId;
use Mcp\Types\RequestParams;
use Mcp\Types\ResourceLinkContent;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\TextContent;
use PHPUnit\Framework\TestCase;

/**
 * Tests for adaptResponseForClient() in ServerSession.
 *
 * Validates that responses are properly adapted for clients using older
 * protocol versions, stripping unsupported features.
 */
final class AdaptResponseTest extends TestCase
{
    /**
     * Test that ResourceLinkContent is stripped for pre-2025-06-18 clients.
     */
    public function testStripsResourceLinkContentForOlderClients(): void {
        $session = $this->createInitializedSession('2025-03-26');

        $result = new CallToolResult([
            new TextContent('hello'),
            new ResourceLinkContent(uri: 'file://test.txt', name: 'Test'),
        ]);

        $adapted = $session->adaptResponseForClient($result);
        $this->assertInstanceOf(CallToolResult::class, $adapted);
        $this->assertCount(1, $adapted->content);
        $this->assertInstanceOf(TextContent::class, $adapted->content[0]);
    }

    /**
     * Test that structuredContent is stripped for pre-2025-06-18 clients.
     */
    public function testStripsStructuredContentForOlderClients(): void {
        $session = $this->createInitializedSession('2025-03-26');

        $result = new CallToolResult(
            content: [new TextContent('{"x":1}')],
            structuredContent: ['x' => 1],
        );

        $adapted = $session->adaptResponseForClient($result);
        $this->assertInstanceOf(CallToolResult::class, $adapted);
        $this->assertNull($adapted->structuredContent);
    }

    /**
     * Test that AudioContent is stripped for pre-2025-03-26 clients.
     */
    public function testStripsAudioContentForPre20250326Clients(): void {
        $session = $this->createInitializedSession('2024-11-05');

        $result = new CallToolResult([
            new TextContent('text'),
            new AudioContent('base64data', 'audio/wav'),
        ]);

        $adapted = $session->adaptResponseForClient($result);
        $this->assertInstanceOf(CallToolResult::class, $adapted);
        $this->assertCount(1, $adapted->content);
        $this->assertInstanceOf(TextContent::class, $adapted->content[0]);
    }

    /**
     * Test that annotations are stripped for pre-2025-03-26 clients.
     */
    public function testStripsAnnotationsForPre20250326Clients(): void {
        $session = $this->createInitializedSession('2024-11-05');

        $result = new CallToolResult([
            new TextContent('hello', new Annotations(priority: 0.5)),
            new ImageContent('imgdata', 'image/png', new Annotations(priority: 0.8)),
        ]);

        $adapted = $session->adaptResponseForClient($result);
        $this->assertInstanceOf(CallToolResult::class, $adapted);
        $this->assertCount(2, $adapted->content);
        $this->assertNull($adapted->content[0]->annotations);
        $this->assertNull($adapted->content[1]->annotations);
    }

    /**
     * Test that no adaptation happens for clients using the latest version.
     */
    public function testNoAdaptationForLatestVersion(): void {
        $session = $this->createInitializedSession(Version::LATEST_PROTOCOL_VERSION);

        $result = new CallToolResult(
            content: [
                new TextContent('hello', new Annotations(priority: 0.5)),
                new AudioContent('data', 'audio/wav'),
                new ResourceLinkContent(uri: 'file://test.txt', name: 'Test'),
            ],
            structuredContent: ['key' => 'value'],
        );

        $adapted = $session->adaptResponseForClient($result);
        $this->assertSame($result, $adapted, 'No adaptation should occur for latest version');
    }

    /**
     * Create a ServerSession initialized with a specific negotiated protocol version.
     */
    private function createInitializedSession(string $protocolVersion): ServerSession {
        $transport = new AdaptTestTransport();
        $options = new InitializationOptions(
            serverName: 'test-server',
            serverVersion: '1.0.0',
            capabilities: new ServerCapabilities()
        );
        $session = new ServerSession($transport, $options);

        // Use reflection to set the negotiated version and initialization state
        $ref = new \ReflectionClass($session);

        $versionProp = $ref->getProperty('negotiatedProtocolVersion');
        $versionProp->setAccessible(true);
        $versionProp->setValue($session, $protocolVersion);

        $stateProp = $ref->getProperty('initializationState');
        $stateProp->setAccessible(true);
        $stateProp->setValue($session, \Mcp\Server\InitializationState::Initialized);

        return $session;
    }
}

/**
 * Minimal transport for adapt response tests.
 */
final class AdaptTestTransport implements \Mcp\Server\Transport\Transport
{
    public array $writtenMessages = [];

    public function start(): void {}
    public function stop(): void {}
    public function readMessage(): ?JsonRpcMessage { return null; }
    public function writeMessage(JsonRpcMessage $message): void {
        $this->writtenMessages[] = $message;
    }
}
