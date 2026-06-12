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
 * Filename: tests/Types/StatelessTypesTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Types;

use Mcp\Types\CacheableResult;
use Mcp\Types\CallToolResult;
use Mcp\Types\DiscoverRequest;
use Mcp\Types\DiscoverResult;
use Mcp\Types\Implementation;
use Mcp\Types\ListToolsResult;
use Mcp\Types\Meta;
use Mcp\Types\MetaKeys;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\RequestParams;
use Mcp\Types\Result;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\TextContent;
use Mcp\Types\TextResourceContents;
use Mcp\Types\Tool;
use Mcp\Types\TraceContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the WS1 stateless-foundation types of the 2026-07-28 revision:
 * the SEP-2575 _meta envelope keys and server/discover types, the SEP-2549
 * caching-hint fields, the SEP-2106 schema/structuredContent relaxations,
 * and the SEP-414 trace-context pass-through.
 */
final class StatelessTypesTest extends TestCase
{
    // -----------------------------------------------------------------------
    // MetaKeys (SEP-2575 / SEP-414)
    // -----------------------------------------------------------------------

    /**
     * The reserved _meta key strings are wire-format constants; any drift
     * breaks interoperability, so they are pinned verbatim.
     */
    public function testMetaKeyConstants(): void {
        $this->assertSame('io.modelcontextprotocol/protocolVersion', MetaKeys::PROTOCOL_VERSION);
        $this->assertSame('io.modelcontextprotocol/clientInfo', MetaKeys::CLIENT_INFO);
        $this->assertSame('io.modelcontextprotocol/clientCapabilities', MetaKeys::CLIENT_CAPABILITIES);
        $this->assertSame('io.modelcontextprotocol/logLevel', MetaKeys::LOG_LEVEL);
        $this->assertSame('io.modelcontextprotocol/subscriptionId', MetaKeys::SUBSCRIPTION_ID);
        // SEP-414: deliberately UNPREFIXED (documented exception)
        $this->assertSame('traceparent', MetaKeys::TRACEPARENT);
        $this->assertSame('tracestate', MetaKeys::TRACESTATE);
        $this->assertSame('baggage', MetaKeys::BAGGAGE);
    }

    // -----------------------------------------------------------------------
    // DiscoverRequest (SEP-2575)
    // -----------------------------------------------------------------------

    /**
     * A DiscoverRequest serializes to method "server/discover" with the
     * _meta envelope under params._meta, exactly as the draft schema's
     * official example shows.
     */
    public function testDiscoverRequestSerializesEnvelope(): void {
        $meta = new Meta();
        $meta->{MetaKeys::PROTOCOL_VERSION} = '2026-07-28';
        $meta->{MetaKeys::CLIENT_INFO} = ['name' => 'ExampleClient', 'version' => '1.0.0'];
        $meta->{MetaKeys::CLIENT_CAPABILITIES} = new \stdClass();

        $request = new DiscoverRequest(new RequestParams($meta));
        $wire = json_decode(json_encode($request), true);

        $this->assertSame('server/discover', $wire['method']);
        $this->assertSame('2026-07-28', $wire['params']['_meta'][MetaKeys::PROTOCOL_VERSION]);
        $this->assertSame('ExampleClient', $wire['params']['_meta'][MetaKeys::CLIENT_INFO]['name']);
        $this->assertArrayHasKey(MetaKeys::CLIENT_CAPABILITIES, $wire['params']['_meta']);
    }

    // -----------------------------------------------------------------------
    // DiscoverResult (SEP-2575 + SEP-2549)
    // -----------------------------------------------------------------------

