<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Filename: tests/Server/HttpRequestMetadataHeadersTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\HttpServerRunner;
use Mcp\Server\InitializationOptions;
use Mcp\Server\McpServer;
use Mcp\Server\NotificationOptions;
use Mcp\Server\Server;
use Mcp\Server\Transport\Http\BufferedIo;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Shared\McpHeaders;
use Mcp\Types\ListToolsResult;
use Mcp\Types\MetaKeys;
use Mcp\Types\RequestParams;
use Mcp\Types\Result;
use PHPUnit\Framework\TestCase;

/**
 * SEP-2243 server-side validation of the request-metadata headers on the
 * modern (2026-07-28) HTTP path.
 *
 * Mirrors the draft conformance suite's http-header-validation and
 * http-custom-header-server-validation scenarios plus the
 * sep-2575-http-server-header-mismatch-400 check:
 * - Mcp-Method is required on every modern request and must equal the body
 *   method (values case-sensitive, OWS-trimmed, header names
 *   case-insensitive).
 * - Mcp-Name is required on the name/uri-bearing methods and must match.
 * - MCP-Protocol-Version must be present and equal the _meta envelope's
 *   protocolVersion; a disagreement is -32020 HeaderMismatch (checked
 *   BEFORE the -32022 unsupported-version error, which only fires when
 *   header and _meta agree).
 * - Designated tool parameters (x-mcp-header) must arrive mirrored in
 *   matching Mcp-Param-* headers, with strict base64-sentinel decoding.
 * All rejections are HTTP 400 + JSON-RPC -32020 echoing the request id.
 * Legacy requests carry none of these headers and are untouched.
 */
final class HttpRequestMetadataHeadersTest extends TestCase
{
    private function makeRunner(): HttpServerRunner
    {
        $server = new Server('hdr-test');
        $server->registerHandler('tools/list', function (?RequestParams $params): Result {
            return new ListToolsResult([]);
        });
        $initOptions = new InitializationOptions(
            serverName: 'hdr-test',
            serverVersion: '1.0.0',
            capabilities: $server->getCapabilities(new NotificationOptions(), []),
        );
        return new HttpServerRunner($server, $initOptions, [], null, null, new BufferedIo());
    }

    /**
     * Runner backed by an McpServer exposing a tool with x-mcp-header
     * annotations, for the Mcp-Param-* validation cases.
     */
    private function makeMcpRunner(): HttpServerRunner
    {
        $mcp = new McpServer('hdr-param-test');
        $mcp->tool(
            name: 'annotated_tool',
            description: 'Tool with designated header params',
            callback: fn () => 'ok',
            inputSchema: [
                'properties' => [
                    'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                    'priority' => ['type' => 'integer', 'x-mcp-header' => 'Priority'],
                    'verbose' => ['type' => 'boolean', 'x-mcp-header' => 'Verbose'],
                    'plain' => ['type' => 'string'],
                ],
                'required' => [],
            ],
        );
        $server = $mcp->getServer();
        $initOptions = new InitializationOptions(
            serverName: 'hdr-param-test',
            serverVersion: '1.0.0',
            capabilities: $server->getCapabilities(new NotificationOptions(), []),
        );
        return new HttpServerRunner($server, $initOptions, [], null, null, new BufferedIo());
    }

    private function envelope(string $version = '2026-07-28'): array
    {
        return [
            MetaKeys::PROTOCOL_VERSION => $version,
            MetaKeys::CLIENT_INFO => ['name' => 'hdr-client', 'version' => '1.0.0'],
            MetaKeys::CLIENT_CAPABILITIES => [],
        ];
    }

