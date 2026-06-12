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
 * Filename: tests/Client/Transport/StreamableHttpTransportHeadersTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client\Transport;

use Mcp\Client\Transport\HttpConfiguration;
use Mcp\Client\Transport\StreamableHttpTransport;
use Mcp\Shared\McpHeaders;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\Meta;
use Mcp\Types\MetaKeys;
use Mcp\Types\NotificationParams;
use Mcp\Types\RequestId;
use Mcp\Types\RequestParams;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the transport-level SEP-2243 header derivation (WS3, client).
 *
 * Every outgoing message carrying the modern SEP-2575 `_meta` envelope
 * gets `Mcp-Method` (requests AND notifications) and — for the
 * name-bearing methods — `Mcp-Name` derived verbatim from the message
 * itself (URIs are never re-encoded). Legacy messages (no envelope) get
 * neither. Transient `httpHeaderHints` (the Mcp-Param-* mirrors stamped
 * by the session) are merged into the request headers, and an
 * empty-string header value survives the cURL conversion via the "Name;"
 * form.
 */
final class StreamableHttpTransportHeadersTest extends TestCase
{
    private StreamableHttpTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new StreamableHttpTransport(
            new HttpConfiguration(endpoint: 'http://localhost:9/mcp')
        );
    }

    /** @return array<string, string> */
    private function perMessageHeaders(JsonRpcMessage $message): array
    {
        $method = new \ReflectionMethod($this->transport, 'buildPerMessageHeaders');
        $method->setAccessible(true);
        /** @var array<string, string> */
        return $method->invoke($this->transport, $message);
    }

    private function envelopeMeta(string $version = '2026-07-28'): Meta
    {
        $meta = new Meta();
        $meta->setField(MetaKeys::PROTOCOL_VERSION, $version);
        return $meta;
    }

    /** @param array<string, mixed> $fields */
    private function modernRequest(string $method, array $fields = []): JsonRpcMessage
    {
        $params = new RequestParams($this->envelopeMeta());
        foreach ($fields as $k => $v) {
            $params->$k = $v;
        }
        return new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId(1),
            params: $params,
            method: $method
        ));
    }

    /**
     * tools/call: Mcp-Method mirrors the JSON-RPC method, Mcp-Name mirrors
     * params.name, and MCP-Protocol-Version mirrors the envelope.
     */
    public function testToolsCallCarriesMethodAndName(): void
    {
        $headers = $this->perMessageHeaders($this->modernRequest('tools/call', ['name' => 'my-hyphenated-tool']));

        $this->assertSame('2026-07-28', $headers[McpHeaders::PROTOCOL_VERSION]);
        $this->assertSame('tools/call', $headers[McpHeaders::METHOD]);
        $this->assertSame('my-hyphenated-tool', $headers[McpHeaders::NAME]);
    }

    /**
     * prompts/get mirrors params.name; tasks/get|update|cancel mirror
     * params.taskId.
     *
     * @dataProvider nameBearingMethodProvider
     */
    public function testNameBearingMethodsMirrorTheirSourceField(string $method, string $field, string $value): void
    {
        $headers = $this->perMessageHeaders($this->modernRequest($method, [$field => $value]));

        $this->assertSame($method, $headers[McpHeaders::METHOD]);
        $this->assertSame($value, $headers[McpHeaders::NAME]);
    }

    /** @return array<string, array{string, string, string}> */
    public static function nameBearingMethodProvider(): array
    {
        return [
            'prompts/get' => ['prompts/get', 'name', 'test_prompt'],
            'tasks/get' => ['tasks/get', 'taskId', 'task-123'],
            'tasks/update' => ['tasks/update', 'taskId', 'task-456'],
            'tasks/cancel' => ['tasks/cancel', 'taskId', 'task-789'],
        ];
    }

    /**
     * resources/read mirrors params.uri VERBATIM: percent-encoded URIs are
     * not re-encoded or decoded on the way into the header.
     */
    public function testResourcesReadMirrorsUriVerbatim(): void
    {
        $uri = 'file:///path/to/file%20name.txt';
        $headers = $this->perMessageHeaders($this->modernRequest('resources/read', ['uri' => $uri]));

        $this->assertSame($uri, $headers[McpHeaders::NAME]);
    }

    /**
     * Non-name-bearing methods get Mcp-Method but no Mcp-Name.
     */
    public function testListMethodsCarryNoName(): void
    {
        $headers = $this->perMessageHeaders($this->modernRequest('tools/list'));

        $this->assertSame('tools/list', $headers[McpHeaders::METHOD]);
        $this->assertArrayNotHasKey(McpHeaders::NAME, $headers);
    }

    /**
     * Notifications carrying the envelope get Mcp-Method too (the spec
     * applies SEP-2243 to requests AND notifications).
     */
    public function testEnvelopedNotificationCarriesMethod(): void
    {
        $params = new NotificationParams($this->envelopeMeta());
        $message = new JsonRpcMessage(new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/roots/list_changed',
            params: $params
        ));

        $headers = $this->perMessageHeaders($message);
        $this->assertSame('notifications/roots/list_changed', $headers[McpHeaders::METHOD]);
        $this->assertArrayNotHasKey(McpHeaders::NAME, $headers);
    }

    /**
     * Legacy-era messages (no `_meta` envelope) must NOT get the SEP-2243
     * headers — no version mirror, no Mcp-Method, no Mcp-Name.
     */
    public function testLegacyMessagesGetNoStandardHeaders(): void
    {
        $params = new RequestParams();
        $params->name = 'legacy-tool';
        $message = new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId(1),
            params: $params,
            method: 'tools/call'
        ));

        $this->assertSame([], $this->perMessageHeaders($message));
    }

    /**
     * Transient httpHeaderHints (the session's Mcp-Param-* mirrors) are
     * merged into the per-message headers.
     */
    public function testHttpHeaderHintsAreMerged(): void
    {
        $message = $this->modernRequest('tools/call', ['name' => 'valid_tool']);
        $message->httpHeaderHints = [
            'Mcp-Param-Region' => 'us-west1',
            'Mcp-Param-Empty' => '',
        ];

        $headers = $this->perMessageHeaders($message);
        $this->assertSame('us-west1', $headers['Mcp-Param-Region']);
        $this->assertSame('', $headers['Mcp-Param-Empty']);
        $this->assertSame('tools/call', $headers[McpHeaders::METHOD], 'Hints never displace the standard headers');
    }

    /**
     * The cURL header conversion uses libcurl's "Name;" form for an empty
     * value — "Name:" would REMOVE the header, but SEP-2243 requires an
     * empty-string argument to mirror as a present, empty header.
     */
    public function testEmptyHeaderValueUsesSemicolonCurlForm(): void
    {
        $method = new \ReflectionMethod($this->transport, 'prepareRequestHeaders');
        $method->setAccessible(true);
        /** @var array<int, string> $curlHeaders */
        $curlHeaders = $method->invoke($this->transport, [
            'Mcp-Param-Empty' => '',
            'Mcp-Param-Full' => 'value',
        ]);

        $this->assertContains('Mcp-Param-Empty;', $curlHeaders);
        $this->assertContains('Mcp-Param-Full: value', $curlHeaders);
    }
}
