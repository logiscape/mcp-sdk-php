<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 *
 * Filename: tests/Client/ClientMrtrTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client;

use Mcp\Client\ClientSession;
use Mcp\Shared\MemoryStream;
use Mcp\Types\CreateMessageRequest;
use Mcp\Types\CreateMessageResult;
use Mcp\Types\ElicitationCreateRequest;
use Mcp\Types\ElicitationCreateResult;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\ListRootsResult;
use Mcp\Types\RequestId;
use Mcp\Types\Role;
use Mcp\Types\Root;
use Mcp\Types\TextContent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the client side of SEP-2322 multi-round-trip requests (WS3).
 *
 * In modern (2026-07-28) mode, callTool() / getPrompt() / readResource()
 * inspect the RAW result before typed parsing: `resultType:
 * "input_required"` terminates the original request — the client services
 * each `inputRequests` entry through its locally registered handlers and
 * re-sends the SAME method with the SAME original params plus
 * `inputResponses` (keyed identically) and the VERBATIM `requestState`
 * (echoed only when present), under a fresh JSON-RPC id. An absent
 * resultType means 'complete' (no retry); the loop is capped at 16 rounds;
 * loop state is strictly per-call.
 */
final class ClientMrtrTest extends TestCase
{
    /**
     * Build a modern-era session over scripted streams (forced modern —
     * no probe traffic to script around).
     *
     * @return array{ClientSession, MemoryStream, MemoryStream}
     */
    private function modernSession(?callable $configure = null): array
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);
        if ($configure !== null) {
            $configure($session);
        }
        $session->negotiate('modern');
        return [$session, $readStream, $writeStream];
    }

    /** @param array<string, mixed> $result */
    private function response(int $id, array $result): JsonRpcMessage
    {
        return new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId($id),
            result: $result
        ));
    }

    /** @return array<int, array<string, mixed>> Decoded wire form of every sent message, in order. */
    private function sentWire(MemoryStream $writeStream): array
    {
        $wire = [];
        while (($msg = $writeStream->receive()) !== null) {
            $wire[] = json_decode((string) json_encode($msg), true);
        }
        return $wire;
    }

    /** @return array<string, mixed> */
    private function completeToolResult(): array
    {
        return [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'done']],
        ];
    }

    /** @return array<string, mixed> */
    private function elicitationInputRequest(): array
    {
        return [
            'method' => 'elicitation/create',
            'params' => [
                'message' => 'Confirm?',
                'requestedSchema' => [
                    'type' => 'object',
                    'properties' => ['confirmed' => ['type' => 'boolean']],
                ],
            ],
        ];
    }

    /**
     * One input_required round with an elicitation/create entry and a
     * requestState: the handler is serviced, and the retry re-sends the
     * SAME method with the SAME original params PLUS inputResponses keyed
     * identically and the requestState echoed byte-identically, under a
     * fresh JSON-RPC id.
     */
    public function testInputRequiredRoundServicesElicitationAndRetries(): void
    {
        $requestState = '{"step":1,"nonce":"abc-123"}';
        [$session, $readStream, $writeStream] = $this->modernSession(
            static function (ClientSession $session): void {
                $session->onElicit(
                    static fn (ElicitationCreateRequest $r): ElicitationCreateResult =>
                        new ElicitationCreateResult(action: 'accept', content: ['confirmed' => true])
                );
            }
        );

        $readStream->send($this->response(0, [
            'resultType' => 'input_required',
            'inputRequests' => ['confirm' => $this->elicitationInputRequest()],
            'requestState' => $requestState,
        ]));
        $readStream->send($this->response(1, $this->completeToolResult()));

        $result = $session->callTool('test_tool', ['a' => 1]);
        $this->assertSame('done', $result->content[0]->text);

        $wire = $this->sentWire($writeStream);
        $this->assertCount(2, $wire, 'Original call plus exactly one retry');

        // Original attempt: no MRTR fields.
        $this->assertSame('tools/call', $wire[0]['method']);
        $this->assertSame('test_tool', $wire[0]['params']['name']);
        $this->assertArrayNotHasKey('inputResponses', $wire[0]['params']);
        $this->assertArrayNotHasKey('requestState', $wire[0]['params']);

        // Retry: same method + original params, plus the MRTR fields.
        $this->assertSame('tools/call', $wire[1]['method']);
        $this->assertSame('test_tool', $wire[1]['params']['name']);
        $this->assertSame(['a' => 1], $wire[1]['params']['arguments']);
        $this->assertSame(
            ['confirm' => ['action' => 'accept', 'content' => ['confirmed' => true]]],
            $wire[1]['params']['inputResponses'],
            'inputResponses keyed identically to inputRequests'
        );
        $this->assertSame($requestState, $wire[1]['params']['requestState'], 'requestState echoed verbatim');
        $this->assertNotSame($wire[0]['id'], $wire[1]['id'], 'Fresh JSON-RPC id on the retry');
    }

    /**
     * When the input_required result carries NO requestState, the retry
     * must omit the requestState key entirely (never send null/empty).
     */
    public function testRetryOmitsRequestStateWhenResultCarriedNone(): void
    {
        [$session, $readStream, $writeStream] = $this->modernSession(
            static function (ClientSession $session): void {
                $session->onElicit(
                    static fn (ElicitationCreateRequest $r): ElicitationCreateResult =>
                        new ElicitationCreateResult(action: 'accept', content: ['confirmed' => true])
                );
            }
        );

        $readStream->send($this->response(0, [
            'resultType' => 'input_required',
            'inputRequests' => ['confirm' => $this->elicitationInputRequest()],
        ]));
        $readStream->send($this->response(1, $this->completeToolResult()));

        $session->callTool('test_tool', []);

        $wire = $this->sentWire($writeStream);
        $this->assertCount(2, $wire);
        $this->assertArrayHasKey('inputResponses', $wire[1]['params']);
        $this->assertArrayNotHasKey('requestState', $wire[1]['params'], 'Absent requestState must not be echoed');
    }

    /**
     * A result WITHOUT resultType is treated as 'complete' (a legacy
     * peer): no retry, the typed result is returned from the single
     * round trip.
     */
    public function testAbsentResultTypeIsCompleteAndNeverRetried(): void
    {
        [$session, $readStream, $writeStream] = $this->modernSession();

        $readStream->send($this->response(0, [
            'content' => [['type' => 'text', 'text' => 'plain']],
        ]));

        $result = $session->callTool('test_mrtr_no_result_type', []);
        $this->assertSame('plain', $result->content[0]->text);
        $this->assertCount(1, $this->sentWire($writeStream), 'No retry for a result without resultType');
    }

    /**
     * MRTR also covers getPrompt() and readResource() — the other two
     * methods allowed to answer input_required.
     */
    public function testGetPromptAndReadResourceRunTheLoop(): void
    {
        [$session, $readStream, $writeStream] = $this->modernSession(
            static function (ClientSession $session): void {
                $session->onElicit(
                    static fn (ElicitationCreateRequest $r): ElicitationCreateResult =>
                        new ElicitationCreateResult(action: 'accept', content: ['confirmed' => true])
                );
            }
        );

        // prompts/get round trip
        $readStream->send($this->response(0, [
            'resultType' => 'input_required',
            'inputRequests' => ['c' => $this->elicitationInputRequest()],
            'requestState' => 'sp',
        ]));
        $readStream->send($this->response(1, [
            'resultType' => 'complete',
            'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'hi']]],
        ]));
        $session->getPrompt('test_prompt');

        // resources/read round trip
        $readStream->send($this->response(2, [
            'resultType' => 'input_required',
            'inputRequests' => ['c' => $this->elicitationInputRequest()],
            'requestState' => 'sr',
        ]));
        $readStream->send($this->response(3, [
            'resultType' => 'complete',
            'contents' => [['uri' => 'file:///x', 'text' => 'data']],
        ]));
        $session->readResource('file:///x');

        $wire = $this->sentWire($writeStream);
        $this->assertSame(['prompts/get', 'prompts/get', 'resources/read', 'resources/read'], array_column($wire, 'method'));
        $this->assertSame('sp', $wire[1]['params']['requestState']);
        $this->assertSame('test_prompt', $wire[1]['params']['name'], 'Retry repeats the original params');
        $this->assertSame('sr', $wire[3]['params']['requestState']);
        $this->assertSame('file:///x', $wire[3]['params']['uri'], 'Retry repeats the original params');
    }

    /**
     * sampling/createMessage and roots/list inputRequests are serviced
     * through the onSampling() / onListRoots() registrations, and their
     * responses ride under the same keys.
     */
    public function testSamplingAndRootsInputRequestsAreServiced(): void
    {
        [$session, $readStream, $writeStream] = $this->modernSession(
            static function (ClientSession $session): void {
                $session->onSampling(
                    static fn (CreateMessageRequest $r): CreateMessageResult =>
                        new CreateMessageResult(
                            content: new TextContent('sampled'),
                            model: 'test-model',
                            role: Role::ASSISTANT
                        )
                );
                $session->onListRoots(
                    static fn (): ListRootsResult =>
                        new ListRootsResult([new Root(uri: 'file:///workspace', name: 'workspace')])
                );
            }
        );

        $readStream->send($this->response(0, [
            'resultType' => 'input_required',
            'inputRequests' => [
                'llm' => [
                    'method' => 'sampling/createMessage',
                    'params' => [
                        'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'hi']]],
                        'maxTokens' => 16,
                    ],
                ],
                'roots' => ['method' => 'roots/list', 'params' => []],
            ],
        ]));
        $readStream->send($this->response(1, $this->completeToolResult()));

        $session->callTool('test_tool', []);

        $wire = $this->sentWire($writeStream);
        $responses = $wire[1]['params']['inputResponses'];
        $this->assertSame(['llm', 'roots'], array_keys($responses), 'Responses keyed identically to the requests');
        $this->assertSame('test-model', $responses['llm']['model']);
        $this->assertSame('assistant', $responses['llm']['role']);
        $this->assertSame('file:///workspace', $responses['roots']['roots'][0]['uri']);
    }

    /**
     * Methods outside the SEP-2322 allowlist (elicitation/create,
     * sampling/createMessage, roots/list) fail the call with a clear
     * exception — never silently skipped or guessed at.
     */
    public function testUnknownInputRequestMethodFailsTheCall(): void
    {
        [$session, $readStream] = $this->modernSession();

        $readStream->send($this->response(0, [
            'resultType' => 'input_required',
            'inputRequests' => ['x' => ['method' => 'tools/call', 'params' => []]],
        ]));

        try {
            $session->callTool('test_tool', []);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tools/call', $e->getMessage());
            $this->assertStringContainsString('elicitation/create', $e->getMessage());
        }
    }

    /**
     * An input_required entry requiring a handler that was never
     * registered fails with a clear exception pointing at the
     * registration API.
     */
    public function testMissingHandlerFailsTheCall(): void
    {
        [$session, $readStream] = $this->modernSession(); // no onElicit registered

        $readStream->send($this->response(0, [
            'resultType' => 'input_required',
            'inputRequests' => ['confirm' => $this->elicitationInputRequest()],
        ]));

        try {
            $session->callTool('test_tool', []);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('onElicit', $e->getMessage());
        }
    }

    /**
     * The loop is capped: a server that answers input_required forever is
     * cut off after 16 serviced rounds with a clear exception.
     */
    public function testLoopThrowsAfterSixteenRounds(): void
    {
        $writeStream = new MrtrRecordingWriteStream();
        $readStream = new AlwaysInputRequiredReadStream($writeStream, $this->elicitationInputRequest());
        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);
        $session->onElicit(
            static fn (ElicitationCreateRequest $r): ElicitationCreateResult =>
                new ElicitationCreateResult(action: 'accept', content: ['confirmed' => true])
        );
        $session->negotiate('modern');

        try {
            $session->callTool('test_tool', []);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('16', $e->getMessage());
        }

        // Original + 16 serviced retries were sent before giving up.
        $this->assertCount(17, $writeStream->accepted);
    }

    /**
     * Loop state is strictly per-call: an unrelated request issued after
     * an MRTR exchange must carry neither inputResponses nor requestState,
     * and the caller's original request object stays untouched.
     */
    public function testUnrelatedRequestsNeverPickUpMrtrState(): void
    {
        [$session, $readStream, $writeStream] = $this->modernSession(
            static function (ClientSession $session): void {
                $session->onElicit(
                    static fn (ElicitationCreateRequest $r): ElicitationCreateResult =>
                        new ElicitationCreateResult(action: 'accept', content: ['confirmed' => true])
                );
            }
        );

        $readStream->send($this->response(0, [
            'resultType' => 'input_required',
            'inputRequests' => ['confirm' => $this->elicitationInputRequest()],
            'requestState' => 'opaque-state',
        ]));
        $readStream->send($this->response(1, $this->completeToolResult()));
        $readStream->send($this->response(2, $this->completeToolResult()));

        $session->callTool('test_mrtr_echo_state', []);
        $session->callTool('test_mrtr_unrelated', []);

        $wire = $this->sentWire($writeStream);
        $this->assertCount(3, $wire);
        $this->assertSame('test_mrtr_unrelated', $wire[2]['params']['name']);
        $this->assertArrayNotHasKey('inputResponses', $wire[2]['params'], 'MRTR state must not leak across calls');
        $this->assertArrayNotHasKey('requestState', $wire[2]['params'], 'MRTR state must not leak across calls');
    }

    /**
     * An input_required result carrying neither inputRequests nor
     * requestState is malformed (the spec requires at least one) and
     * fails the call instead of looping.
     */
    public function testEmptyInputRequiredResultFails(): void
    {
        [$session, $readStream] = $this->modernSession();

        $readStream->send($this->response(0, ['resultType' => 'input_required']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('neither inputRequests nor requestState');
        $session->callTool('test_tool', []);
    }
}

/**
 * Write stream that records every accepted message while still queueing it
 * for inspection through the MemoryStream contract.
 */
final class MrtrRecordingWriteStream extends MemoryStream
{
    /** @var JsonRpcMessage[] */
    public array $accepted = [];

    public function send(mixed $message): void
    {
        $this->accepted[] = $message;
        parent::send($message);
    }
}

/**
 * Read stream simulating a server that answers EVERY request with an
 * input_required result (for the round-cap test).
 */
final class AlwaysInputRequiredReadStream extends MemoryStream
{
    private int $served = 0;

    /** @param array<string, mixed> $inputRequest */
    public function __construct(
        private readonly MrtrRecordingWriteStream $writes,
        private readonly array $inputRequest
    ) {
    }

    public function receive(): mixed
    {
        if ($this->served >= count($this->writes->accepted)) {
            return null;
        }
        $msg = $this->writes->accepted[$this->served++];
        $inner = $msg->message;
        if (!($inner instanceof JSONRPCRequest)) {
            return null;
        }
        return new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: $inner->id,
            result: [
                'resultType' => 'input_required',
                'inputRequests' => ['confirm' => $this->inputRequest],
                'requestState' => 'round-' . $this->served,
            ]
        ));
    }
}
