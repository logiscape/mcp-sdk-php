<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Filename: tests/Server/AppsExtensionTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\McpServer;
use Mcp\Server\NotificationOptions;
use Mcp\Types\CallToolResult;
use Mcp\Types\ExtensionIds;
use Mcp\Types\BlobResourceContents;
use Mcp\Types\ListResourcesResult;
use Mcp\Types\ListToolsResult;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\TextContent;
use Mcp\Types\TextResourceContents;
use PHPUnit\Framework\TestCase;

/**
 * Coverage of the MCP Apps extension (SEP-1865, ext-apps stable revision
 * 2026-01-26) as implemented by {@see McpServer::ui()}: extension declaration
 * per SEP-2133, ui:// template-resource registration with the correct MIME
 * type, tool→UI linkage through `_meta.ui` (current and deprecated flat keys),
 * resource-level `_meta.ui` metadata emission, and the graceful-degradation
 * (non-rendering-host) path. The extension adds no RPC method, so the server
 * surface is purely capability + `_meta` plumbing.
 */
final class AppsExtensionTest extends TestCase
{
    private const HTML = '<!doctype html><html><body>app</body></html>';

    private function appServer(): McpServer
    {
        $server = new McpServer('apps-test');
        $server
            ->tool('get_weather', 'Get the weather', fn (string $city): string => "Weather in {$city}: sunny")
            ->ui(
                tool: 'get_weather',
                uri: 'ui://weather/dashboard',
                name: 'Weather Dashboard',
                html: self::HTML,
            );
        return $server;
    }

    /**
     * @return array<string, callable>
     */
    private function handlers(McpServer $server): array
    {
        return $server->getServer()->getHandlers();
    }

    /**
     * Calling ui() declares the Apps extension through the SEP-2133
     * extensions map, with the value object carrying the UI MIME type — so it
     * is advertised in initialize and server/discover.
     */
    public function testUiDeclaresAppsExtensionWithMimeType(): void
    {
        $caps = $this->appServer()->getServer()->getCapabilities(new NotificationOptions(), []);

        $this->assertIsArray($caps->extensions);
        $this->assertArrayHasKey(ExtensionIds::UI, $caps->extensions);
        $this->assertSame(
            ['mimeTypes' => [McpServer::UI_MIME_TYPE]],
            $caps->extensions[ExtensionIds::UI]
        );
        $this->assertSame('text/html;profile=mcp-app', McpServer::UI_MIME_TYPE);
    }

    /**
     * A server with no UI templates declares no Apps extension.
     */
    public function testNoAppsExtensionWithoutUi(): void
    {
        $server = new McpServer('plain');
        $server->tool('noop', 'Does nothing', fn (): string => 'ok');
        $caps = $server->getServer()->getCapabilities(new NotificationOptions(), []);

        if ($caps->extensions !== null) {
            $this->assertArrayNotHasKey(ExtensionIds::UI, $caps->extensions);
        } else {
            $this->assertNull($caps->extensions);
        }
    }

    /**
     * The linked tool carries `_meta.ui.resourceUri`, and the deprecated flat
     * `_meta["ui/resourceUri"]` is dual-written for host back-compat (mirroring
     * the reference ext-apps server SDK during the pre-GA window).
     */
    public function testToolLinkedViaMetaWithDeprecatedDualWrite(): void
    {
        $tool = $this->handlers($this->appServer())['tools/list'](null)->tools[0];
        $meta = $tool->_meta;

        $this->assertIsArray($meta);
        $this->assertSame('ui://weather/dashboard', $meta['ui']['resourceUri']);
        $this->assertSame('ui://weather/dashboard', $meta['ui/resourceUri']);
        // visibility omitted unless explicitly requested (host default: both).
        $this->assertArrayNotHasKey('visibility', $meta['ui']);
    }

    /**
     * Visibility is emitted, validated, and re-indexed when provided.
     */
    public function testVisibilityEmittedWhenProvided(): void
    {
        $server = new McpServer('apps-test');
        $server
            ->tool('app_only', 'App-only tool', fn (): string => 'x')
            ->ui(tool: 'app_only', uri: 'ui://x', name: 'X', html: self::HTML, visibility: ['app']);

        $tool = $this->handlers($server)['tools/list'](null)->tools[0];
        $this->assertSame(['app'], $tool->_meta['ui']['visibility']);
    }

