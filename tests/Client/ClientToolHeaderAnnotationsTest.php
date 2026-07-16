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
 * Filename: tests/Client/ClientToolHeaderAnnotationsTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client;

use InvalidArgumentException;
use Mcp\Client\ClientSession;
use Mcp\Shared\MemoryStream;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\RequestId;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the client side of SEP-2243 x-mcp-header processing (WS3).
 *
 * On the modern HTTP path, tools/list results are validated: tools with
 * invalid x-mcp-header annotations are excluded (spec MUST for HTTP
 * clients) and cached as rejected — callTool() refuses them without
 * touching the wire — while one invalid tool never affects valid
 * siblings. Valid annotation maps are cached per tool and drive the
 * Mcp-Param-{name} header hints attached to tools/call messages
 * (plain/number/boolean/empty/base64 encodings; null arguments omitted).
 * Stdio sessions ignore annotations entirely and keep results unfiltered.
 */
final class ClientToolHeaderAnnotationsTest extends TestCase
{
    /**
     * @return array{ClientSession, MemoryStream, MemoryStream}
     */
    private function modernHttpSession(): array
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);
        $session->setHttpTransportMode(true);
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

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function tool(string $name, array $properties): array
    {
        return [
            'name' => $name,
            'description' => "Tool {$name}",
            'inputSchema' => ['type' => 'object', 'properties' => $properties],
        ];
    }

    /**
     * One tool definition per invalid-annotation class from the spec:
     * empty annotation, non-string annotation, non-primitive property
     * types (object / array / none), the explicitly-prohibited `number`
     * type, case-insensitive duplicates, and charset violations
     * (space / colon / non-ASCII / control char).
     *
     * @return array<int, array<string, mixed>>
     */
    private function invalidTools(): array
    {
        return [
            $this->tool('bad_empty', ['p' => ['type' => 'string', 'x-mcp-header' => '']]),
            $this->tool('bad_nonstring', ['p' => ['type' => 'string', 'x-mcp-header' => ['nested' => true]]]),
            $this->tool('bad_object_type', ['p' => ['type' => 'object', 'x-mcp-header' => 'Obj']]),
            $this->tool('bad_array_type', ['p' => ['type' => 'array', 'x-mcp-header' => 'Arr']]),
            $this->tool('bad_no_type', ['p' => ['x-mcp-header' => 'NoType']]),
            $this->tool('bad_duplicate', [
                'a' => ['type' => 'string', 'x-mcp-header' => 'X-Dup'],
                'b' => ['type' => 'string', 'x-mcp-header' => 'x-dup'],
            ]),
            $this->tool('bad_number_type', ['p' => ['type' => 'number', 'x-mcp-header' => 'Num']]),
            $this->tool('bad_space', ['p' => ['type' => 'string', 'x-mcp-header' => 'has space']]),
            $this->tool('bad_colon', ['p' => ['type' => 'string', 'x-mcp-header' => 'has:colon']]),
            $this->tool('bad_non_ascii', ['p' => ['type' => 'string', 'x-mcp-header' => "Région"]]),
            $this->tool('bad_control', ['p' => ['type' => 'string', 'x-mcp-header' => "Ctrl\x01"]]),
        ];
    }

    /** @return array<string, mixed> */
    private function validTool(): array
    {
        return $this->tool('valid_tool', [
            'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
            'priority' => ['type' => 'integer', 'x-mcp-header' => 'Priority'],
            'verbose' => ['type' => 'boolean', 'x-mcp-header' => 'Verbose'],
            'empty_val' => ['type' => 'string', 'x-mcp-header' => 'Empty-Val'],
            'padded' => ['type' => 'string', 'x-mcp-header' => 'Padded'],
            'method_val' => ['type' => 'string', 'x-mcp-header' => 'Method'],
            'query' => ['type' => 'string'], // unannotated: never mirrored
        ]);
    }

    /**
     * Every invalid-annotation class is excluded from the returned
     * ListToolsResult on the modern HTTP path; the valid sibling survives
     * untouched.
     */
    public function testInvalidToolsAreExcludedAndValidSiblingKept(): void
    {
        [$session, $readStream] = $this->modernHttpSession();

        $tools = $this->invalidTools();
        $tools[] = $this->validTool();
        $readStream->send($this->response(0, ['resultType' => 'complete', 'tools' => $tools]));

        $result = $session->listTools();

        $this->assertCount(1, $result->tools, 'All ten invalid tools excluded');
        $this->assertSame('valid_tool', $result->tools[0]->name);
    }

    /**
     * callTool() on a tool cached as rejected throws without sending
     * anything on the wire — the spec forbids calling such a tool.
     */
    public function testCallToolOnRejectedToolThrowsWithoutWireTraffic(): void
    {
        [$session, $readStream, $writeStream] = $this->modernHttpSession();

        $readStream->send($this->response(0, [
            'resultType' => 'complete',
            'tools' => [$this->invalidTools()[0], $this->validTool()],
        ]));
        $session->listTools();

        // Drain the tools/list message so only post-listTools traffic remains.
        while ($writeStream->receive() !== null) {
        }

        try {
            $session->callTool('bad_empty', []);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('bad_empty', $e->getMessage());
            $this->assertStringContainsString('x-mcp-header', $e->getMessage());
        }
        $this->assertNull($writeStream->receive(), 'Rejected tool must never be called on the wire');
    }

    /**
     * The cached annotation map drives Mcp-Param-{name} hints on the
     * tools/call message: numbers and booleans stringified, an empty
     * string mirrored as a present empty header, padded values
     * base64-wrapped (lowercase sentinel), null arguments omitted,
     * unannotated arguments never mirrored, and `method_val` mapping to
     * Mcp-Param-Method without colliding with Mcp-Method.
     */
    public function testCallToolAttachesEncodedParamHeaderHints(): void
    {
        [$session, $readStream, $writeStream] = $this->modernHttpSession();

        $readStream->send($this->response(0, ['resultType' => 'complete', 'tools' => [$this->validTool()]]));
        $session->listTools();
        while ($writeStream->receive() !== null) {
        }

        $readStream->send($this->response(1, [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'ok']],
        ]));
        $session->callTool('valid_tool', [
            'region' => 'us west 1',       // internal space: plain, no wrapper
            'priority' => 42,
            'verbose' => false,
            'empty_val' => '',
            'padded' => ' padded ',
            'method_val' => 'custom',
            'query' => 'SELECT 1',         // unannotated
            'absent_like' => null,         // null: omit (even if it were annotated)
        ]);

        $sent = $writeStream->receive();
        $this->assertInstanceOf(JsonRpcMessage::class, $sent);
        $hints = $sent->httpHeaderHints;
        $this->assertIsArray($hints);

        $this->assertSame('us west 1', $hints['Mcp-Param-Region']);
        $this->assertSame('42', $hints['Mcp-Param-Priority']);
        $this->assertSame('false', $hints['Mcp-Param-Verbose']);
        $this->assertSame('', $hints['Mcp-Param-Empty-Val'], 'Empty string mirrors as a present empty header');
        $this->assertSame('=?base64?' . base64_encode(' padded ') . '?=', $hints['Mcp-Param-Padded']);
        $this->assertSame('custom', $hints['Mcp-Param-Method'], 'No collision with Mcp-Method');
        foreach (array_keys($hints) as $header) {
            $this->assertStringNotContainsString('Query', $header, 'Unannotated arguments are never mirrored');
        }
    }

    /**
     * SEP-2243 integer bounds are enforced client-side before any wire
     * traffic — including for large values that arrive as integral
     * floats (how PHP decodes big JSON integers).
     */
    public function testUnsafeIntegerArgumentsThrowBeforeWireTraffic(): void
    {
        [$session, $readStream, $writeStream] = $this->modernHttpSession();

        $readStream->send($this->response(0, ['resultType' => 'complete', 'tools' => [$this->validTool()]]));
        $session->listTools();
        while ($writeStream->receive() !== null) {
        }

        foreach ([\Mcp\Shared\McpHeaders::MAX_SAFE_INTEGER + 1, 9.1e15] as $unsafe) {
            try {
                $session->callTool('valid_tool', ['priority' => $unsafe]);
                $this->fail('Expected InvalidArgumentException for unsafe integer ' . var_export($unsafe, true));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('JavaScript-safe integer range', $e->getMessage());
            }
            $this->assertNull($writeStream->receive(), 'Nothing may reach the wire for an unsafe value');
        }
    }

    /**
     * Null-valued annotated arguments are omitted entirely: no
     * Mcp-Param-{name} header for them.
     */
    public function testNullAnnotatedArgumentOmitsItsHeader(): void
    {
        [$session, $readStream, $writeStream] = $this->modernHttpSession();

        $readStream->send($this->response(0, ['resultType' => 'complete', 'tools' => [$this->validTool()]]));
        $session->listTools();
        while ($writeStream->receive() !== null) {
        }

        $readStream->send($this->response(1, [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'ok']],
        ]));
        $session->callTool('valid_tool', ['region' => 'us-east1', 'verbose' => null]);

        $sent = $writeStream->receive();
        $hints = $sent->httpHeaderHints;
        $this->assertIsArray($hints);
        $this->assertSame('us-east1', $hints['Mcp-Param-Region']);
        $this->assertArrayNotHasKey('Mcp-Param-Verbose', $hints, 'Null argument: omit the header');
    }

    /**
     * The caches refresh on every listTools(): a tool rejected by an
     * earlier listing becomes callable again once a later listing
     * advertises it with valid annotations.
     */
    public function testCachesRefreshOnEveryListTools(): void
    {
        [$session, $readStream, $writeStream] = $this->modernHttpSession();

        $readStream->send($this->response(0, [
            'resultType' => 'complete',
            'tools' => [$this->tool('flaky', ['p' => ['type' => 'string', 'x-mcp-header' => '']])],
        ]));
        $this->assertCount(0, $session->listTools()->tools);

        $readStream->send($this->response(1, [
            'resultType' => 'complete',
            'tools' => [$this->tool('flaky', ['p' => ['type' => 'string', 'x-mcp-header' => 'P']])],
        ]));
        $this->assertCount(1, $session->listTools()->tools);
        while ($writeStream->receive() !== null) {
        }

        $readStream->send($this->response(2, [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'ok']],
        ]));
        $session->callTool('flaky', ['p' => 'v']);

        $sent = $writeStream->receive();
        $this->assertSame(['Mcp-Param-P' => 'v'], $sent->httpHeaderHints);
    }

    /**
     * Spec PR #2972 (SEP-2243, post-RC): clients MUST construct
     * Mcp-Param-* headers from the most recently obtained inputSchema
     * regardless of the listing's TTL — cache staleness is never a reason
     * to omit the headers. A tools/list result stamped ttlMs: 0
     * (immediately stale under SEP-2549) must still drive header emission
     * on a later tools/call.
     */
    public function testParamHeadersUseLatestSchemaRegardlessOfTtl(): void
    {
        [$session, $readStream, $writeStream] = $this->modernHttpSession();

        $readStream->send($this->response(0, [
            'resultType' => 'complete',
            'ttlMs' => 0,
            'cacheScope' => 'private',
            'tools' => [$this->validTool()],
        ]));
        $session->listTools();
        while ($writeStream->receive() !== null) {
        }

        $readStream->send($this->response(1, [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'ok']],
        ]));
        $session->callTool('valid_tool', ['region' => 'us-west1']);

        $sent = $writeStream->receive();
        $this->assertInstanceOf(JsonRpcMessage::class, $sent);
        $this->assertSame(
            ['Mcp-Param-Region' => 'us-west1'],
            $sent->httpHeaderHints,
            'An expired ttlMs must not suppress Mcp-Param-* emission (spec PR #2972)'
        );
    }

    /**
     * Spec PR #2972 (SEP-2243, post-RC): a client that has never obtained
     * the tool's inputSchema SHOULD send the request without Mcp-Param-*
     * headers — the call still goes out on the wire, just unmirrored.
     */
    public function testNeverListedToolSendsWithoutParamHeaders(): void
    {
        [$session, $readStream, $writeStream] = $this->modernHttpSession();

        $readStream->send($this->response(0, [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'ok']],
        ]));
        $session->callTool('valid_tool', ['region' => 'us-west1']);

        $sent = $writeStream->receive();
        $this->assertInstanceOf(JsonRpcMessage::class, $sent);
        $this->assertNull(
            $sent->httpHeaderHints,
            'No schema ever retrieved: send without Mcp-Param-* headers (spec PR #2972)'
        );
    }

    /**
     * Stdio sessions (no HTTP transport mode) MUST ignore annotations:
     * tools/list results stay unfiltered, nothing is cached or rejected,
     * and no header hints are produced.
     */
    public function testStdioIgnoresAnnotationsEntirely(): void
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0);
        $session->negotiate('modern'); // modern, but NOT HTTP

        $readStream->send($this->response(0, [
            'resultType' => 'complete',
            'tools' => [$this->invalidTools()[0], $this->validTool()],
        ]));
        $result = $session->listTools();
        $this->assertCount(2, $result->tools, 'Stdio results are never filtered');
        while ($writeStream->receive() !== null) {
        }

        $readStream->send($this->response(1, [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'ok']],
        ]));
        $session->callTool('valid_tool', ['region' => 'us-east1']);

        $sent = $writeStream->receive();
        $this->assertNull($sent->httpHeaderHints, 'Stdio never mirrors arguments into headers');

        // Even the "invalid" tool is callable on stdio.
        $readStream->send($this->response(2, [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'ok']],
        ]));
        $session->callTool('bad_empty', []);
        $this->assertNotNull($writeStream->receive());
    }

    /**
     * A continuation page (listTools() with a cursor) MERGES into the
     * annotation caches instead of resetting them: rejections and
     * annotation maps cached from page 1 survive fetching page 2, and
     * page 2's own annotations are added alongside them. Otherwise a
     * paginated listing would silently drop the SEP-2243 rejection guard
     * and Mcp-Param-* hints for every tool on an earlier page.
     */
    public function testPaginatedListToolsMergesAnnotationCaches(): void
    {
        [$session, $readStream, $writeStream] = $this->modernHttpSession();

        // Page 1 (fresh listing): one rejected tool, one valid tool.
        $readStream->send($this->response(0, [
            'resultType' => 'complete',
            'tools' => [$this->invalidTools()[0], $this->validTool()],
            'nextCursor' => 'page-2',
        ]));
        $page1 = $session->listTools();
        $this->assertCount(1, $page1->tools);
        $this->assertSame('page-2', $page1->nextCursor);

        // Page 2 (continuation): a new valid tool.
        $readStream->send($this->response(1, [
            'resultType' => 'complete',
            'tools' => [$this->tool('page_two', ['q' => ['type' => 'string', 'x-mcp-header' => 'Q']])],
        ]));
        $page2 = $session->listTools('page-2');
        $this->assertCount(1, $page2->tools);
        while ($writeStream->receive() !== null) {
        }

        // Page-1 rejection survived page 2: still refused off the wire.
        try {
            $session->callTool('bad_empty', []);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('bad_empty', $e->getMessage());
        }
        $this->assertNull($writeStream->receive(), 'Rejected page-1 tool must stay uncallable after page 2');

        // Page-1 annotations survived page 2: hints still emitted.
        $readStream->send($this->response(2, [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'ok']],
        ]));
        $session->callTool('valid_tool', ['region' => 'us-east1']);
        $sent = $writeStream->receive();
        $this->assertSame(['Mcp-Param-Region' => 'us-east1'], $sent->httpHeaderHints);

        // Page-2 annotations were merged in alongside.
        $readStream->send($this->response(3, [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'ok']],
        ]));
        $session->callTool('page_two', ['q' => 'v']);
        $sent = $writeStream->receive();
        $this->assertSame(['Mcp-Param-Q' => 'v'], $sent->httpHeaderHints);
    }

    /**
     * A later FRESH listing (no cursor) still resets both caches exactly
     * as before pagination support: tools cached from a previous
     * paginated listing lose their annotations, and previously rejected
     * tools become callable again.
     */
    public function testFreshListingAfterPaginationResetsCaches(): void
    {
        [$session, $readStream, $writeStream] = $this->modernHttpSession();

        // Paginated listing: rejected tool + valid tool on page 1, another on page 2.
        $readStream->send($this->response(0, [
            'resultType' => 'complete',
            'tools' => [$this->invalidTools()[0], $this->validTool()],
            'nextCursor' => 'page-2',
        ]));
        $session->listTools();
        $readStream->send($this->response(1, [
            'resultType' => 'complete',
            'tools' => [$this->tool('page_two', ['q' => ['type' => 'string', 'x-mcp-header' => 'Q']])],
        ]));
        $session->listTools('page-2');

        // Fresh listing advertising none of them: caches reset.
        $readStream->send($this->response(2, ['resultType' => 'complete', 'tools' => []]));
        $session->listTools();
        while ($writeStream->receive() !== null) {
        }

        // Annotations gone: call goes out unmirrored.
        $readStream->send($this->response(3, [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'ok']],
        ]));
        $session->callTool('valid_tool', ['region' => 'us-east1']);
        $sent = $writeStream->receive();
        $this->assertNull($sent->httpHeaderHints, 'Fresh listing must clear annotations from prior pages');

        // Rejection gone: the previously rejected tool reaches the wire again.
        $readStream->send($this->response(4, [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'ok']],
        ]));
        $session->callTool('bad_empty', []);
        $this->assertNotNull($writeStream->receive(), 'Fresh listing must clear prior rejections');
    }
}
