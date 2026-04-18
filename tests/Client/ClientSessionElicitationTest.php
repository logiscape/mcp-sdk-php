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
 */

declare(strict_types=1);

namespace Mcp\Tests\Client;

use Mcp\Client\ClientSession;
use Mcp\Shared\MemoryStream;
use Mcp\Shared\Version;
use Mcp\Types\ElicitationCreateRequest;
use Mcp\Types\ElicitationCreateResult;
use Mcp\Types\Implementation;
use Mcp\Types\InitializeResult;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\RequestId;
use Mcp\Types\ServerCapabilities;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ClientSession::onElicit() and SEP-1034 applyDefaults behavior.
 *
 * Covers:
 * - Capability advertisement at initialize time
 * - Handler invocation on server-initiated elicitation/create requests
 * - Defaults fill-in (accept only, never overwrite, preserves explicit null)
 * - No fill on decline/cancel
 * - Opt-out with applyDefaults=false
 * - Post-initialize registration guard
 */
final class ClientSessionElicitationTest extends TestCase
{
    public function testCapabilityAdvertisedOnlyWhenHandlerRegistered(): void
    {
        // No handler → no elicitation capability in the initialize request.
        $session = $this->makeInitializedSession(null, false, $writeStream);
        $this->assertInstanceOf(ClientSession::class, $session);
        $capabilities = $this->extractInitCapabilities($writeStream);
        $this->assertArrayNotHasKey('elicitation', $capabilities);
    }

    public function testCapabilityAdvertisesFormOnlyWithoutApplyDefaults(): void
    {
        $session = $this->makeInitializedSession(
            static fn() => new ElicitationCreateResult('decline'),
            false,
            $writeStream
        );
        $this->assertInstanceOf(ClientSession::class, $session);
        $capabilities = $this->extractInitCapabilities($writeStream);
        $this->assertArrayHasKey('elicitation', $capabilities);
        $this->assertArrayHasKey('form', $capabilities['elicitation']);
        $this->assertArrayNotHasKey('applyDefaults', $capabilities['elicitation']);
    }

    public function testCapabilityAdvertisesApplyDefaultsWhenOptedIn(): void
    {
        $session = $this->makeInitializedSession(
            static fn() => new ElicitationCreateResult('accept', []),
            true,
            $writeStream
        );
        $this->assertInstanceOf(ClientSession::class, $session);
        $capabilities = $this->extractInitCapabilities($writeStream);
        $this->assertArrayHasKey('elicitation', $capabilities);
        $this->assertArrayHasKey('form', $capabilities['elicitation']);
        $this->assertArrayHasKey('applyDefaults', $capabilities['elicitation']);
        $this->assertTrue($capabilities['elicitation']['applyDefaults']);
    }

    public function testOnElicitRejectsPostInitCall(): void
    {
        $session = $this->makeInitializedSession(null, false, $writeStream);
        $this->expectException(\RuntimeException::class);
        $session->onElicit(static fn() => new ElicitationCreateResult('decline'));
    }

    public function testOnElicitAllowedOnRestoredSession(): void
    {
        // Restored sessions skip the handshake — capabilities were negotiated
        // in a prior PHP request. Registration must still succeed so the
        // server-initiated elicitation/create dispatch path is wired up.
        $session = $this->makeRestoredSession($writeStream);
        $session->onElicit(static fn() => new ElicitationCreateResult('decline'));
        $this->addToAssertionCount(1);
    }

    public function testHandlerInvokedOnRestoredSession(): void
    {
        $invoked = false;
        $handler = static function (ElicitationCreateRequest $request) use (&$invoked): ElicitationCreateResult {
            $invoked = true;
            return new ElicitationCreateResult('accept', []);
        };

        $session = $this->makeRestoredSession($writeStream);
        $session->onElicit($handler, applyDefaults: true);

        $session->dispatchIncomingMessage($this->makeElicitationRequest(9, [
            'properties' => ['name' => ['type' => 'string', 'default' => 'John Doe']],
        ]));

        $this->assertTrue($invoked, 'Restored-session handler should fire');
        $response = $this->receiveResponse($writeStream);
        $this->assertSame(9, $response['id']);
        $this->assertSame('accept', $response['result']['action']);
        $this->assertSame(['name' => 'John Doe'], $response['result']['content']);
    }

