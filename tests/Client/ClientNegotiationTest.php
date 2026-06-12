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
 * Filename: tests/Client/ClientNegotiationTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client;

use Mcp\Client\ClientSession;
use Mcp\Shared\McpError;
use Mcp\Shared\MemoryStream;
use Mcp\Shared\Version;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JsonRpcErrorObject;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\MetaKeys;
use Mcp\Types\RequestId;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for ClientSession::negotiate() — SEP-2575 dual-era client
 * detection (WS2).
 *
 * The probe/fallback sequencing follows the spec's normative rules: probe
 * server/discover with the preferred modern version; on -32004 retry with
 * an advertised version and NEVER fall back; on the other recognized
 * modern errors (-32001/-32003) fail without falling back; on any other
 * error or probe timeout fall back to the legacy initialize handshake.
 * The transports' exception surfaces are simulated through scripted
 * streams, so these cover the stdio shape (JSON-RPC errors) and the HTTP
 * shape (RuntimeException with the HTTP status as code) alike.
 */
final class ClientNegotiationTest extends TestCase
{
    private function discoverResultData(array $supported = ['2026-07-28', '2025-11-25']): array
    {
        return [
            'resultType' => 'complete',
            'supportedVersions' => $supported,
            'capabilities' => ['tools' => ['listChanged' => true]],
            'serverInfo' => ['name' => 'matrix-server', 'version' => '1.0.0'],
            'instructions' => 'be nice',
            'ttlMs' => 60000,
            'cacheScope' => 'public',
        ];
    }

