<?php

declare(strict_types=1);

namespace Mcp\Tests\Server\Elicitation;

use PHPUnit\Framework\TestCase;
use Mcp\Server\Elicitation\ElicitationContext;
use Mcp\Server\Elicitation\ElicitationDeclinedException;
use Mcp\Server\Elicitation\ElicitationSuspendException;
use Mcp\Server\McpServerException;
use Mcp\Server\HttpServerSession;
use Mcp\Server\InitializationOptions;
use Mcp\Server\InitializationState;
use Mcp\Server\ServerSession;
use Mcp\Server\Transport\Transport;
use Mcp\Types\ClientCapabilities;
use Mcp\Types\ElicitationCapability;
use Mcp\Types\ElicitationCreateResult;
use Mcp\Types\Implementation;
use Mcp\Types\InitializeRequestParams;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\ServerCapabilities;
use Psr\Log\NullLogger;

/**
 * Minimal transport for testing ElicitationContext.
 */
class ElicitationTestTransport implements Transport
{
    /** @var JsonRpcMessage[] */
    public array $writtenMessages = [];

    public function start(): void {}
    public function stop(): void {}

    public function readMessage(): ?JsonRpcMessage
    {
        return null;
    }

    public function writeMessage(JsonRpcMessage $message): void
    {
        $this->writtenMessages[] = $message;
    }
}

final class ElicitationContextTest extends TestCase
{
    private function createInitOptions(): InitializationOptions
    {
        return new InitializationOptions(
            serverName: 'test-server',
            serverVersion: '1.0.0',
            capabilities: new ServerCapabilities()
        );
    }

    /**
     * Create a session with given client capabilities for testing.
     */
    private function createSessionWithCapabilities(
        ?ElicitationCapability $elicitation = null,
        string $protocolVersion = '2025-11-25',
    ): ServerSession {
        $transport = new ElicitationTestTransport();
        $session = new ServerSession($transport, $this->createInitOptions(), new NullLogger());

        // Use reflection to set protected fields for testing
        $ref = new \ReflectionClass($session);

        $initStateField = $ref->getProperty('initializationState');
        $initStateField->setValue($session, InitializationState::Initialized);

        $clientParams = new InitializeRequestParams(
            protocolVersion: $protocolVersion,
            capabilities: new ClientCapabilities(elicitation: $elicitation),
            clientInfo: new Implementation(name: 'test-client', version: '1.0')
        );
        $clientParamsField = $ref->getProperty('clientParams');
        $clientParamsField->setValue($session, $clientParams);

        $versionField = $ref->getProperty('negotiatedProtocolVersion');
        $versionField->setValue($session, $protocolVersion);

        return $session;
    }

