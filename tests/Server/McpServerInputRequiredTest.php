<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Filename: tests/Server/McpServerInputRequiredTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\Elicitation\ElicitationContext;
use Mcp\Server\HttpServerRunner;
use Mcp\Server\InitializationOptions;
use Mcp\Server\InputRequired\InputContext;
use Mcp\Server\McpServer;
use Mcp\Server\NotificationOptions;
use Mcp\Server\Sampling\SamplingContext;
use Mcp\Server\Transport\Http\BufferedIo;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Types\MetaKeys;
use PHPUnit\Framework\TestCase;

/**
 * SEP-2322 multi-round-trip server behavior over the modern (2026-07-28)
 * HTTP path, mirroring the draft conformance suite's
 * input-required-result-* scenarios:
 *
 * - Round 1 of an input-needing tool answers a successful result with
 *   resultType "input_required", inputRequests keyed by input name, and a
 *   signed string requestState.
 * - The retry (same method+params, new id, inputResponses + echoed
 *   requestState) completes.
 * - Tampered requestState is rejected with a JSON-RPC error; missing or
 *   invalid input responses re-issue the InputRequiredResult; unknown
 *   extra responses are ignored.
 * - Multi-round exchanges carry earlier results in requestState, with a
 *   distinct state every round.
 * - Capability gating: a server MUST NOT include input requests the
 *   request's clientCapabilities did not declare.
 * - prompts/get participates in the mechanism; list methods never return
 *   input_required.
 */
