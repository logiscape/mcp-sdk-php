<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Mcp\Server\Elicitation\ElicitationContext;
use Mcp\Server\HttpServerSession;
use Mcp\Server\InitializationOptions;
use Mcp\Server\InitializationState;
use Mcp\Server\McpServer;
use Mcp\Server\Transport\Transport;
use Mcp\Shared\RequestResponder;
use Mcp\Types\CallToolRequest;
use Mcp\Types\ClientCapabilities;
use Mcp\Types\ClientRequest;
use Mcp\Types\ElicitationCapability;
use Mcp\Types\Implementation;
use Mcp\Types\InitializeRequestParams;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\RequestId;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\Tool;
use Mcp\Types\ToolInputSchema;
use Psr\Log\NullLogger;

/**
 * Minimal transport that captures written messages.
 */
class McpServerElicitationTestTransport implements Transport
{
    /** @var JsonRpcMessage[] */
    public array $written = [];

    public function start(): void {}
    public function stop(): void {}
    public function readMessage(): ?JsonRpcMessage { return null; }
    public function writeMessage(JsonRpcMessage $message): void
    {
        $this->written[] = $message;
    }
}

/**
 * Tests for the McpServer convenience wrapper's elicitation integration.
 */
final class McpServerElicitationTest extends TestCase
{
    /**
     * Test that ElicitationContext parameters are excluded from tool input schema.
     */
    public function testElicitationContextExcludedFromSchema(): void
    {
        $server = new McpServer('test');
        $server->enableElicitation();

        $server->tool(
            'configure',
            'A tool that uses elicitation',
            function (string $name, ElicitationContext $elicit, int $count = 5) {
                return "Configured: $name ($count)";
            }
        );

        // Access the internal tools array via reflection
        $ref = new \ReflectionClass($server);
        $toolsField = $ref->getProperty('tools');
        $tools = $toolsField->getValue($server);

        $this->assertCount(1, $tools);
        /** @var Tool $tool */
        $tool = $tools[0];

        // The schema should have 'name' and 'count' but NOT 'elicit'
        $schema = $tool->inputSchema;
        $properties = $schema->properties;

        // ToolInputProperties stores fields as extra fields
        $propKeys = array_keys($properties->getExtraFields());
        $this->assertContains('name', $propKeys);
        $this->assertContains('count', $propKeys);
        $this->assertNotContains('elicit', $propKeys);

        // 'name' should be required, 'count' should not (it has a default)
        $this->assertContains('name', $schema->required ?? []);
        $this->assertNotContains('elicit', $schema->required ?? []);
    }

    /**
     * Test that tool without ElicitationContext still works normally.
     */
    public function testToolWithoutElicitationContext(): void
    {
        $server = new McpServer('test');
        $server->enableElicitation();

        $server->tool('add', 'Add numbers', function (float $a, float $b) {
            return "Sum: " . ($a + $b);
        });

        $ref = new \ReflectionClass($server);
        $toolsField = $ref->getProperty('tools');
        $tools = $toolsField->getValue($server);

        $this->assertCount(1, $tools);
        $schema = $tools[0]->inputSchema;
        $properties = $schema->properties;

        $propKeys = array_keys($properties->getExtraFields());
        $this->assertContains('a', $propKeys);
        $this->assertContains('b', $propKeys);
        $this->assertCount(2, $propKeys);
    }

    /**
     * Test that toolsNeedElicitation tracks which tools need context injection.
     */
    public function testToolsNeedElicitationTracking(): void
    {
        $server = new McpServer('test');
        $server->enableElicitation();

        $server->tool('simple', 'No elicitation', function (string $input) {
            return $input;
        });

        $server->tool('interactive', 'Uses elicitation', function (string $action, ElicitationContext $elicit) {
            return $action;
        });

        $ref = new \ReflectionClass($server);
        $needsField = $ref->getProperty('toolsNeedElicitation');
        $needs = $needsField->getValue($server);

        $this->assertArrayNotHasKey('simple', $needs);
        $this->assertArrayHasKey('interactive', $needs);
        $this->assertTrue($needs['interactive']);
    }

    /**
     * Test enableElicitation returns self for chaining.
     */
    public function testEnableElicitationChaining(): void
    {
        $server = new McpServer('test');
        $result = $server->enableElicitation();
        $this->assertSame($server, $result);
    }

    // -------------------------------------------------------------------
    // End-to-end: throwUrlRequired → JSON-RPC -32042 error
    // -------------------------------------------------------------------

    /**
     * Build an HttpServerSession wired to an McpServer and pre-initialised
     * with the given client elicitation capability.
     *
     * @return array{McpServerElicitationTestTransport, HttpServerSession}
     */
    private function buildWiredSession(
        McpServer $mcpServer,
        ?ElicitationCapability $elicitation = null,
    ): array {
        $transport = new McpServerElicitationTestTransport();
        $inner     = $mcpServer->getServer();

        $initOpts = $inner->createInitializationOptions(
            new \Mcp\Server\NotificationOptions()
        );

        $session = new HttpServerSession($transport, $initOpts, new NullLogger());

        // Fast-forward to Initialized state
        $ref = new \ReflectionClass($session);
        $ref->getProperty('initializationState')
            ->setValue($session, InitializationState::Initialized);
        $ref->getProperty('negotiatedProtocolVersion')
            ->setValue($session, '2025-11-25');
        $ref->getProperty('clientParams')
            ->setValue($session, new InitializeRequestParams(
                protocolVersion: '2025-11-25',
                capabilities: new ClientCapabilities(
                    elicitation: $elicitation
                        ?? new ElicitationCapability(form: true, url: true),
                ),
                clientInfo: new Implementation('test-client', '1.0'),
            ));

        // Wire the McpServer handlers into the session
        $inner->setSession($session);
        $session->registerHandlers($inner->getHandlers());

        return [$transport, $session];
    }