    /**
     * Test supportsForm returns true when client has form capability.
     */
    public function testSupportsFormWithFormCapability(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true)
        );
        $context = new ElicitationContext(session: $session);
        $this->assertTrue($context->supportsForm());
    }

    /**
     * Test supportsForm returns true when client has empty elicitation capability.
     * Per spec: "elicitation": {} is equivalent to form-only support.
     */
    public function testSupportsFormWithEmptyCapability(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability() // both form and url are null
        );
        $context = new ElicitationContext(session: $session);
        $this->assertTrue($context->supportsForm());
    }

    /**
     * Test supportsForm returns false when client has no elicitation capability.
     */
    public function testSupportsFormWithoutCapability(): void
    {
        $session = $this->createSessionWithCapabilities(null);
        $context = new ElicitationContext(session: $session);
        $this->assertFalse($context->supportsForm());
    }

    /**
     * Test supportsForm returns false when protocol version is too old.
     */
    public function testSupportsFormWithOldProtocolVersion(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true),
            '2025-03-26' // Before elicitation was added
        );
        $context = new ElicitationContext(session: $session);
        $this->assertFalse($context->supportsForm());
    }

    /**
     * Test supportsUrl returns true when client has URL capability and version supports it.
     */
    public function testSupportsUrlWithUrlCapability(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true, url: true),
            '2025-11-25'
        );
        $context = new ElicitationContext(session: $session);
        $this->assertTrue($context->supportsUrl());
    }

    /**
     * Test supportsUrl returns false when client only has form capability.
     */
    public function testSupportsUrlWithoutUrlCapability(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true) // url is null
        );
        $context = new ElicitationContext(session: $session);
        $this->assertFalse($context->supportsUrl());
    }

    /**
     * Test supportsUrl returns false when protocol version doesn't support url elicitation.
     */
    public function testSupportsUrlWithOldProtocolVersion(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true, url: true),
            '2025-06-18' // Supports form but not URL
        );
        $context = new ElicitationContext(session: $session);
        $this->assertFalse($context->supportsUrl());
    }

    /**
     * Test form() returns null when client doesn't support elicitation.
     */
    public function testFormReturnsNullWhenNotSupported(): void
    {
        $session = $this->createSessionWithCapabilities(null);
        $context = new ElicitationContext(session: $session);

        $result = $context->form('Need info', ['type' => 'object', 'properties' => []]);
        $this->assertNull($result);
    }

    /**
     * Test url() returns null when client doesn't support URL elicitation.
     */
    public function testUrlReturnsNullWhenNotSupported(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true) // no URL support
        );
        $context = new ElicitationContext(session: $session);

        $result = $context->url('Click here', 'https://example.com/auth');
        $this->assertNull($result);
    }

    /**
     * Test form() in HTTP mode throws ElicitationSuspendException when no preloaded result.
     */
    public function testFormHttpModeThrowsSuspendException(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true)
        );
        $context = new ElicitationContext(
            session: $session,
            httpMode: true,
            preloadedResults: [],
            toolName: 'test-tool',
            toolArguments: ['arg' => 'val'],
            originalRequestId: 1,
        );

        $this->expectException(ElicitationSuspendException::class);
        $context->form('Need info', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]);
    }

    /**
     * Test form() in HTTP mode returns preloaded result when available.
     */
    public function testFormHttpModeReturnsPreloadedResult(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true)
        );
        $context = new ElicitationContext(
            session: $session,
            httpMode: true,
            preloadedResults: [
                0 => ['action' => 'accept', 'content' => ['name' => 'John']],
            ],
            toolName: 'test-tool',
            toolArguments: [],
            originalRequestId: 1,
        );

        $result = $context->form('Need info', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]);

        $this->assertNotNull($result);
        $this->assertSame('accept', $result->action);
        $this->assertSame(['name' => 'John'], $result->content);
    }

    /**
     * Test multiple elicitations in HTTP mode: first returns preloaded, second suspends.
     */
    public function testMultipleElicitationsInHttpMode(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true)
        );
        $context = new ElicitationContext(
            session: $session,
            httpMode: true,
            preloadedResults: [
                0 => ['action' => 'accept', 'content' => ['name' => 'John']],
            ],
            toolName: 'test-tool',
            toolArguments: [],
            originalRequestId: 1,
        );

        // First call returns preloaded result
        $result1 = $context->form('First question', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]);
        $this->assertNotNull($result1);
        $this->assertSame('accept', $result1->action);

        // Second call suspends (no preloaded result for sequence 1)
        try {
            $context->form('Second question', [
                'type' => 'object',
                'properties' => ['age' => ['type' => 'number']],
            ]);
            $this->fail('Expected ElicitationSuspendException');
        } catch (ElicitationSuspendException $e) {
            $this->assertSame(1, $e->elicitationSequence);
            $this->assertSame('test-tool', $e->toolName);
            $this->assertArrayHasKey(0, $e->previousResults);
        }
    }

    /**
     * Test url() in HTTP mode throws ElicitationSuspendException.
     */
    public function testUrlHttpModeThrowsSuspendException(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true, url: true),
            '2025-11-25'
        );
        $context = new ElicitationContext(
            session: $session,
            httpMode: true,
            preloadedResults: [],
            toolName: 'auth-tool',
            toolArguments: [],
            originalRequestId: 2,
        );

        try {
            $context->url('Please authenticate', 'https://example.com/auth', 'elicit-123');
            $this->fail('Expected ElicitationSuspendException');
        } catch (ElicitationSuspendException $e) {
            $this->assertSame('url', $e->request->mode);
            $this->assertSame('https://example.com/auth', $e->request->url);
            $this->assertSame('elicit-123', $e->request->elicitationId);
        }
    }

    /**
     * Test requiresForm throws ElicitationDeclinedException when client declines.
     */
    public function testRequiresFormThrowsOnDecline(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true)
        );
        $context = new ElicitationContext(
            session: $session,
            httpMode: true,
            preloadedResults: [
                0 => ['action' => 'decline'],
            ],
            toolName: 'test-tool',
            toolArguments: [],
            originalRequestId: 1,
        );

        $this->expectException(ElicitationDeclinedException::class);
        $context->requiresForm('Need info', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]);
    }

    /**
     * Test requiresForm throws ElicitationDeclinedException when unsupported.
     */
    public function testRequiresFormThrowsWhenUnsupported(): void
    {
        $session = $this->createSessionWithCapabilities(null);
        $context = new ElicitationContext(session: $session);

        $this->expectException(ElicitationDeclinedException::class);
        $context->requiresForm('Need info', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]);
    }

    /**
     * Test requiresForm returns result when client accepts.
     */
    public function testRequiresFormReturnsOnAccept(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true)
        );
        $context = new ElicitationContext(
            session: $session,
            httpMode: true,
            preloadedResults: [
                0 => ['action' => 'accept', 'content' => ['name' => 'Test']],
            ],
            toolName: 'test-tool',
            toolArguments: [],
            originalRequestId: 1,
        );

        $result = $context->requiresForm('Need info', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]);
        $this->assertSame('accept', $result->action);
        $this->assertSame('Test', $result->content['name']);
    }

    /**
     * Test throwUrlRequired throws McpServerException with -32042 error code.
     */
    public function testThrowUrlRequiredThrowsMcpError(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true, url: true),
            '2025-11-25'
        );
        $context = new ElicitationContext(session: $session);

        try {
            $context->throwUrlRequired(
                'Authorization required',
                'https://example.com/oauth/start',
                'elicit-abc',
            );
            $this->fail('Expected McpServerException');
        } catch (McpServerException $e) {
            $this->assertSame(-32042, $e->error->code);
            $this->assertSame('Authorization required', $e->error->message);
            $this->assertIsArray($e->error->data);
            $this->assertArrayHasKey('elicitations', $e->error->data);
            $elicitations = $e->error->data['elicitations'];
            $this->assertCount(1, $elicitations);
            $this->assertSame('url', $elicitations[0]['mode']);
            $this->assertSame('https://example.com/oauth/start', $elicitations[0]['url']);
            $this->assertSame('elicit-abc', $elicitations[0]['elicitationId']);
        }
    }

    /**
     * Test throwUrlRequired auto-generates elicitationId when not provided.
     */
    public function testThrowUrlRequiredAutoGeneratesId(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true, url: true),
            '2025-11-25'
        );
        $context = new ElicitationContext(session: $session);

        try {
            $context->throwUrlRequired('Auth needed', 'https://example.com/auth');
            $this->fail('Expected McpServerException');
        } catch (McpServerException $e) {
            $elicitations = $e->error->data['elicitations'];
            $this->assertNotEmpty($elicitations[0]['elicitationId']);
            // Should be a 32-char hex string (16 random bytes)
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $elicitations[0]['elicitationId']);
        }
    }

    /**
     * Test throwMultipleUrlRequired with multiple elicitations.
     */
    public function testThrowMultipleUrlRequired(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true, url: true),
            '2025-11-25'
        );
        $context = new ElicitationContext(session: $session);

        try {
            $context->throwMultipleUrlRequired([
                ['message' => 'Connect GitHub', 'url' => 'https://example.com/github', 'elicitationId' => 'gh-1'],
                ['message' => 'Connect Slack', 'url' => 'https://example.com/slack'],
            ], 'Multiple authorizations required');
            $this->fail('Expected McpServerException');
        } catch (McpServerException $e) {
            $this->assertSame(-32042, $e->error->code);
            $elicitations = $e->error->data['elicitations'];
            $this->assertCount(2, $elicitations);
            $this->assertSame('gh-1', $elicitations[0]['elicitationId']);
            $this->assertSame('url', $elicitations[0]['mode']);
            $this->assertSame('url', $elicitations[1]['mode']);
            // Second one should have auto-generated ID
            $this->assertNotEmpty($elicitations[1]['elicitationId']);
        }
    }

    /**
     * Test notifyUrlComplete sends notification via session.
     */
    public function testNotifyUrlCompleteSendsNotification(): void
    {
        // Create session with our test transport directly so we can capture output
        $transport = new ElicitationTestTransport();
        $initOptions = new InitializationOptions(
            serverName: 'test',
            serverVersion: '1.0.0',
            capabilities: new ServerCapabilities(),
        );
        $session = new ServerSession($transport, $initOptions, new NullLogger());

        // Set up initialized state via reflection
        $ref = new \ReflectionClass($session);
        $initStateField = $ref->getProperty('initializationState');
        $initStateField->setValue($session, InitializationState::Initialized);
        $clientParams = new InitializeRequestParams(
            protocolVersion: '2025-11-25',
            capabilities: new ClientCapabilities(
                elicitation: new ElicitationCapability(form: true, url: true)
            ),
            clientInfo: new Implementation(name: 'test', version: '1.0')
        );
        $ref->getProperty('clientParams')->setValue($session, $clientParams);
        $ref->getProperty('negotiatedProtocolVersion')->setValue($session, '2025-11-25');

        $context = new ElicitationContext(session: $session);
        $context->notifyUrlComplete('elicit-xyz');

        $this->assertCount(1, $transport->writtenMessages);
        $msg = $transport->writtenMessages[0]->message;
        $this->assertInstanceOf(\Mcp\Types\JSONRPCNotification::class, $msg);
        $this->assertSame('notifications/elicitation/complete', $msg->method);
    }

    /**
     * Test the suspend exception carries correct data.
     */
    public function testSuspendExceptionCarriesCorrectData(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true)
        );
        $context = new ElicitationContext(
            session: $session,
            httpMode: true,
            preloadedResults: [],
            toolName: 'my-tool',
            toolArguments: ['x' => 1],
            originalRequestId: 42,
        );

        try {
            $context->form('Question', [
                'type' => 'object',
                'properties' => ['answer' => ['type' => 'string']],
            ]);
            $this->fail('Expected ElicitationSuspendException');
        } catch (ElicitationSuspendException $e) {
            $this->assertSame('my-tool', $e->toolName);
            $this->assertSame(['x' => 1], $e->toolArguments);
            $this->assertSame(42, $e->originalRequestId);
            $this->assertSame(0, $e->elicitationSequence);
            $this->assertSame('form', $e->request->mode);
            $this->assertSame('Question', $e->request->message);
        }
    }

    // -------------------------------------------------------------------
    // ServerSession low-level API: form-mode sub-capability guard
    // -------------------------------------------------------------------

    /**
     * A URL-only client ("elicitation": {"url": {}}) must not receive
     * form-mode elicitation requests.  ServerSession::sendElicitationRequest()
     * must return null in this case.
     */
    public function testSendElicitationRequestRejectsFormForUrlOnlyClient(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(url: true),  // URL-only, form is null
            '2025-11-25',
        );

        $result = $session->sendElicitationRequest(
            message: 'Need your name',
            requestedSchema: [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
            ],
        );

        $this->assertNull($result, 'Form request to a URL-only client must return null');
    }

    /**
     * A form-only client ("elicitation": {"form": {}}) must not receive
     * URL-mode elicitation requests.  This was already guarded; included
     * here for symmetry.
     */
    public function testSendElicitationRequestRejectsUrlForFormOnlyClient(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(form: true),  // form only
            '2025-11-25',
        );

        $result = $session->sendElicitationRequest(
            message: 'Authorize',
            url: 'https://example.com/oauth',
            elicitationId: 'e-1',
        );

        $this->assertNull($result, 'URL request to a form-only client must return null');
    }

    /**
     * An empty capability object ("elicitation": {}) means form-only per
     * spec.  Form requests must be allowed through the guards.
     *
     * We use a transport that feeds back a valid elicitation response so
     * the blocking waitForResponse() completes normally.
     */
    public function testSendElicitationRequestAllowsFormForEmptyCapability(): void
    {
        // Build a transport that returns an accept response on the first
        // readMessage() call after the outgoing request is written.
        $transport = new class extends ElicitationTestTransport {
            public function readMessage(): ?\Mcp\Types\JsonRpcMessage
            {
                // Once the session has written the elicitation request,
                // respond to it so waitForResponse() can complete.
                if (!empty($this->writtenMessages)) {
                    $outgoing = $this->writtenMessages[0]->message;
                    if ($outgoing instanceof \Mcp\Types\JSONRPCRequest) {
                        return new \Mcp\Types\JsonRpcMessage(
                            new \Mcp\Types\JSONRPCResponse(
                                jsonrpc: '2.0',
                                id: $outgoing->id,
                                result: [
                                    'action' => 'accept',
                                    'content' => ['x' => 'hello'],
                                ],
                            )
                        );
                    }
                }
                return null;
            }
        };

        $initOptions = new InitializationOptions(
            serverName: 'test',
            serverVersion: '1.0.0',
            capabilities: new ServerCapabilities(),
        );
        $session = new ServerSession($transport, $initOptions, new NullLogger());

        $ref = new \ReflectionClass($session);
        $ref->getProperty('initializationState')
            ->setValue($session, InitializationState::Initialized);
        $ref->getProperty('negotiatedProtocolVersion')
            ->setValue($session, '2025-11-25');
        $ref->getProperty('clientParams')
            ->setValue($session, new InitializeRequestParams(
                protocolVersion: '2025-11-25',
                capabilities: new ClientCapabilities(
                    elicitation: new ElicitationCapability(), // empty = form-only
                ),
                clientInfo: new Implementation('test', '1.0'),
            ));

        $result = $session->sendElicitationRequest(
            message: 'Need info',
            requestedSchema: [
                'type' => 'object',
                'properties' => ['x' => ['type' => 'string']],
            ],
        );

        $this->assertNotNull($result, 'Guard must pass for empty capability (form-only)');
        $this->assertSame('accept', $result->action);
        $this->assertSame('hello', $result->content['x']);
    }

    /**
     * Verify ElicitationContext::supportsForm() returns false for a
     * URL-only client, matching the low-level guard.
     */
    public function testSupportsFormReturnsFalseForUrlOnlyClient(): void
    {
        $session = $this->createSessionWithCapabilities(
            new ElicitationCapability(url: true),
            '2025-11-25',
        );
        $context = new ElicitationContext(session: $session);

        $this->assertFalse($context->supportsForm());
        $this->assertTrue($context->supportsUrl());
    }
}