    public function testHandlerInvokedAndResponseSentOnElicitationCreate(): void
    {
        $invoked = false;
        $handler = static function (ElicitationCreateRequest $request) use (&$invoked): ElicitationCreateResult {
            $invoked = true;
            return new ElicitationCreateResult('accept', ['name' => 'Jane']);
        };

        $session = $this->makeInitializedSession($handler, false, $writeStream);
        $this->drainInitMessages($writeStream);

        $session->dispatchIncomingMessage($this->makeElicitationRequest(42, [
            'properties' => ['name' => ['type' => 'string', 'default' => 'ignored']],
        ]));

        $this->assertTrue($invoked, 'Handler should have been called');

        $response = $this->receiveResponse($writeStream);
        $this->assertSame(42, $response['id']);
        $this->assertSame('accept', $response['result']['action']);
        $this->assertSame(['name' => 'Jane'], $response['result']['content']);
    }

    public function testApplyDefaultsFillsAllPrimitiveTypes(): void
    {
        $handler = static fn() => new ElicitationCreateResult('accept', []);

        $session = $this->makeInitializedSession($handler, true, $writeStream);
        $this->drainInitMessages($writeStream);

        $session->dispatchIncomingMessage($this->makeElicitationRequest(1, $this->sep1034Schema()));

        $response = $this->receiveResponse($writeStream);
        $this->assertSame('accept', $response['result']['action']);
        $this->assertSame([
            'name' => 'John Doe',
            'age' => 30,
            'score' => 95.5,
            'status' => 'active',
            'verified' => true,
        ], $response['result']['content']);
    }

    public function testApplyDefaultsDoesNotOverrideExplicitValues(): void
    {
        $handler = static fn() => new ElicitationCreateResult('accept', [
            'name' => 'Overridden',
            'verified' => false,
        ]);

        $session = $this->makeInitializedSession($handler, true, $writeStream);
        $this->drainInitMessages($writeStream);

        $session->dispatchIncomingMessage($this->makeElicitationRequest(1, $this->sep1034Schema()));

        $response = $this->receiveResponse($writeStream);
        $content = $response['result']['content'];
        $this->assertSame('Overridden', $content['name'], 'Handler-provided string must not be overwritten');
        $this->assertFalse($content['verified'], 'Handler-provided boolean must not be overwritten');
        $this->assertSame(30, $content['age']);
        $this->assertSame(95.5, $content['score']);
        $this->assertSame('active', $content['status']);
    }

    public function testApplyDefaultsPreservesExplicitNull(): void
    {
        // Spec parity with TS reference PR #1096: only `undefined`-equivalent
        // (missing) keys get filled. An explicit null from the handler must
        // stand — use array_key_exists, not isset.
        $handler = static fn() => new ElicitationCreateResult('accept', ['name' => null]);

        $session = $this->makeInitializedSession($handler, true, $writeStream);
        $this->drainInitMessages($writeStream);

        $session->dispatchIncomingMessage($this->makeElicitationRequest(1, [
            'properties' => [
                'name' => ['type' => 'string', 'default' => 'John Doe'],
            ],
        ]));

        $response = $this->receiveResponse($writeStream);
        $this->assertArrayHasKey('name', $response['result']['content']);
        $this->assertNull($response['result']['content']['name']);
    }

    public function testApplyDefaultsNotAppliedOnDecline(): void
    {
        $handler = static fn() => new ElicitationCreateResult('decline');

        $session = $this->makeInitializedSession($handler, true, $writeStream);
        $this->drainInitMessages($writeStream);

        $session->dispatchIncomingMessage($this->makeElicitationRequest(1, $this->sep1034Schema()));

        $response = $this->receiveResponse($writeStream);
        $this->assertSame('decline', $response['result']['action']);
        $this->assertArrayNotHasKey('content', $response['result']);
    }

    public function testApplyDefaultsNotAppliedOnCancel(): void
    {
        $handler = static fn() => new ElicitationCreateResult('cancel');

        $session = $this->makeInitializedSession($handler, true, $writeStream);
        $this->drainInitMessages($writeStream);

        $session->dispatchIncomingMessage($this->makeElicitationRequest(1, $this->sep1034Schema()));

        $response = $this->receiveResponse($writeStream);
        $this->assertSame('cancel', $response['result']['action']);
        $this->assertArrayNotHasKey('content', $response['result']);
    }

    public function testOptOutForwardsContentVerbatim(): void
    {
        $handler = static fn() => new ElicitationCreateResult('accept', []);

        $session = $this->makeInitializedSession($handler, false, $writeStream);
        $this->drainInitMessages($writeStream);

        $session->dispatchIncomingMessage($this->makeElicitationRequest(1, $this->sep1034Schema()));

        $response = $this->receiveResponse($writeStream);
        $this->assertSame('accept', $response['result']['action']);
        // Without applyDefaults, the SDK must not mutate content — stays empty.
        $this->assertSame([], $response['result']['content']);
    }