    /**
     * DiscoverResult round-trips through fromResponseData()/jsonSerialize()
     * with all schema-required fields (supportedVersions, capabilities,
     * serverInfo, resultType, ttlMs, cacheScope) plus optional instructions,
     * and preserves unknown fields via ExtraFieldsTrait.
     */
    public function testDiscoverResultRoundTrip(): void {
        $data = [
            'resultType' => 'complete',
            'supportedVersions' => ['2026-07-28', '2025-11-25'],
            'capabilities' => ['tools' => ['listChanged' => true], 'resources' => []],
            'serverInfo' => ['name' => 'ExampleServer', 'version' => '1.0.0'],
            'instructions' => 'Use the tools.',
            'ttlMs' => 3600000,
            'cacheScope' => 'public',
            'futureField' => ['x' => 1],
        ];

        $result = DiscoverResult::fromResponseData($data);

        $this->assertSame(['2026-07-28', '2025-11-25'], $result->supportedVersions);
        $this->assertSame('ExampleServer', $result->serverInfo->name);
        $this->assertSame('Use the tools.', $result->instructions);
        $this->assertSame('complete', $result->resultType);
        $this->assertSame(3600000, $result->getTtlMs());
        $this->assertSame('public', $result->getCacheScope());
        $this->assertSame(['x' => 1], $result->futureField);

        $wire = json_decode(json_encode($result), true);
        $this->assertSame(['2026-07-28', '2025-11-25'], $wire['supportedVersions']);
        $this->assertSame('complete', $wire['resultType']);
        $this->assertSame(3600000, $wire['ttlMs']);
        $this->assertSame('public', $wire['cacheScope']);
        $this->assertSame('ExampleServer', $wire['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $wire['capabilities']);
        $this->assertSame(['x' => 1], $wire['futureField']);
    }

    public function testDiscoverResultRejectsEmptySupportedVersions(): void {
        $this->expectException(\InvalidArgumentException::class);
        (new DiscoverResult(
            supportedVersions: [],
            capabilities: new ServerCapabilities(),
            serverInfo: new Implementation(name: 's', version: '1'),
        ))->validate();
    }

    // -----------------------------------------------------------------------
    // Cacheable results (SEP-2549)
    // -----------------------------------------------------------------------

    /**
     * The caching hints are nullable and OMITTED from serialization when
     * unset, so legacy wire output is byte-identical to pre-WS1 behavior.
     */
    public function testCacheHintsOmittedWhenUnset(): void {
        $result = new ListToolsResult([]);
        $wire = json_decode(json_encode($result), true);
        $this->assertArrayNotHasKey('ttlMs', $wire);
        $this->assertArrayNotHasKey('cacheScope', $wire);
        $this->assertArrayNotHasKey('resultType', $wire);
    }

    /**
     * When set, the hints serialize on every cacheable result type and
     * round-trip back through fromResponseData().
     */
    public function testCacheHintsSerializeAndRoundTrip(): void {
        $result = new ListToolsResult([]);
        $result->setCacheHints(5000, CacheableResult::CACHE_SCOPE_PUBLIC);
        $result->resultType = Result::RESULT_TYPE_COMPLETE;

        $wire = json_decode(json_encode($result), true);
        $this->assertSame(5000, $wire['ttlMs']);
        $this->assertSame('public', $wire['cacheScope']);
        $this->assertSame('complete', $wire['resultType']);

        $restored = ListToolsResult::fromResponseData($wire);
        $this->assertSame(5000, $restored->getTtlMs());
        $this->assertSame('public', $restored->getCacheScope());
        $this->assertSame('complete', $restored->resultType);
    }

    public function testCacheHintsOnReadResourceResult(): void {
        $result = new ReadResourceResult([
            new TextResourceContents(text: 'hello', uri: 'test://a', mimeType: 'text/plain'),
        ]);
        $result->setCacheHints(0, CacheableResult::CACHE_SCOPE_PRIVATE);

        $wire = json_decode(json_encode($result), true);
        $this->assertSame(0, $wire['ttlMs']);
        $this->assertSame('private', $wire['cacheScope']);

        $restored = ReadResourceResult::fromResponseData($wire);
        $this->assertSame(0, $restored->getTtlMs());
        $this->assertSame('private', $restored->getCacheScope());
    }

    public function testNegativeTtlRejected(): void {
        $result = new ListToolsResult([]);
        $this->expectException(\InvalidArgumentException::class);
        $result->setCacheHints(-1, CacheableResult::CACHE_SCOPE_PUBLIC);
    }

    public function testInvalidCacheScopeRejected(): void {
        $result = new ListToolsResult([]);
        $this->expectException(\InvalidArgumentException::class);
        $result->setCacheHints(0, 'shared');
    }

    public function testClearCacheHints(): void {
        $result = new ListToolsResult([]);
        $result->setCacheHints(100, CacheableResult::CACHE_SCOPE_PUBLIC);
        $result->clearCacheHints();
        $this->assertNull($result->getTtlMs());
        $this->assertNull($result->getCacheScope());
    }

    // -----------------------------------------------------------------------
    // SEP-2106: JSON Schema 2020-12 in tool schemas
    // -----------------------------------------------------------------------

    /**
     * A tool inputSchema using JSON Schema 2020-12 keywords ($schema, $defs,
     * $ref, composition, conditionals) alongside the required root
     * type:"object" round-trips through Tool::fromArray()/jsonSerialize()
     * with every keyword preserved and nothing dereferenced.
     */
    public function testToolInputSchema202012RoundTrip(): void {
        $toolData = [
            'name' => 'lookup_user',
            'description' => 'Look up a user profile by id',
            'inputSchema' => [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                '$defs' => [
                    'userId' => ['type' => 'string', 'pattern' => '^[a-z0-9-]+$'],
                ],
                'properties' => [
                    'id' => ['$ref' => '#/$defs/userId'],
                    'profile' => ['$ref' => 'https://example.com/canary/profile-schema.json'],
                    'mode' => ['oneOf' => [['const' => 'fast'], ['const' => 'slow']]],
                ],
                'required' => ['id'],
                'if' => ['properties' => ['mode' => ['const' => 'fast']]],
                'then' => ['properties' => ['timeoutMs' => ['type' => 'integer']]],
            ],
            'outputSchema' => [
                // SEP-2106: outputSchema has no root restriction
                'type' => 'array',
                'items' => ['$ref' => '#/$defs/row'],
                '$defs' => ['row' => ['type' => 'object']],
            ],
        ];

        $tool = Tool::fromArray($toolData);
        $wire = json_decode(json_encode($tool), true);

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $wire['inputSchema']['$schema']);
        $this->assertSame('object', $wire['inputSchema']['type']);
        $this->assertSame(['type' => 'string', 'pattern' => '^[a-z0-9-]+$'], $wire['inputSchema']['$defs']['userId']);
        $this->assertSame(['$ref' => '#/$defs/userId'], $wire['inputSchema']['properties']['id']);
        // Network $ref preserved verbatim — never dereferenced (SEP-2106 security)
        $this->assertSame(
            ['$ref' => 'https://example.com/canary/profile-schema.json'],
            $wire['inputSchema']['properties']['profile']
        );
        $this->assertArrayHasKey('oneOf', $wire['inputSchema']['properties']['mode']);
        $this->assertSame(['id'], $wire['inputSchema']['required']);
        $this->assertArrayHasKey('if', $wire['inputSchema']);
        $this->assertArrayHasKey('then', $wire['inputSchema']);
        $this->assertSame('array', $wire['outputSchema']['type']);
    }

    /**
     * The root type:"object" constraint on inputSchema is still enforced —
     * SEP-2106 relaxes everything except the root type.
     */
    public function testToolInputSchemaStillRequiresRootObjectType(): void {
        $this->expectException(\InvalidArgumentException::class);
        Tool::fromArray([
            'name' => 'bad',
            'inputSchema' => ['$ref' => '#/$defs/x'],
        ]);
    }

    /**
     * structuredContent may be any JSON value under 2026-07-28: scalars
     * round-trip through CallToolResult intact.
     */
    public function testCallToolResultScalarStructuredContent(): void {
        $result = new CallToolResult(
            content: [new TextContent(text: '42')],
            structuredContent: 42
        );
        $wire = json_decode(json_encode($result), true);
        $this->assertSame(42, $wire['structuredContent']);

        $restored = CallToolResult::fromResponseData($wire);
        $this->assertSame(42, $restored->structuredContent);

        // Booleans (falsy values) survive too
        $boolResult = new CallToolResult(
            content: [new TextContent(text: 'false')],
            structuredContent: false
        );
        $boolWire = json_decode(json_encode($boolResult), true);
        $this->assertFalse($boolWire['structuredContent']);
    }

    /**
     * Explicit JSON null structuredContent (valid under SEP-2106 when the
     * outputSchema accepts null) is distinguishable from an absent field and
     * round-trips through serialization and fromResponseData().
     */
    public function testCallToolResultExplicitNullStructuredContent(): void
    {
        // Unset: field omitted from the wire
        $unset = new CallToolResult(content: [new TextContent(text: 'x')]);
        $this->assertFalse($unset->hasExplicitNullStructuredContent());
        $this->assertArrayNotHasKey('structuredContent', json_decode(json_encode($unset), true));

        // Explicit null: field present with value null
        $explicit = new CallToolResult(content: [new TextContent(text: 'null')]);
        $explicit->setStructuredContentNull();
        $wire = json_decode(json_encode($explicit), true);
        $this->assertArrayHasKey('structuredContent', $wire);
        $this->assertNull($wire['structuredContent']);

        // Round-trip: explicitness survives fromResponseData()
        $restored = CallToolResult::fromResponseData($wire);
        $this->assertTrue($restored->hasExplicitNullStructuredContent());
        $rewire = json_decode(json_encode($restored), true);
        $this->assertArrayHasKey('structuredContent', $rewire);
        $this->assertNull($rewire['structuredContent']);
    }

    // -----------------------------------------------------------------------
    // SEP-414: trace context pass-through
    // -----------------------------------------------------------------------

    /**
     * The bare trace-context keys survive a Meta round-trip unprefixed and
     * unmodified — the SDK must never strip or rename them.
     */
    public function testTraceContextKeysRoundTripThroughMeta(): void {
        $meta = new Meta();
        $meta->traceparent = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';
        $meta->tracestate = 'congo=t61rcWkgMzE';
        $meta->baggage = 'userId=alice';
        $meta->{'com.example/custom'} = 'kept';

        $wire = json_decode(json_encode($meta), true);
        $this->assertSame('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01', $wire['traceparent']);
        $this->assertSame('congo=t61rcWkgMzE', $wire['tracestate']);
        $this->assertSame('userId=alice', $wire['baggage']);
        $this->assertSame('kept', $wire['com.example/custom']);
    }

    public function testTraceContextFromMeta(): void {
        $meta = new Meta();
        $meta->traceparent = '00-aaaa-bbbb-01';
        $meta->tracestate = 'k=v';

        $ctx = TraceContext::fromMeta($meta);
        $this->assertNotNull($ctx);
        $this->assertSame('00-aaaa-bbbb-01', $ctx->traceparent);
        $this->assertSame('k=v', $ctx->tracestate);
        $this->assertNull($ctx->baggage);
    }

    public function testTraceContextAbsentReturnsNull(): void {
        $this->assertNull(TraceContext::fromMeta(null));
        $this->assertNull(TraceContext::fromMeta(new Meta()));
        $this->assertNull(TraceContext::fromArray(['other' => 'field']));
        // Non-string values are ignored rather than coerced
        $this->assertNull(TraceContext::fromArray(['traceparent' => 123]));
    }

    public function testTraceContextApplyToMeta(): void {
        $ctx = new TraceContext(traceparent: '00-cccc-dddd-00', baggage: 'a=b');
        $meta = new Meta();
        $meta->tracestate = 'existing=1';

        $ctx->applyToMeta($meta);

        // Null fields never clear existing values
        $this->assertSame('existing=1', $meta->tracestate);
        $this->assertSame('00-cccc-dddd-00', $meta->traceparent);
        $this->assertSame('a=b', $meta->baggage);
    }

    /**
     * Trace-context keys in a request's _meta survive the full ClientRequest
     * factory path for methods whose params previously dropped _meta
     * (tools/list, resources/read, ...), so server handlers can read them.
     */
    public function testTraceContextSurvivesRequestFactory(): void {
        $request = \Mcp\Types\ClientRequest::fromMethodAndParams('tools/list', [
            '_meta' => ['traceparent' => '00-eeee-ffff-01'],
        ]);
        $params = $request->getRequest()->params;
        $this->assertNotNull($params);
        $this->assertNotNull($params->_meta);
        $this->assertSame('00-eeee-ffff-01', $params->_meta->traceparent);

        $request = \Mcp\Types\ClientRequest::fromMethodAndParams('resources/read', [
            'uri' => 'test://x',
            '_meta' => ['traceparent' => '00-1111-2222-01'],
        ]);
        $params = $request->getRequest()->params;
        $this->assertSame('00-1111-2222-01', $params->_meta->traceparent);

        $request = \Mcp\Types\ClientRequest::fromMethodAndParams('completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'p'],
            'argument' => ['name' => 'a', 'value' => 'v'],
            '_meta' => ['traceparent' => '00-3333-4444-01'],
        ]);
        $params = $request->getRequest()->params;
        $this->assertNotNull($params->_meta, 'completion/complete must preserve _meta');
        $this->assertSame('00-3333-4444-01', $params->_meta->traceparent);
    }
}