    /**
     * The ui:// template is registered as an ordinary resource: it appears in
     * resources/list and resources/read with the Apps MIME type, so a host can
     * prefetch, cache, and security-review it.
     */
    public function testTemplateResourceRegisteredAndReadable(): void
    {
        $handlers = $this->handlers($this->appServer());

        $list = $handlers['resources/list'](null);
        $this->assertInstanceOf(ListResourcesResult::class, $list);
        $this->assertCount(1, $list->resources);
        $this->assertSame('ui://weather/dashboard', $list->resources[0]->uri);
        $this->assertSame(McpServer::UI_MIME_TYPE, $list->resources[0]->mimeType);

        $params = new \stdClass();
        $params->uri = 'ui://weather/dashboard';
        $read = $handlers['resources/read']($params);
        $this->assertInstanceOf(ReadResourceResult::class, $read);
        $this->assertSame(self::HTML, $read->contents[0]->text);
        $this->assertSame(McpServer::UI_MIME_TYPE, $read->contents[0]->mimeType);
    }

    /**
     * The HTML source may be a callback, invoked lazily at read time.
     */
    public function testHtmlCallbackInvokedLazily(): void
    {
        $calls = 0;
        $server = new McpServer('apps-test');
        $server
            ->tool('dyn', 'Dynamic UI', fn (): string => 'x')
            ->ui(tool: 'dyn', uri: 'ui://dyn', name: 'Dyn', html: function () use (&$calls): string {
                $calls++;
                return '<html>' . $calls . '</html>';
            });

        $this->assertSame(0, $calls, 'HTML callback must not run at registration');

        $params = new \stdClass();
        $params->uri = 'ui://dyn';
        $read = $this->handlers($server)['resources/read']($params);
        $this->assertSame('<html>1</html>', $read->contents[0]->text);
        $this->assertSame(1, $calls);
    }

    /**
     * Resource-level `_meta.ui` (csp / permissions / domain / prefersBorder)
     * is emitted on the resources/read content — where the stable revision
     * reads it — with permission members rendered as empty objects, not
     * arrays. It is also mirrored on the listed resource (draft revision).
     */
    public function testResourceMetaEmittedOnReadContentAndListing(): void
    {
        $server = new McpServer('apps-test');
        $server
            ->tool('rich', 'Rich UI', fn (): string => 'x')
            ->ui(
                tool: 'rich',
                uri: 'ui://rich',
                name: 'Rich',
                html: self::HTML,
                csp: ['connectDomains' => ['https://api.example.com']],
                permissions: ['camera', 'geolocation'],
                domain: 'abc.example-host.com',
                prefersBorder: true,
            );

        $handlers = $this->handlers($server);

        $params = new \stdClass();
        $params->uri = 'ui://rich';
        $read = $handlers['resources/read']($params);
        $json = (string) json_encode($read);

        // Permission members are empty OBJECTS on the wire, never [].
        $this->assertStringContainsString('"camera":{}', $json);
        $this->assertStringContainsString('"geolocation":{}', $json);
        // The trait's storage property must never leak as a literal key.
        $this->assertStringNotContainsString('extraFields', $json);

        $meta = $read->contents[0]->_meta;
        $this->assertSame(['https://api.example.com'], $meta['ui']['csp']['connectDomains']);
        $this->assertSame('abc.example-host.com', $meta['ui']['domain']);
        $this->assertTrue($meta['ui']['prefersBorder']);

        // Listing mirrors the same metadata (draft dual-location).
        $listed = $handlers['resources/list'](null)->resources[0];
        $this->assertArrayHasKey('ui', $listed->_meta);
    }

    /**
     * No resource `_meta` is emitted when no host hints are supplied.
     */
    public function testNoResourceMetaWhenNoHints(): void
    {
        $read = (function () {
            $handlers = $this->handlers($this->appServer());
            $params = new \stdClass();
            $params->uri = 'ui://weather/dashboard';
            return $handlers['resources/read']($params);
        })();

        $this->assertNull($read->contents[0]->_meta);
        $this->assertStringNotContainsString('_meta', (string) json_encode($read));
    }

    /**
     * Graceful degradation: the linked tool keeps returning ordinary content,
     * so a host that cannot render the UI still gets a working tool. There is
     * no UI-specific server path — UI-originated calls are ordinary tools/call.
     */
    public function testGracefulDegradationToolStillReturnsContent(): void
    {
        $params = new \stdClass();
        $params->name = 'get_weather';
        $params->arguments = (object) ['city' => 'Paris'];

        $result = $this->handlers($this->appServer())['tools/call']($params);
        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('Weather in Paris: sunny', $result->content[0]->text);

        // The tool is still listed (the host, not the server, filters by
        // visibility), so non-UI hosts discover it normally.
        $tools = $this->handlers($this->appServer())['tools/list'](null)->tools;
        $this->assertSame('get_weather', $tools[0]->name);
    }