final class McpServerInputRequiredTest extends TestCase
{
    private function makeRunner(array $httpOptions = []): HttpServerRunner
    {
        $mcp = new McpServer('mrtr-test');
        $mcp->tool(
            'ask_name',
            'Elicits the user name',
            function (ElicitationContext $elicit): string {
                $result = $elicit->form(
                    'What is your name?',
                    ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
                    inputKey: 'user_name'
                );
                $content = $result?->content;
                $name = is_object($content) ? ($content->name ?? null) : (is_array($content) ? ($content['name'] ?? null) : null);
                return 'Hello, ' . (is_string($name) ? $name : 'unknown');
            }
        );
        $mcp->tool(
            'ask_model',
            'Requests a sampling completion',
            function (SamplingContext $sampling): string {
                $result = $sampling->prompt('What is the capital of France?');
                return 'Model said: ' . ($result?->content?->text ?? '(nothing)');
            }
        );
        $mcp->tool(
            'ask_roots',
            'Requests the client roots',
            function (InputContext $input): string {
                $input->wantRoots('workspace');
                $results = $input->collect();
                return 'Roots: ' . count($results['workspace']['roots'] ?? []);
            }
        );
        $mcp->tool(
            'ask_everything',
            'Requests elicitation + sampling + roots in one round',
            function (InputContext $input): string {
                $input->wantForm('who', 'Name?', ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]);
                $input->wantSample('llm', [new \Mcp\Types\SamplingMessage(
                    role: \Mcp\Types\Role::USER,
                    content: new \Mcp\Types\TextContent(text: 'Hi')
                )], 50);
                $input->wantRoots('ws');
                $input->collect();
                return 'all gathered';
            }
        );
        $mcp->tool(
            'two_questions',
            'Multi-round: asks name, then color',
            function (ElicitationContext $elicit): string {
                $name = $elicit->form('Name?', ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]], inputKey: 'name_q');
                $color = $elicit->form('Color?', ['type' => 'object', 'properties' => ['color' => ['type' => 'string']]], inputKey: 'color_q');
                return 'done';
            }
        );
        $mcp->tool(
            'capability_aware',
            'Requests only inputs the client declared support for',
            function (InputContext $input): string {
                if ($input->supports('elicitation')) {
                    $input->wantForm('who', 'Name?', ['type' => 'object', 'properties' => []]);
                }
                if ($input->supports('sampling')) {
                    $input->wantSample('llm', [new \Mcp\Types\SamplingMessage(
                        role: \Mcp\Types\Role::USER,
                        content: new \Mcp\Types\TextContent(text: 'Hi')
                    )], 50);
                }
                $input->collect();
                return 'capability-aware complete';
            }
        );
        $mcp->prompt(
            'context_prompt',
            'Prompt that elicits context first',
            function (ElicitationContext $elicit): string {
                $elicit->form('Context?', ['type' => 'object', 'properties' => ['context' => ['type' => 'string']]], inputKey: 'user_context');
                return 'Prompt with context';
            }
        );

        $server = $mcp->getServer();
        $initOptions = new InitializationOptions(
            serverName: 'mrtr-test',
            serverVersion: '1.0.0',
            capabilities: $server->getCapabilities(new NotificationOptions(), []),
        );
        return new HttpServerRunner($server, $initOptions, $httpOptions, null, null, new BufferedIo());
    }

    /**
     * Runner with token auth enabled; every request validates to the
     * given principal (`sub` claim).
     */
    private function makeAuthRunner(?string $sub): HttpServerRunner
    {
        return $this->makeRunner([
            'auth_enabled' => true,
            'resource' => 'https://example.com/mcp',
            'authorization_servers' => ['https://as.example.com'],
            'token_validator' => new FixedPrincipalValidator($sub),
        ]);
    }

    private function envelope(?array $capabilities = null): array
    {
        return [
            MetaKeys::PROTOCOL_VERSION => '2026-07-28',
            MetaKeys::CLIENT_INFO => ['name' => 'mrtr-client', 'version' => '1.0.0'],
            MetaKeys::CLIENT_CAPABILITIES => $capabilities
                ?? ['sampling' => new \stdClass(), 'elicitation' => new \stdClass(), 'roots' => ['listChanged' => true]],
        ];
    }

    /**
     * POST a modern request with conforming SEP-2243 headers; returns the
     * decoded JSON-RPC body.
     */
    private function rpc(HttpServerRunner $runner, string $method, array $params, int $id, ?string $bearer = null): array
    {
        $body = (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);
        $request = new HttpMessage($body);
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('MCP-Protocol-Version', '2026-07-28');
        $request->setHeader('Mcp-Method', $method);
        if ($bearer !== null) {
            $request->setHeader('Authorization', 'Bearer ' . $bearer);
        }
        $name = \Mcp\Shared\McpHeaders::expectedNameValue($method, $params);
        if ($name !== null) {
            $request->setHeader('Mcp-Name', $name);
        }
        $response = $runner->handleRequest($request);
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($decoded, 'Every MRTR exchange is a JSON-RPC body');
        return $decoded;
    }

    private function callTool(HttpServerRunner $runner, string $tool, array $extra = [], int $id = 1, ?array $capabilities = null, ?string $bearer = null): array
    {
        return $this->rpc($runner, 'tools/call', array_merge([
            'name' => $tool,
            'arguments' => new \stdClass(),
            '_meta' => $this->envelope($capabilities),
        ], $extra), $id, $bearer);
    }

    public function testBasicElicitationTwoRounds(): void
    {
        $runner = $this->makeRunner();

        $round1 = $this->callTool($runner, 'ask_name', id: 1);
        $this->assertArrayNotHasKey('error', $round1);
        $result = $round1['result'];
        $this->assertSame('input_required', $result['resultType']);
        $this->assertArrayHasKey('user_name', $result['inputRequests']);
        $this->assertSame('elicitation/create', $result['inputRequests']['user_name']['method']);
        $this->assertIsString($result['requestState']);

        $round2 = $this->callTool($runner, 'ask_name', [
            'inputResponses' => ['user_name' => ['action' => 'accept', 'content' => ['name' => 'Alice']]],
            'requestState' => $result['requestState'],
        ], id: 2);
        $this->assertArrayNotHasKey('error', $round2);
        $final = $round2['result'];
        $this->assertNotSame('input_required', $final['resultType'] ?? null);
        $this->assertSame('Hello, Alice', $final['content'][0]['text']);
    }

    public function testTamperedRequestStateRejected(): void
    {
        $runner = $this->makeRunner();
        $round1 = $this->callTool($runner, 'ask_name', id: 1);
        $state = $round1['result']['requestState'];

        $round2 = $this->callTool($runner, 'ask_name', [
            'inputResponses' => ['user_name' => ['action' => 'accept', 'content' => ['ok' => true]]],
            'requestState' => $state . '-TAMPERED',
        ], id: 2);

        $this->assertArrayHasKey('error', $round2, 'Tampered state MUST be rejected with a JSON-RPC error');
        $this->assertSame(-32602, $round2['error']['code']);
    }

    public function testExpiredRequestStateRejected(): void
    {
        $runner = $this->makeRunner();
        // A state signed with the same secret but an already-passed expiry
        // must be rejected (replay mitigation).
        $codec = new \Mcp\Server\InputRequired\RequestStateCodec('x', -10);
        $this->assertNull($codec->decode($codec->encode(['m' => 'tools/call', 'n' => 'ask_name', 'res' => []])));
    }

    public function testMissingInputResponseReRequests(): void
    {
        $runner = $this->makeRunner();

        // Single call with a wrong-key response and no state: the server
        // SHOULD re-issue the InputRequiredResult, not error or complete.
        $round = $this->callTool($runner, 'ask_name', [
            'inputResponses' => ['wrong_key' => ['action' => 'accept', 'content' => ['data' => 'wrong']]],
        ], id: 1);

        $this->assertArrayNotHasKey('error', $round);
        $this->assertSame('input_required', $round['result']['resultType']);
        $this->assertArrayHasKey('user_name', $round['result']['inputRequests']);
    }

    public function testExtraInputResponsesIgnored(): void
    {
        $runner = $this->makeRunner();

        $round = $this->callTool($runner, 'ask_name', [
            'inputResponses' => [
                'user_name' => ['action' => 'accept', 'content' => ['name' => 'Alice']],
                'unknown_extra_key' => ['action' => 'accept', 'content' => ['foo' => 'bar']],
                'another_unexpected' => ['action' => 'accept', 'content' => ['baz' => 123]],
            ],
        ], id: 1);

        $this->assertArrayNotHasKey('error', $round);
        $this->assertNotSame('input_required', $round['result']['resultType'] ?? null);
    }

    public function testInvalidInputResponseShapesDoNotComplete(): void
    {
        $runner = $this->makeRunner();

        // A non-object response value is re-requested...
        $round = $this->callTool($runner, 'ask_name', [
            'inputResponses' => ['user_name' => 12345],
        ], id: 1);
        $this->assertArrayNotHasKey('error', $round);
        $this->assertSame('input_required', $round['result']['resultType']);

        // ...and a null inputResponses counts as absent (fresh round 1).
        $round = $this->callTool($runner, 'ask_name', [
            'inputResponses' => null,
        ], id: 2);
        $this->assertArrayNotHasKey('error', $round);
        $this->assertSame('input_required', $round['result']['resultType']);
    }

    public function testMultiRoundCarriesStateAndDiffersPerRound(): void
    {
        $runner = $this->makeRunner();

        $r1 = $this->callTool($runner, 'two_questions', id: 1)['result'];
        $this->assertSame('input_required', $r1['resultType']);
        $this->assertArrayHasKey('name_q', $r1['inputRequests']);

        $r2 = $this->callTool($runner, 'two_questions', [
            'inputResponses' => ['name_q' => ['action' => 'accept', 'content' => ['name' => 'Alice']]],
            'requestState' => $r1['requestState'],
        ], id: 2)['result'];
        $this->assertSame('input_required', $r2['resultType']);
        $this->assertArrayHasKey('color_q', $r2['inputRequests']);
        $this->assertNotSame($r1['requestState'], $r2['requestState'], 'Each round gets a distinct state');

        $r3 = $this->callTool($runner, 'two_questions', [
            'inputResponses' => ['color_q' => ['action' => 'accept', 'content' => ['color' => 'blue']]],
            'requestState' => $r2['requestState'],
        ], id: 3)['result'];
        $this->assertNotSame('input_required', $r3['resultType'] ?? null);
        $this->assertSame('done', $r3['content'][0]['text']);
    }

    public function testMultipleInputRequestsInOneRound(): void
    {
        $runner = $this->makeRunner();

        $r1 = $this->callTool($runner, 'ask_everything', id: 1)['result'];
        $this->assertSame('input_required', $r1['resultType']);
        $methods = array_map(
            static fn (array $req) => $req['method'],
            $r1['inputRequests']
        );
        sort($methods);
        $this->assertSame(
            ['elicitation/create', 'roots/list', 'sampling/createMessage'],
            $methods,
            'All three input types batch into a single round'
        );
        $this->assertIsString($r1['requestState']);

        $r2 = $this->callTool($runner, 'ask_everything', [
            'inputResponses' => [
                'who' => ['action' => 'accept', 'content' => ['name' => 'Alice']],
                'llm' => ['role' => 'assistant', 'content' => ['type' => 'text', 'text' => 'Hello there!'], 'model' => 'test-model', 'stopReason' => 'endTurn'],
                'ws' => ['roots' => [['uri' => 'file:///test/root', 'name' => 'Test Root']]],
            ],
            'requestState' => $r1['requestState'],
        ], id: 2)['result'];
        $this->assertNotSame('input_required', $r2['resultType'] ?? null);
        $this->assertSame('all gathered', $r2['content'][0]['text']);
    }

    public function testRootsInputRequest(): void
    {
        $runner = $this->makeRunner();

        $r1 = $this->callTool($runner, 'ask_roots', id: 1)['result'];
        $this->assertSame('input_required', $r1['resultType']);
        $key = array_key_first($r1['inputRequests']);
        $this->assertSame('roots/list', $r1['inputRequests'][$key]['method']);

        $r2 = $this->callTool($runner, 'ask_roots', [
            'inputResponses' => [$key => ['roots' => [['uri' => 'file:///test/root', 'name' => 'Test Root']]]],
            'requestState' => $r1['requestState'],
        ], id: 2)['result'];
        $this->assertSame('Roots: 1', $r2['content'][0]['text']);
    }

    public function testSamplingInputRequest(): void
    {
        $runner = $this->makeRunner();

        $r1 = $this->callTool($runner, 'ask_model', id: 1)['result'];
        $this->assertSame('input_required', $r1['resultType']);
        $key = array_key_first($r1['inputRequests']);
        $this->assertSame('sampling/createMessage', $r1['inputRequests'][$key]['method']);

        $r2 = $this->callTool($runner, 'ask_model', [
            'inputResponses' => [$key => [
                'role' => 'assistant',
                'content' => ['type' => 'text', 'text' => 'Paris.'],
                'model' => 'test-model',
                'stopReason' => 'endTurn',
            ]],
            'requestState' => $r1['requestState'],
        ], id: 2)['result'];
        $this->assertSame('Model said: Paris.', $r2['content'][0]['text']);
    }

    public function testCapabilityGatingExcludesUndeclaredInputTypes(): void
    {
        $runner = $this->makeRunner();

        // Client declares ONLY sampling: the server must not include any
        // elicitation/create input request — and must not error either.
        $round = $this->callTool(
            $runner,
            'capability_aware',
            id: 1,
            capabilities: ['sampling' => new \stdClass()]
        );

        $this->assertArrayNotHasKey('error', $round);
        $result = $round['result'];
        $this->assertSame('input_required', $result['resultType']);
        foreach ($result['inputRequests'] as $request) {
            $this->assertNotSame(
                'elicitation/create',
                $request['method'],
                'Spec: servers MUST NOT send input requests the client did not declare support for'
            );
        }
    }

    public function testPromptsGetParticipatesInMrtr(): void
    {
        $runner = $this->makeRunner();

        $r1 = $this->rpc($runner, 'prompts/get', [
            'name' => 'context_prompt',
            '_meta' => $this->envelope(),
        ], id: 1)['result'];
        $this->assertSame('input_required', $r1['resultType']);
        $this->assertArrayHasKey('user_context', $r1['inputRequests']);

        $r2 = $this->rpc($runner, 'prompts/get', [
            'name' => 'context_prompt',
            'inputResponses' => ['user_context' => ['action' => 'accept', 'content' => ['context' => 'test context']]],
            'requestState' => $r1['requestState'],
            '_meta' => $this->envelope(),
        ], id: 2)['result'];
        $this->assertNotSame('input_required', $r2['resultType'] ?? null);
        $this->assertArrayHasKey('messages', $r2, 'Completed prompts/get returns a GetPromptResult');
    }

    public function testRequestStateIsBoundToTheAuthenticatedPrincipal(): void
    {
        // SEP-2322: requestState is bound to the principal it was issued
        // for. A state minted for one user (here: an anonymous round 1)
        // replayed by a DIFFERENT authenticated user must fail
        // verification exactly like tampering. Both runners share the
        // default per-installation secret file, so only the principal
        // binding can reject the replay.
        $anonymous = $this->makeRunner();
        $round1 = $this->callTool($anonymous, 'ask_name', id: 1);
        $state = $round1['result']['requestState'];

        $asBob = $this->makeAuthRunner('user-bob');
        $replay = $this->callTool($asBob, 'ask_name', [
            'inputResponses' => ['user_name' => ['action' => 'accept', 'content' => ['name' => 'Mallory']]],
            'requestState' => $state,
        ], id: 2, bearer: 'bob-token');

        $this->assertArrayHasKey('error', $replay, 'Another principal replaying captured state must be rejected');
        $this->assertSame(-32602, $replay['error']['code']);
    }

    public function testRequestStateRoundTripsForTheSamePrincipal(): void
    {
        $round1 = $this->callTool($this->makeAuthRunner('user-alice'), 'ask_name', id: 1, bearer: 'alice-token');
        $this->assertSame('input_required', $round1['result']['resultType']);

        // A separate runner instance (a different worker serving the
        // retry) with the SAME principal completes normally.
        $round2 = $this->callTool($this->makeAuthRunner('user-alice'), 'ask_name', [
            'inputResponses' => ['user_name' => ['action' => 'accept', 'content' => ['name' => 'Alice']]],
            'requestState' => $round1['result']['requestState'],
        ], id: 2, bearer: 'alice-token');

        $this->assertArrayNotHasKey('error', $round2);
        $this->assertSame('Hello, Alice', $round2['result']['content'][0]['text']);
    }

    public function testSubLessTokensDoNotShareABinding(): void
    {
        // A validator may accept a token while returning EMPTY claims.
        // Two distinct authenticated users must still never share a
        // requestState binding — the principal falls back to a
        // fingerprint of the presented token.
        $round1 = $this->callTool($this->makeAuthRunner(null), 'ask_name', id: 1, bearer: 'token-of-user-a');
        $this->assertSame('input_required', $round1['result']['resultType']);

        $replay = $this->callTool($this->makeAuthRunner(null), 'ask_name', [
            'inputResponses' => ['user_name' => ['action' => 'accept', 'content' => ['name' => 'Mallory']]],
            'requestState' => $round1['result']['requestState'],
        ], id: 2, bearer: 'token-of-user-b');

        $this->assertArrayHasKey('error', $replay, 'Sub-less tokens must not share a null binding');
        $this->assertSame(-32602, $replay['error']['code']);
    }

    public function testSubLessTokenRoundTripsWithItself(): void
    {
        $round1 = $this->callTool($this->makeAuthRunner(null), 'ask_name', id: 1, bearer: 'stable-token');

        $round2 = $this->callTool($this->makeAuthRunner(null), 'ask_name', [
            'inputResponses' => ['user_name' => ['action' => 'accept', 'content' => ['name' => 'Alice']]],
            'requestState' => $round1['result']['requestState'],
        ], id: 2, bearer: 'stable-token');

        $this->assertArrayNotHasKey('error', $round2, 'The same token fingerprint must verify across workers');
        $this->assertSame('Hello, Alice', $round2['result']['content'][0]['text']);
    }

    public function testListMethodsNeverReturnInputRequired(): void
    {
        $runner = $this->makeRunner();

        foreach (['tools/list', 'prompts/list'] as $i => $method) {
            $body = $this->rpc($runner, $method, ['_meta' => $this->envelope()], id: 10 + $i);
            $this->assertNotSame('input_required', $body['result']['resultType'] ?? null);
        }
    }

    public function testLegacyHttpSuspendResumeUnaffected(): void
    {
        // WS6 promise: the legacy suspend/resume pattern (server-initiated
        // elicitation/create over the session path) is covered extensively
        // by ElicitationHttpIntegrationTest; here we just pin that a
        // LEGACY-era tools/call on this server still raises the legacy
        // suspend machinery rather than answering input_required.
        $runner = $this->makeRunner();

        // Legacy initialize first.
        $init = $this->legacyRpc($runner, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => ['elicitation' => new \stdClass()],
            'clientInfo' => ['name' => 'legacy', 'version' => '1.0'],
        ], 1, null);
        $sessionId = $init['sessionId'];
        $this->assertNotNull($sessionId);

        $call = $this->legacyRpc($runner, 'tools/call', [
            'name' => 'ask_name',
            'arguments' => new \stdClass(),
        ], 2, $sessionId);

        // Legacy era: the server suspends by sending an elicitation/create
        // REQUEST (not an input_required result).
        $this->assertSame('elicitation/create', $call['body']['method'] ?? null);
    }

    /**
     * Legacy-style POST (no modern headers/envelope).
     *
     * @return array{body: array<string, mixed>|null, sessionId: string|null}|array<string, mixed>
     */
    private function legacyRpc(HttpServerRunner $runner, string $method, array $params, int $id, ?string $sessionId): array
    {
        $body = (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);
        $request = new HttpMessage($body);
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', 'application/json');
        if ($sessionId !== null) {
            $request->setHeader('Mcp-Session-Id', $sessionId);
        }
        $response = $runner->handleRequest($request);
        $decoded = json_decode((string) $response->getBody(), true);
        return [
            'body' => is_array($decoded) ? $decoded : null,
            'sessionId' => $response->getHeader('Mcp-Session-Id'),
        ];
    }
}

/**
 * Token validator stub: accepts every bearer token, always yielding the
 * same principal (`sub` claim) — lets tests bind requestState to a user.
 */
final class FixedPrincipalValidator implements \Mcp\Server\Auth\TokenValidatorInterface
{
    /**
     * @param string|null $sub Null simulates a validator that accepts
     *        the token but yields EMPTY claims (TokenValidationResult's
     *        claims default) — the case where principal binding must
     *        fall back to the token fingerprint.
     */
    public function __construct(private readonly ?string $sub)
    {
    }

    public function validate(string $token): \Mcp\Server\Auth\TokenValidationResult
    {
        return new \Mcp\Server\Auth\TokenValidationResult(
            valid: true,
            claims: $this->sub !== null ? ['sub' => $this->sub] : []
        );
    }
}