    private function initializeResultData(string $version = '2025-11-25'): array
    {
        return [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => true]],
            'serverInfo' => ['name' => 'matrix-server', 'version' => '1.0.0'],
        ];
    }

    private function response(int $id, array $result): JsonRpcMessage
    {
        return new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId($id),
            result: $result
        ));
    }

    private function error(int $id, int $code, string $message, mixed $data = null): JsonRpcMessage
    {
        return new JsonRpcMessage(new JSONRPCError(
            jsonrpc: '2.0',
            id: new RequestId($id),
            error: new JsonRpcErrorObject(code: $code, message: $message, data: $data)
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

    /**
     * Modern client × modern server: the probe succeeds, the session is
     * immediately ready (no initialize, no initialized notification), the
     * discover result doubles as the initialization result, and EVERY
     * subsequent request carries the per-request _meta envelope.
     */
    public function testModernServerNegotiatesModern(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $readStream->send($this->response(0, $this->discoverResultData()));
        $readStream->send($this->response(1, ['tools' => []]));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);
        $era = $session->negotiate();

        $this->assertSame('modern', $era);
        $this->assertTrue($session->isModernMode());
        $this->assertSame('2026-07-28', $session->getModernWireVersion());
        $this->assertSame('2026-07-28', $session->getNegotiatedProtocolVersion());
        $this->assertTrue($session->supportsFeature('stateless_lifecycle'));
        $this->assertSame('matrix-server', $session->getInitializeResult()->serverInfo->name);
        $this->assertSame('be nice', $session->getInitializeResult()->instructions);

        $session->listTools();

        $wire = $this->sentWire($writeStream);
        $this->assertCount(2, $wire, 'Exactly the probe and the tools/list — no initialize, no initialized notification');
        $this->assertSame('server/discover', $wire[0]['method']);
        $this->assertSame('2026-07-28', $wire[0]['params']['_meta'][MetaKeys::PROTOCOL_VERSION]);
        $this->assertSame('tools/list', $wire[1]['method']);
        $meta = $wire[1]['params']['_meta'];
        $this->assertSame('2026-07-28', $meta[MetaKeys::PROTOCOL_VERSION], 'Every modern request carries the envelope');
        $this->assertSame('mcp-client', $meta[MetaKeys::CLIENT_INFO]['name']);
        $this->assertArrayHasKey(MetaKeys::CLIENT_CAPABILITIES, $meta);
    }

    /**
     * Modern client × legacy server (stdio shape): -32601 to the probe
     * triggers the legacy fallback — initialize is sent, the handshake
     * completes with the initialized notification, era is legacy.
     */
    public function testLegacyServerFallsBackOnMethodNotFound(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $readStream->send($this->error(0, -32601, 'Method not found: server/discover'));
        $readStream->send($this->response(1, $this->initializeResultData()));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);
        $era = $session->negotiate();

        $this->assertSame('legacy', $era);
        $this->assertFalse($session->isModernMode());
        $this->assertNull($session->getModernWireVersion());
        $this->assertSame('2025-11-25', $session->getNegotiatedProtocolVersion());

        $wire = $this->sentWire($writeStream);
        $this->assertSame('server/discover', $wire[0]['method']);
        $this->assertSame('initialize', $wire[1]['method']);
        $this->assertSame(
            Version::LATEST_LEGACY_PROTOCOL_VERSION,
            $wire[1]['params']['protocolVersion'],
            'The fallback handshake requests the latest LEGACY revision'
        );
        $this->assertSame('notifications/initialized', $wire[2]['method']);
        $this->assertArrayNotHasKey(
            '_meta',
            $wire[1]['params'],
            'Legacy requests must not carry the modern envelope'
        );
    }

    /**
     * The fallback is NOT keyed to one specific error code (spec MUST NOT):
     * a legacy server answering -32602 falls back exactly the same way.
     */
    public function testLegacyServerFallsBackOnInvalidParamsToo(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $readStream->send($this->error(0, -32602, 'Invalid params'));
        $readStream->send($this->response(1, $this->initializeResultData()));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        $this->assertSame('legacy', $session->negotiate());
    }

    /**
     * -32004 means the server IS modern: retry with an advertised version
     * (here the RC-window draft identifier), never fall back. The retried
     * session speaks the advertised wire version in every envelope while
     * feature-gating on the canonical 2026-07-28.
     */
    public function testUnsupportedVersionRetriesWithAdvertisedVersion(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $readStream->send($this->error(0, McpError::UNSUPPORTED_PROTOCOL_VERSION, 'Unsupported protocol version', [
            'supported' => [Version::DRAFT_MODERN_PROTOCOL_VERSION],
            'requested' => '2026-07-28',
        ]));
        $readStream->send($this->response(1, $this->discoverResultData([Version::DRAFT_MODERN_PROTOCOL_VERSION])));
        $readStream->send($this->response(2, ['tools' => []]));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);
        $era = $session->negotiate();

        $this->assertSame('modern', $era);
        $this->assertSame(Version::DRAFT_MODERN_PROTOCOL_VERSION, $session->getModernWireVersion());
        $this->assertSame('2026-07-28', $session->getNegotiatedProtocolVersion(), 'Draft alias canonicalizes for feature gating');

        $session->listTools();

        $wire = $this->sentWire($writeStream);
        $this->assertSame('server/discover', $wire[0]['method']);
        $this->assertSame('2026-07-28', $wire[0]['params']['_meta'][MetaKeys::PROTOCOL_VERSION], 'First probe prefers the dated revision');
        $this->assertSame('server/discover', $wire[1]['method']);
        $this->assertSame(
            Version::DRAFT_MODERN_PROTOCOL_VERSION,
            $wire[1]['params']['_meta'][MetaKeys::PROTOCOL_VERSION],
            'Retry carries the advertised identifier'
        );
        $this->assertSame(
            Version::DRAFT_MODERN_PROTOCOL_VERSION,
            $wire[2]['params']['_meta'][MetaKeys::PROTOCOL_VERSION],
            'Subsequent envelopes keep the negotiated wire identifier'
        );
    }

    /**
     * -32004 with NO mutually supported version: a modern server was
     * detected, so falling back to initialize is forbidden — connect fails
     * with a clear error and initialize is never sent.
     */
    public function testUnsupportedVersionWithoutCommonVersionDoesNotFallBack(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $readStream->send($this->error(0, McpError::UNSUPPORTED_PROTOCOL_VERSION, 'Unsupported protocol version', [
            'supported' => ['2099-01-01'],
            'requested' => '2026-07-28',
        ]));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        try {
            $session->negotiate();
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('modern', $e->getMessage());
        }

        foreach ($this->sentWire($writeStream) as $sent) {
            $this->assertNotSame('initialize', $sent['method'], 'Spec: never fall back after -32004');
        }
        $this->assertFalse($session->isModernMode());
    }

    /**
     * The other recognized modern errors (-32003 missing capability,
     * -32001 header mismatch) identify a modern server: they are
     * re-thrown, and the fallback is provably NOT triggered.
     *
     * @dataProvider recognizedModernErrorProvider
     */
    public function testRecognizedModernErrorsDoNotTriggerFallback(int $code): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $readStream->send($this->error(0, $code, 'modern rejection'));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        try {
            $session->negotiate();
            $this->fail('Expected McpError');
        } catch (McpError $e) {
            $this->assertSame($code, $e->error->code);
        }

        $wire = $this->sentWire($writeStream);
        $this->assertCount(1, $wire, 'Only the probe may have been sent');
        $this->assertSame('server/discover', $wire[0]['method']);
    }

    /** @return array<string, array{int}> */
    public static function recognizedModernErrorProvider(): array
    {
        return [
            'missing capability (-32003)' => [McpError::MISSING_REQUIRED_CLIENT_CAPABILITY],
            'header mismatch (-32001)' => [McpError::HEADER_MISMATCH],
        ];
    }

    /**
     * A server that answers the probe with HTTP 200 and a result that does
     * NOT parse as a DiscoverResult (e.g. a legacy server returning a
     * generic result for unknown methods) is a legacy server: fall back
     * instead of surfacing the parse failure (the spec's "any other error"
     * rule; regression test for the stable-suite sse-retry server).
     */
    public function testMalformedDiscoverResultFallsBack(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        // 200-style response whose result lacks serverInfo/supportedVersions
        $readStream->send($this->response(0, ['echo' => 'ok']));
        $readStream->send($this->response(1, $this->initializeResultData()));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        $this->assertSame('legacy', $session->negotiate());
        $this->assertFalse($session->isModernMode());
    }

    /**
     * HTTP shape of the legacy fallback: the transport throws
     * RuntimeException with the HTTP status as its code when a 400 body is
     * NOT a recognized modern JSON-RPC error — that is the spec's
     * empty/unrecognized-body fallback trigger.
     */
    public function testHttp400WithoutModernBodyFallsBack(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new ThrowOnceWriteStream(new RuntimeException('HTTP request failed with status 400', 400));
        $readStream->send($this->response(1, $this->initializeResultData()));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        $this->assertSame('legacy', $session->negotiate());
        $this->assertSame('initialize', $writeStream->accepted[0]->message->method);
    }

    /**
     * HTTP shape of the silent-server fallback: the transport throws the
     * typed HttpRequestTimeoutException when cURL times out without a
     * response (the probe timeout bound applied to enveloped requests) —
     * negotiate() must classify it as a probe timeout and fall back, not
     * propagate it as a transport failure (review finding: probeTimeout
     * did not bound HTTP probes and a cURL timeout failed connect()).
     */
    public function testHttpTransportTimeoutFallsBack(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new ThrowOnceWriteStream(
            new \Mcp\Client\Transport\HttpRequestTimeoutException('HTTP request timed out: (28) Operation timed out')
        );
        $readStream->send($this->response(1, $this->initializeResultData()));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        $this->assertSame('legacy', $session->negotiate());
        $this->assertSame('initialize', $writeStream->accepted[0]->message->method);
    }

    /**
     * Transport-level failures that are NOT 4xx (connection refused, 5xx)
     * are not era signals: they propagate instead of producing a
     * misleading fallback attempt against an unreachable server.
     */
    public function testTransportFailurePropagatesWithoutFallback(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new ThrowOnceWriteStream(new RuntimeException('HTTP request failed: (7) connection refused'));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        try {
            $session->negotiate();
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('connection refused', $e->getMessage());
        }
        $this->assertCount(0, $writeStream->accepted, 'No fallback initialize after a transport failure');
    }

    /**
     * A silent legacy server (answers nothing to unknown pre-initialize
     * requests) is detected by the probe timeout and falls back — the
     * spec's "does not respond within a reasonable timeout" rule. The
     * scripted server stays mute until it sees the fallback initialize.
     */
    public function testSilentServerFallsBackOnProbeTimeout(): void
    {
        $writeStream = new RecordingWriteStream();
        $readStream = new SilentUntilInitializeReadStream($writeStream, $this->initializeResultData());

        $session = new ClientSession($readStream, $writeStream); // no read timeout configured
        $era = $session->negotiate(probeTimeout: 0.2);

        $this->assertSame('legacy', $era);
        $wire = $this->sentWire($writeStream);
        $this->assertSame('server/discover', $wire[0]['method']);
        $this->assertSame('initialize', $wire[1]['method']);
        $this->assertSame('2025-11-25', $session->getNegotiatedProtocolVersion());
    }

    /**
     * mode 'legacy' skips the probe entirely — for servers known to predate
     * 2026-07-28 or fragile ones that mishandle unknown requests.
     */
    public function testLegacyModeSkipsProbe(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $readStream->send($this->response(0, $this->initializeResultData()));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        $this->assertSame('legacy', $session->negotiate('legacy'));
        $wire = $this->sentWire($writeStream);
        $this->assertSame('initialize', $wire[0]['method'], 'No probe in legacy mode');
    }

    /**
     * mode 'modern' (forced): no server/discover probe and no initialize
     * are sent at all — the session enters the stateless modern era
     * immediately with the preferred wire version (the latest revision by
     * default) and every subsequent request carries the envelope. Needed
     * for 2026-07-28 servers that answer -32601 to BOTH server/discover
     * and initialize.
     */
    public function testModernModeSkipsProbeEntirely(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $readStream->send($this->response(0, ['tools' => []]));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);

        $this->assertSame('modern', $session->negotiate('modern'));
        $this->assertTrue($session->isModernMode());
        $this->assertSame(Version::LATEST_PROTOCOL_VERSION, $session->getModernWireVersion());
        $this->assertSame('2026-07-28', $session->getNegotiatedProtocolVersion());

        $session->listTools();

        $wire = $this->sentWire($writeStream);
        $this->assertCount(1, $wire, 'No probe, no initialize — only the tools/list itself');
        $this->assertSame('tools/list', $wire[0]['method']);
        $this->assertSame(
            Version::LATEST_PROTOCOL_VERSION,
            $wire[0]['params']['_meta'][MetaKeys::PROTOCOL_VERSION],
            'Forced-modern requests carry the envelope'
        );
    }

    /**
     * mode 'modern' honors a preferred wire identifier (e.g. the RC-window
     * draft alias the conformance mocks speak) and feature-gates on its
     * canonical dated form.
     */
    public function testModernModeHonorsPreferredVersion(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $readStream->send($this->response(0, ['tools' => []]));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);
        $session->negotiate('modern', null, Version::DRAFT_MODERN_PROTOCOL_VERSION);

        $this->assertSame(Version::DRAFT_MODERN_PROTOCOL_VERSION, $session->getModernWireVersion());
        $this->assertSame('2026-07-28', $session->getNegotiatedProtocolVersion());

        $session->listTools();
        $wire = $this->sentWire($writeStream);
        $this->assertSame(
            Version::DRAFT_MODERN_PROTOCOL_VERSION,
            $wire[0]['params']['_meta'][MetaKeys::PROTOCOL_VERSION]
        );
    }

    /**
     * Forced-modern -32004 recovery: when the FIRST real request is
     * rejected with -32004 carrying a usable data.supported list, the
     * session adopts an advertised version (envelope and header switch
     * together) and retries that request exactly once under a fresh id.
     */
    public function testForcedModernAdoptsAdvertisedVersionOnFirstRequest(): void
    {
        $readStream = new MemoryStream();
        // Snapshot the wire bytes at send time: the retry adopts the new
        // version by mutating the request's (shared) _meta, so decoding
        // the queued message OBJECTS afterwards would show the adopted
        // version on both attempts even though the first attempt was sent
        // with the original one.
        $writeStream = new SnapshotWriteStream();
        $readStream->send($this->error(0, McpError::UNSUPPORTED_PROTOCOL_VERSION, 'Unsupported protocol version', [
            'supported' => [Version::DRAFT_MODERN_PROTOCOL_VERSION],
            'requested' => '2026-07-28',
        ]));
        $readStream->send($this->response(1, ['tools' => []]));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);
        $session->negotiate('modern');

        $result = $session->listTools();
        $this->assertSame([], $result->tools);
        $this->assertSame(Version::DRAFT_MODERN_PROTOCOL_VERSION, $session->getModernWireVersion());

        $wire = $writeStream->wire;
        $this->assertCount(2, $wire, 'Original request plus exactly one retry');
        $this->assertSame('tools/list', $wire[0]['method']);
        $this->assertSame('2026-07-28', $wire[0]['params']['_meta'][MetaKeys::PROTOCOL_VERSION]);
        $this->assertSame('tools/list', $wire[1]['method']);
        $this->assertSame(
            Version::DRAFT_MODERN_PROTOCOL_VERSION,
            $wire[1]['params']['_meta'][MetaKeys::PROTOCOL_VERSION],
            'The retry envelope carries the adopted version'
        );
        $this->assertNotSame($wire[0]['id'], $wire[1]['id'], 'Fresh JSON-RPC id on the retry');
    }

    /**
     * The adopt-and-retry is narrow: a -32004 whose data lacks a usable
     * supported list propagates unchanged (no retry, no adoption).
     */
    public function testForcedModern32004WithoutSupportedListPropagates(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $readStream->send($this->error(0, McpError::UNSUPPORTED_PROTOCOL_VERSION, 'Unsupported protocol version'));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);
        $session->negotiate('modern');

        try {
            $session->listTools();
            $this->fail('Expected McpError');
        } catch (McpError $e) {
            $this->assertSame(McpError::UNSUPPORTED_PROTOCOL_VERSION, $e->error->code);
        }
        $this->assertCount(1, $this->sentWire($writeStream), 'No retry without an advertised list');
        $this->assertSame(Version::LATEST_PROTOCOL_VERSION, $session->getModernWireVersion(), 'No adoption');
    }

    /**
     * Unknown modes are rejected up front.
     */
    public function testInvalidModeRejected(): void
    {
        $session = new ClientSession(new MemoryStream(), new MemoryStream(), readTimeout: 2.0);

        $this->expectException(\InvalidArgumentException::class);
        $session->negotiate('yolo');
    }

    /**
     * Legacy client × any server (matrix completeness): plain initialize()
     * still works exactly as in v1 — no envelope anywhere, version from
     * the handshake.
     */
    public function testPlainLegacyInitializeUnchanged(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $readStream->send($this->response(0, $this->initializeResultData('2025-06-18')));

        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);
        $session->initialize();

        $this->assertSame('2025-06-18', $session->getNegotiatedProtocolVersion());
        $this->assertFalse($session->isModernMode());
        $wire = $this->sentWire($writeStream);
        $this->assertSame('initialize', $wire[0]['method']);
        $this->assertArrayNotHasKey('_meta', $wire[0]['params']);
        $this->assertSame('notifications/initialized', $wire[1]['method']);
    }
}