    /**
     * Multiple ui() calls share a single extension declaration, and pre-existing
     * tool `_meta` is preserved when the UI block is merged in.
     */
    public function testMultipleUiCallsAndPreexistingMetaPreserved(): void
    {
        $server = new McpServer('apps-test');
        $server->tool('a', 'A', fn (): string => 'a')->tool('b', 'B', fn (): string => 'b');

        // Pre-set unrelated _meta on tool a before linking.
        $toolA = $this->handlers($server)['tools/list'](null)->tools[0];
        $toolA->_meta = ['vendor.example/flag' => true];

        $server
            ->ui(tool: 'a', uri: 'ui://a', name: 'A UI', html: self::HTML)
            ->ui(tool: 'b', uri: 'ui://b', name: 'B UI', html: self::HTML);

        $caps = $server->getServer()->getCapabilities(new NotificationOptions(), []);
        $this->assertSame(['mimeTypes' => [McpServer::UI_MIME_TYPE]], $caps->extensions[ExtensionIds::UI]);

        $tools = $this->handlers($server)['tools/list'](null)->tools;
        $this->assertTrue($tools[0]->_meta['vendor.example/flag']);
        $this->assertSame('ui://a', $tools[0]->_meta['ui']['resourceUri']);
        $this->assertSame('ui://b', $tools[1]->_meta['ui']['resourceUri']);
        $this->assertCount(2, $this->handlers($server)['resources/list'](null)->resources);
    }

    /**
     * A host/client parsing a resources/read response MUST retain the
     * resource content's `_meta.ui` (CSP, permissions, domain, border hints) —
     * it is the security metadata needed to sandbox an MCP App. Both the text
     * and blob content types preserve it through fromArray().
     */
    public function testReadResourceContentMetaSurvivesParse(): void
    {
        $meta = ['ui' => ['prefersBorder' => true, 'permissions' => ['camera' => new \stdClass()]]];

        $parsed = ReadResourceResult::fromResponseData([
            'contents' => [
                [
                    'uri' => 'ui://x',
                    'mimeType' => McpServer::UI_MIME_TYPE,
                    'text' => '<html></html>',
                    '_meta' => $meta,
                ],
            ],
        ]);

        $contents = $parsed->contents[0];
        $this->assertInstanceOf(TextResourceContents::class, $contents);
        $roundTripped = $contents->getExtraField('_meta');
        $this->assertIsArray($roundTripped);
        $this->assertTrue($roundTripped['ui']['prefersBorder']);
        // Permission members survive as empty objects, not arrays, on re-serialize.
        $this->assertStringContainsString('"camera":{}', (string) json_encode($contents));

        // Same guarantee for binary (blob) content.
        $blob = BlobResourceContents::fromArray([
            'uri' => 'ui://y',
            'mimeType' => 'application/octet-stream',
            'blob' => base64_encode('data'),
            '_meta' => ['ui' => ['domain' => 'abc.host.example']],
        ]);
        $this->assertSame('abc.host.example', $blob->getExtraField('_meta')['ui']['domain']);
    }

    public function testRejectsNonUiUri(): void
    {
        $server = new McpServer('apps-test');
        $server->tool('t', 'T', fn (): string => 'x');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("must begin with 'ui://'");
        $server->ui(tool: 't', uri: 'https://evil/x', name: 'X', html: self::HTML);
    }

    public function testRejectsUnknownTool(): void
    {
        $server = new McpServer('apps-test');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown tool');
        $server->ui(tool: 'nope', uri: 'ui://x', name: 'X', html: self::HTML);
    }

    /**
     * @dataProvider invalidMetadataProvider
     * @param array<string, mixed> $extra Named args for ui() beyond the basics
     */
    public function testRejectsInvalidMetadata(array $extra, string $expectedMessageFragment): void
    {
        $server = new McpServer('apps-test');
        $server->tool('t', 'T', fn (): string => 'x');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessageFragment);
        $server->ui(...array_merge(
            ['tool' => 't', 'uri' => 'ui://x', 'name' => 'X', 'html' => self::HTML],
            $extra
        ));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidMetadataProvider(): array
    {
        return [
            'empty visibility' => [['visibility' => []], 'cannot be empty'],
            'unknown visibility' => [['visibility' => ['nobody']], 'Invalid UI visibility'],
            'unknown permission' => [['permissions' => ['filesystem']], 'Invalid UI permission'],
            'unknown csp key' => [['csp' => ['scriptDomains' => ['x']]], 'Invalid UI csp key'],
            'csp non-list' => [['csp' => ['connectDomains' => ['k' => 'v']]], 'must be a list'],
            'csp non-string' => [['csp' => ['connectDomains' => [123]]], 'only domain strings'],
        ];
    }
}