    /**
     * Fire a tools/call request through a session and return the single
     * JSON-RPC message that the session wrote back.
     */
    private function callTool(
        HttpServerSession $session,
        string $toolName,
        array $arguments = [],
    ): JsonRpcMessage {
        $requestId     = new RequestId(1);
        $clientRequest = new ClientRequest(
            new CallToolRequest($toolName, $arguments ?: null),
        );
        $responder = new RequestResponder(
            $requestId,
            ['name' => $toolName, 'arguments' => $arguments ?: null],
            $clientRequest,
            $session,
        );

        $session->handleRequest($responder);

        $ref       = new \ReflectionClass($session);
        $transport = $ref->getProperty('transport')->getValue($session);

        $this->assertNotEmpty(
            $transport->written,
            'Session should have written exactly one response',
        );

        return $transport->written[0];
    }

    /**
     * End-to-end: a tool that calls throwUrlRequired() must produce a
     * JSON-RPC error response with code -32042, and the error's `data`
     * must carry the elicitations array that a spec-compliant client
     * needs to drive the out-of-band URL flow.
     */
    public function testThrowUrlRequiredProducesJsonRpc32042Error(): void
    {
        // ── arrange ─────────────────────────────────────────────────
        $mcpServer = new McpServer('e2e-test');
        $mcpServer->enableElicitation();

        $mcpServer->tool(
            'connect-github',
            'Connect to GitHub',
            function (string $repo, ElicitationContext $elicit) {
                // Simulate: no stored OAuth token yet
                $elicit->throwUrlRequired(
                    'Please authorize access to your GitHub repositories.',
                    'https://myserver.example.com/oauth/github/start',
                    'gh-elicit-001',
                );
            },
        );

        [$transport, $session] = $this->buildWiredSession($mcpServer);

        // ── act ─────────────────────────────────────────────────────
        $response = $this->callTool($session, 'connect-github', ['repo' => 'acme/widgets']);

        // ── assert: outer envelope ──────────────────────────────────
        $inner = $response->message;
        $this->assertInstanceOf(
            JSONRPCError::class,
            $inner,
            'Response must be a JSON-RPC error, not a success result',
        );
        $this->assertSame(1, $inner->id->value, 'Error must echo the request id');

        // ── assert: error code ──────────────────────────────────────
        $this->assertSame(
            -32042,
            $inner->error->code,
            'Error code must be -32042 (URLElicitationRequired)',
        );

        // ── assert: human-readable message ──────────────────────────
        $this->assertSame(
            'Please authorize access to your GitHub repositories.',
            $inner->error->message,
        );

        // ── assert: structured data payload ─────────────────────────
        $data = $inner->error->data;
        $this->assertIsArray($data, 'error.data must be present');
        $this->assertArrayHasKey('elicitations', $data);

        $elicitations = $data['elicitations'];
        $this->assertCount(1, $elicitations);

        $elicitation = $elicitations[0];
        $this->assertSame('url',              $elicitation['mode']);
        $this->assertSame('gh-elicit-001',    $elicitation['elicitationId']);
        $this->assertSame(
            'https://myserver.example.com/oauth/github/start',
            $elicitation['url'],
        );
        $this->assertSame(
            'Please authorize access to your GitHub repositories.',
            $elicitation['message'],
        );
    }

    /**
     * After the out-of-band flow completes (e.g. OAuth callback), a second
     * tools/call with the same tool must succeed normally when the handler
     * no longer throws.
     */
    public function testToolSucceedsOnRetryAfterUrlElicitation(): void
    {
        // ── arrange ─────────────────────────────────────────────────
        $authorised = false;           // mutable flag simulating token storage

        $mcpServer = new McpServer('e2e-test');
        $mcpServer->enableElicitation();

        $mcpServer->tool(
            'fetch-repos',
            'List repos',
            function (string $org, ElicitationContext $elicit) use (&$authorised) {
                if (!$authorised) {
                    $elicit->throwUrlRequired(
                        'Authorization required.',
                        'https://myserver.example.com/oauth/start',
                    );
                }
                return "repos for {$org}: widget, gadget";
            },
        );

        [$transport, $session] = $this->buildWiredSession($mcpServer);

        // ── first call: expect -32042 ───────────────────────────────
        $err = $this->callTool($session, 'fetch-repos', ['org' => 'acme']);
        $this->assertInstanceOf(JSONRPCError::class, $err->message);
        $this->assertSame(-32042, $err->message->error->code);

        // ── simulate OAuth callback setting the token ───────────────
        $authorised = true;

        // ── second call: expect success ─────────────────────────────
        // Reset transport so we only see the new message
        $transport->written = [];

        $ok = $this->callTool($session, 'fetch-repos', ['org' => 'acme']);
        $inner = $ok->message;

        $this->assertInstanceOf(
            JSONRPCResponse::class,
            $inner,
            'Retry after authorisation must succeed',
        );

        // The result is a CallToolResult wrapping a TextContent
        $resultData = $inner->result;
        $json = json_encode($resultData);
        $this->assertStringContainsString('widget', $json);
        $this->assertStringContainsString('gadget', $json);
    }
}