    public function testHandlerErrorIsReportedAsJsonRpcError(): void
    {
        $handler = static function (): ElicitationCreateResult {
            throw new \RuntimeException('handler blew up');
        };

        $session = $this->makeInitializedSession($handler, true, $writeStream);
        $this->drainInitMessages($writeStream);

        $session->dispatchIncomingMessage($this->makeElicitationRequest(7, $this->sep1034Schema()));

        $writtenRaw = $writeStream->receive();
        $this->assertInstanceOf(JsonRpcMessage::class, $writtenRaw);
        $this->assertInstanceOf(JSONRPCError::class, $writtenRaw->message);
        $decoded = json_decode(json_encode($writtenRaw), true);
        $this->assertSame(7, $decoded['id']);
        $this->assertSame(-32603, $decoded['error']['code']);
        $this->assertStringContainsString('handler blew up', $decoded['error']['message']);
    }

    // ---------------------------------------------------------------------
    // Fixtures / helpers
    // ---------------------------------------------------------------------

    /**
     * Build a schema mirroring the SEP-1034 client-defaults conformance test.
     *
     * @return array<string, mixed>
     */
    private function sep1034Schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'default' => 'John Doe'],
                'age' => ['type' => 'integer', 'default' => 30],
                'score' => ['type' => 'number', 'default' => 95.5],
                'status' => [
                    'type' => 'string',
                    'enum' => ['active', 'inactive', 'pending'],
                    'default' => 'active',
                ],
                'verified' => ['type' => 'boolean', 'default' => true],
            ],
        ];
    }

    /**
     * Create and initialize a ClientSession, optionally wiring an elicitation
     * handler before the handshake. Returns the session and sets $writeStream
     * to the stream carrying outbound messages (init + subsequent responses).
     */
    private function makeInitializedSession(
        ?callable $handler,
        bool $applyDefaults,
        ?MemoryStream &$writeStream
    ): ClientSession {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();

        $readStream->send(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(0),
            result: [
                'protocolVersion' => Version::LATEST_PROTOCOL_VERSION,
                'capabilities' => [],
                'serverInfo' => ['name' => 'test-server', 'version' => '1.0.0'],
            ]
        )));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);
        if ($handler !== null) {
            $session->onElicit($handler, $applyDefaults);
        }
        $session->initialize();

        return $session;
    }

    /**
     * Pull both init messages (initialize request + initialized notification)
     * off the write stream so subsequent receive() calls see only elicitation
     * responses.
     */
    private function drainInitMessages(MemoryStream $writeStream): void
    {
        $writeStream->receive(); // initialize request
        $writeStream->receive(); // notifications/initialized
    }

    /**
     * Build a session via createRestored() — no handshake, already "initialized."
     * Sets $writeStream to an empty stream so the caller sees only responses
     * to server-initiated requests they dispatch during the test.
     */
    private function makeRestoredSession(?MemoryStream &$writeStream): ClientSession
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();

        $initResult = new InitializeResult(
            capabilities: new ServerCapabilities(),
            serverInfo: new Implementation(name: 'test-server', version: '1.0.0'),
            protocolVersion: Version::LATEST_PROTOCOL_VERSION,
        );

        return ClientSession::createRestored(
            readStream: $readStream,
            writeStream: $writeStream,
            initResult: $initResult,
            negotiatedProtocolVersion: Version::LATEST_PROTOCOL_VERSION,
            nextRequestId: 0,
            readTimeout: 2.0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function extractInitCapabilities(MemoryStream $writeStream): array
    {
        $initMessage = $writeStream->receive();
        $decoded = json_decode(json_encode($initMessage), true);
        return $decoded['params']['capabilities'] ?? [];
    }

    /**
     * @param array<string, mixed> $requestedSchema
     */
    private function makeElicitationRequest(int $id, array $requestedSchema): JsonRpcMessage
    {
        $params = new \Mcp\Types\RequestParams();
        $params->message = 'please accept with defaults';
        $params->requestedSchema = $requestedSchema;

        return new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId($id),
            params: $params,
            method: 'elicitation/create'
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function receiveResponse(MemoryStream $writeStream): array
    {
        $raw = $writeStream->receive();
        $this->assertInstanceOf(JsonRpcMessage::class, $raw);
        $this->assertInstanceOf(JSONRPCResponse::class, $raw->message);
        return json_decode(json_encode($raw), true);
    }
}