/**
 * Write stream that records the SERIALIZED form of every message at send
 * time — needed when a later retry mutates an object shared with an
 * already-sent message (e.g. the -32004 version adoption rewriting the
 * request's _meta).
 */
final class SnapshotWriteStream extends MemoryStream
{
    /** @var array<int, array<string, mixed>> */
    public array $wire = [];

    public function send(mixed $message): void
    {
        $this->wire[] = json_decode((string) json_encode($message), true);
        parent::send($message);
    }
}

/**
 * Write stream that throws a configured exception on the FIRST send (the
 * probe), then accepts subsequent messages — simulating the HTTP
 * transport's fail-fast behavior for non-modern error responses.
 */
final class ThrowOnceWriteStream extends MemoryStream
{
    /** @var JsonRpcMessage[] */
    public array $accepted = [];

    private bool $thrown = false;

    public function __construct(private readonly RuntimeException $exception)
    {
    }

    public function send(mixed $message): void
    {
        if (!$this->thrown) {
            $this->thrown = true;
            throw $this->exception;
        }
        $this->accepted[] = $message;
        parent::send($message);
    }
}

/**
 * Write stream that records every accepted message while still queueing it
 * for inspection through the MemoryStream contract.
 */
final class RecordingWriteStream extends MemoryStream
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
 * Read stream simulating a legacy server that stays SILENT on unknown
 * pre-initialize requests (so the probe must time out), then answers the
 * legacy handshake normally once the fallback initialize arrives.
 */
final class SilentUntilInitializeReadStream extends MemoryStream
{
    private bool $answered = false;

    /** @param array<string, mixed> $initializeResult */
    public function __construct(
        private readonly RecordingWriteStream $writes,
        private readonly array $initializeResult
    ) {
    }

    public function receive(): mixed
    {
        if ($this->answered) {
            return null;
        }
        foreach ($this->writes->accepted as $msg) {
            $inner = $msg->message;
            if ($inner instanceof JSONRPCRequest && $inner->method === 'initialize') {
                $this->answered = true;
                return new JsonRpcMessage(new JSONRPCResponse(
                    jsonrpc: '2.0',
                    id: $inner->id,
                    result: $this->initializeResult
                ));
            }
        }
        return null; // silent: the probe sees nothing until it times out
    }
}