    private function body(string $method, array $params, int $id = 1): string
    {
        return (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params === [] ? new \stdClass() : $params,
        ]);
    }

    /**
     * A conforming modern POST: version header mirrored from the envelope,
     * Mcp-Method from the body method, Mcp-Name where the method bears one.
     * $headers overrides/augments; a null override removes the header.
     *
     * @param array<string, string|null> $headers
     */
    private function post(string $body, array $headers = []): HttpMessage
    {
        $request = new HttpMessage($body);
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', 'application/json, text/event-stream');

        $decoded = json_decode($body, true);
        $metaVersion = $decoded['params']['_meta'][MetaKeys::PROTOCOL_VERSION] ?? null;
        if (is_string($metaVersion)) {
            $request->setHeader('MCP-Protocol-Version', $metaVersion);
        }
        if (isset($decoded['method'])) {
            $request->setHeader('Mcp-Method', $decoded['method']);
            $params = is_array($decoded['params'] ?? null) ? $decoded['params'] : null;
            $name = McpHeaders::expectedNameValue($decoded['method'], $params);
            if ($name !== null) {
                $request->setHeader('Mcp-Name', $name);
            }
        }

        foreach ($headers as $name => $value) {
            if ($value === null) {
                $request->removeHeader($name);
            } else {
                $request->setHeader($name, $value);
            }
        }
        return $request;
    }

    private function assertHeaderMismatch(HttpMessage $response, int $expectedId): array
    {
        $this->assertSame(400, $response->getStatusCode(), 'HeaderMismatch MUST be HTTP 400');
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(-32020, $body['error']['code'] ?? null, 'HeaderMismatch MUST be -32020');
        $this->assertSame($expectedId, $body['id'], 'Error must echo the request id');
        return $body;
    }

    // -------------------------------------------------------------------
    // Mcp-Method / Mcp-Name (conformance http-header-validation cases)
    // -------------------------------------------------------------------

    public function testMismatchedMethodHeaderRejected(): void
    {
        $response = $this->makeRunner()->handleRequest($this->post(
            $this->body('tools/list', ['_meta' => $this->envelope()], id: 100),
            ['Mcp-Method' => 'prompts/list']
        ));
        $this->assertHeaderMismatch($response, 100);
    }

    public function testMissingMethodHeaderRejected(): void
    {
        $response = $this->makeRunner()->handleRequest($this->post(
            $this->body('tools/list', ['_meta' => $this->envelope()], id: 101),
            ['Mcp-Method' => null]
        ));
        $this->assertHeaderMismatch($response, 101);
    }

    public function testMethodHeaderValueIsCaseSensitive(): void
    {
        $response = $this->makeRunner()->handleRequest($this->post(
            $this->body('tools/list', ['_meta' => $this->envelope()], id: 102),
            ['Mcp-Method' => 'TOOLS/LIST']
        ));
        $this->assertHeaderMismatch($response, 102);
    }

    public function testOwsPaddedHeaderValueAccepted(): void
    {
        // RFC 9110 §5.5: optional whitespace around the field value is
        // trimmed before evaluation.
        $response = $this->makeRunner()->handleRequest($this->post(
            $this->body('tools/list', ['_meta' => $this->envelope()]),
            ['Mcp-Method' => '  tools/list  ']
        ));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMismatchedNameHeaderRejected(): void
    {
        $response = $this->makeMcpRunner()->handleRequest($this->post(
            $this->body('tools/call', [
                'name' => 'annotated_tool',
                'arguments' => new \stdClass(),
                '_meta' => $this->envelope(),
            ], id: 103),
            ['Mcp-Name' => 'wrong_tool_name']
        ));
        $this->assertHeaderMismatch($response, 103);
    }

    public function testMissingNameHeaderRejected(): void
    {
        $response = $this->makeMcpRunner()->handleRequest($this->post(
            $this->body('tools/call', [
                'name' => 'annotated_tool',
                'arguments' => new \stdClass(),
                '_meta' => $this->envelope(),
            ], id: 104),
            ['Mcp-Name' => null]
        ));
        $this->assertHeaderMismatch($response, 104);
    }

    public function testOwsPaddedNameHeaderAccepted(): void
    {
        $response = $this->makeMcpRunner()->handleRequest($this->post(
            $this->body('tools/call', [
                'name' => 'annotated_tool',
                'arguments' => new \stdClass(),
                '_meta' => $this->envelope(),
            ]),
            ['Mcp-Name' => '  annotated_tool  ']
        ));
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('result', $body);
    }

    // -------------------------------------------------------------------
    // MCP-Protocol-Version header vs _meta (-32020 before -32022)
    // -------------------------------------------------------------------

    public function testVersionHeaderMetaMismatchIsHeaderMismatch(): void
    {
        // The conformance probe: header carries the run's version while
        // _meta says v999.0.0 — that disagreement is -32020, NOT -32022.
        $envelope = $this->envelope();
        $envelope[MetaKeys::PROTOCOL_VERSION] = 'v999.0.0';
        $response = $this->makeRunner()->handleRequest($this->post(
            $this->body('server/discover', ['_meta' => $envelope], id: 302),
            ['MCP-Protocol-Version' => '2026-07-28']
        ));
        $this->assertHeaderMismatch($response, 302);
    }

    public function testAgreedUnsupportedVersionIsStill32004(): void
    {
        // -32022 only fires when header and _meta AGREE on an unsupported
        // version — the validation order the spec's reference fixes.
        $envelope = $this->envelope('v999.0.0');
        $response = $this->makeRunner()->handleRequest($this->post(
            $this->body('server/discover', ['_meta' => $envelope], id: 301)
        ));
        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(-32022, $body['error']['code']);
        $this->assertSame('v999.0.0', $body['error']['data']['requested']);
    }

    public function testMissingVersionHeaderOnModernBodyRejected(): void
    {
        // A body that signals the modern era cannot be served as a legacy
        // revision, so the version header is required (the legacy
        // absent-header lenience cannot apply).
        $response = $this->makeRunner()->handleRequest($this->post(
            $this->body('tools/list', ['_meta' => $this->envelope()], id: 105),
            ['MCP-Protocol-Version' => null]
        ));
        $this->assertHeaderMismatch($response, 105);
    }

    public function testModernNotificationRequiresMethodHeader(): void
    {
        // Mcp-Method is required on notifications too; a notification has
        // no id so the error carries id null.
        $body = (string) json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => [
                'progressToken' => 't1',
                'progress' => 1,
                '_meta' => $this->envelope(),
            ],
        ]);
        $response = $this->makeRunner()->handleRequest($this->post($body, ['Mcp-Method' => null]));
        $this->assertSame(400, $response->getStatusCode());
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertSame(-32020, $decoded['error']['code']);
        $this->assertNull($decoded['id']);
    }

    public function testLegacyRequestsNeedNoMetadataHeaders(): void
    {
        // WS6 promise: a legacy initialize POST (no modern signal) is
        // served without any SEP-2243 headers.
        $initBody = (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'legacy', 'version' => '1.0'],
            ],
        ]);
        $request = new HttpMessage($initBody);
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', 'application/json');
        $response = $this->makeRunner()->handleRequest($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------
    // Mcp-Param-* (x-mcp-header designated parameters)
    // -------------------------------------------------------------------

    private function callAnnotatedTool(array $arguments, array $headers, int $id = 200): HttpMessage
    {
        return $this->makeMcpRunner()->handleRequest($this->post(
            $this->body('tools/call', [
                'name' => 'annotated_tool',
                'arguments' => $arguments === [] ? new \stdClass() : $arguments,
                '_meta' => $this->envelope(),
            ], id: $id),
            $headers
        ));
    }

    public function testParamHeaderPlainValueAccepted(): void
    {
        $response = $this->callAnnotatedTool(
            ['region' => 'us-west1'],
            ['Mcp-Param-Region' => 'us-west1']
        );
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('result', $body);
        $this->assertArrayNotHasKey('error', $body);
    }

    public function testParamHeaderValidBase64Accepted(): void
    {
        $response = $this->callAnnotatedTool(
            ['region' => 'Hello'],
            ['Mcp-Param-Region' => '=?base64?SGVsbG8=?=']
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testParamHeaderUppercaseWrapperAccepted(): void
    {
        // Emit lowercase, accept any case (SEP-2243 receiver rule).
        $response = $this->callAnnotatedTool(
            ['region' => 'Hello'],
            ['Mcp-Param-Region' => '=?BASE64?SGVsbG8=?=']
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testParamHeaderBadPaddingRejected(): void
    {
        $response = $this->callAnnotatedTool(
            ['region' => 'Hello'],
            ['Mcp-Param-Region' => '=?base64?SGVsbG8?='],
            id: 201
        );
        $this->assertHeaderMismatch($response, 201);
    }

    public function testParamHeaderInvalidBase64CharsRejected(): void
    {
        $response = $this->callAnnotatedTool(
            ['region' => 'Hello'],
            ['Mcp-Param-Region' => '=?base64?SGVs!!!bG8=?='],
            id: 202
        );
        $this->assertHeaderMismatch($response, 202);
    }

    public function testParamHeaderLiteralWithoutWrapperPrefixNotDecoded(): void
    {
        // No complete sentinel wrapper → literal value, never decoded.
        $response = $this->callAnnotatedTool(
            ['region' => 'SGVsbG8='],
            ['Mcp-Param-Region' => 'SGVsbG8=']
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testParamHeaderLiteralWithoutWrapperSuffixNotDecoded(): void
    {
        $response = $this->callAnnotatedTool(
            ['region' => '=?base64?SGVsbG8='],
            ['Mcp-Param-Region' => '=?base64?SGVsbG8=']
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMissingParamHeaderRejected(): void
    {
        $response = $this->callAnnotatedTool(
            ['region' => 'test-value'],
            [],
            id: 203
        );
        $this->assertHeaderMismatch($response, 203);
    }

    public function testMismatchedParamValueRejected(): void
    {
        $response = $this->callAnnotatedTool(
            ['region' => 'us-west1'],
            ['Mcp-Param-Region' => 'us-east1'],
            id: 204
        );
        $this->assertHeaderMismatch($response, 204);
    }

    public function testNullArgumentRequiresNoHeader(): void
    {
        // Spec: null/absent designated parameter → client omits the
        // header, server MUST NOT expect it.
        $response = $this->callAnnotatedTool(
            ['region' => null, 'plain' => 'x'],
            []
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIntegerParamComparedNumerically(): void
    {
        $response = $this->callAnnotatedTool(
            ['priority' => 42],
            ['Mcp-Param-Priority' => '42.0']
        );
        $this->assertSame(200, $response->getStatusCode(), 'Spec: integer values SHOULD be compared numerically');
    }

    public function testIntegralFloatWithinBoundsAccepted(): void
    {
        // JSON decoding can surface an integer-typed argument as an
        // integral float; within bounds it mirrors normally.
        $response = $this->callAnnotatedTool(
            ['priority' => 42.0],
            ['Mcp-Param-Priority' => '42']
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUnsafeIntegerRejectedEvenAsFloat(): void
    {
        // 9.1e15 > 2^53 - 1: large JSON integers decode as floats and
        // must not bypass the SEP-2243 safe-integer bound.
        $response = $this->callAnnotatedTool(
            ['priority' => 9.1e15],
            ['Mcp-Param-Priority' => '9100000000000000'],
            id: 206
        );
        $this->assertHeaderMismatch($response, 206);
    }

    public function testUnsafeNativeIntegerRejected(): void
    {
        $response = $this->callAnnotatedTool(
            ['priority' => \Mcp\Shared\McpHeaders::MAX_SAFE_INTEGER + 1],
            ['Mcp-Param-Priority' => (string) (\Mcp\Shared\McpHeaders::MAX_SAFE_INTEGER + 1)],
            id: 207
        );
        $this->assertHeaderMismatch($response, 207);
    }

    public function testBooleanParamMatchesLowercaseString(): void
    {
        $response = $this->callAnnotatedTool(
            ['verbose' => false],
            ['Mcp-Param-Verbose' => 'false']
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testBooleanParamMismatchRejected(): void
    {
        $response = $this->callAnnotatedTool(
            ['verbose' => true],
            ['Mcp-Param-Verbose' => 'false'],
            id: 205
        );
        $this->assertHeaderMismatch($response, 205);
    }

    public function testUnannotatedParamNeedsNoHeader(): void
    {
        $response = $this->callAnnotatedTool(
            ['plain' => 'anything'],
            []
        );
        $this->assertSame(200, $response->getStatusCode());
    }
}
