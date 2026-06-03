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

namespace Mcp\Tests\Server;

use InvalidArgumentException;
use Mcp\Server\McpServer;
use Mcp\Server\McpServerException;
use Mcp\Types\ListResourceTemplatesResult;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\ResourceTemplate;
use Mcp\Types\TextResourceContents;
use PHPUnit\Framework\TestCase;

/**
 * Tests for McpServer::resourceTemplate() — first-class resource templates
 * (B3 + B4): resources/templates/list advertisement and templated
 * resources/read with by-name variable injection.
 */
final class McpServerResourceTemplateTest extends TestCase
{
    /**
     * Registering a template makes it appear in resources/templates/list with
     * the correct name, uriTemplate, and mimeType.
     */
    public function testRegisteredTemplateAppearsInList(): void
    {
        $server = new McpServer('test');
        $server->resourceTemplate(
            uriTemplate: 'test://template/{id}/data',
            name: 'Template Resource',
            callback: fn (string $id) => "data for {$id}",
            description: 'A templated resource',
            mimeType: 'application/json'
        );

        $handlers = $server->getServer()->getHandlers();
        $result = $handlers['resources/templates/list'](null);

        $this->assertInstanceOf(ListResourceTemplatesResult::class, $result);
        $this->assertCount(1, $result->resourceTemplates);

        $template = $result->resourceTemplates[0];
        $this->assertInstanceOf(ResourceTemplate::class, $template);
        $this->assertSame('Template Resource', $template->name);
        $this->assertSame('test://template/{id}/data', $template->uriTemplate);
        $this->assertSame('application/json', $template->mimeType);
        $this->assertSame('A templated resource', $template->description);
    }

    /**
     * A resources/read for a URI matching the template invokes the callback
     * with the extracted variable bound by name.
     */
    public function testTemplatedReadInjectsVariableByName(): void
    {
        $server = new McpServer('test');
        $server->resourceTemplate(
            uriTemplate: 'test://template/{id}/data',
            name: 'Template Resource',
            callback: fn (string $id) => "Template resource data for id={$id}"
        );

        $handlers = $server->getServer()->getHandlers();
        $result = $handlers['resources/read']((object) ['uri' => 'test://template/abc/data']);

        $this->assertInstanceOf(ReadResourceResult::class, $result);
        $this->assertCount(1, $result->contents);
        $this->assertInstanceOf(TextResourceContents::class, $result->contents[0]);
        $this->assertSame('Template resource data for id=abc', $result->contents[0]->text);
        // The contents carry the CONCRETE request URI, not the template.
        $this->assertSame('test://template/abc/data', $result->contents[0]->uri);
    }

    /**
     * An exact-match static resource resolves via the fast path and is NOT
     * shadowed by a template that could also match.
     */
    public function testExactMatchWinsOverTemplate(): void
    {
        $server = new McpServer('test');
        $server
            ->resourceTemplate(
                uriTemplate: 'test://template/{id}/data',
                name: 'Template Resource',
                callback: fn (string $id) => "TEMPLATE:{$id}"
            )
            ->resource(
                uri: 'test://template/special/data',
                name: 'Exact Resource',
                callback: fn () => 'EXACT'
            );

        $handlers = $server->getServer()->getHandlers();
        $result = $handlers['resources/read']((object) ['uri' => 'test://template/special/data']);

        $this->assertSame('EXACT', $result->contents[0]->text);
    }

    /**
     * A read matching no static resource and no template throws unknownResource.
     */
    public function testReadOfUnmatchedUriThrows(): void
    {
        $server = new McpServer('test');
        $server->resourceTemplate(
            uriTemplate: 'test://template/{id}/data',
            name: 'Template Resource',
            callback: fn (string $id) => $id
        );

        $handlers = $server->getServer()->getHandlers();

        $this->expectException(McpServerException::class);
        $this->expectExceptionMessage('Unknown resource: test://other/thing');
        $handlers['resources/read']((object) ['uri' => 'test://other/thing']);
    }

    /**
     * A template callback returning a ReadResourceResult is passed through
     * unchanged.
     */
    public function testTemplateCallbackReadResourceResultPassthrough(): void
    {
        $passthrough = new ReadResourceResult(contents: [
            new TextResourceContents(text: 'custom', uri: 'test://template/z/data', mimeType: 'text/x-custom'),
        ]);

        $server = new McpServer('test');
        $server->resourceTemplate(
            uriTemplate: 'test://template/{id}/data',
            name: 'Template Resource',
            callback: fn (string $id) => $passthrough
        );

        $handlers = $server->getServer()->getHandlers();
        $result = $handlers['resources/read']((object) ['uri' => 'test://template/z/data']);

        $this->assertSame($passthrough, $result);
        $this->assertSame('text/x-custom', $result->contents[0]->mimeType);
    }

    /**
     * A string return is wrapped as TextResourceContents with the concrete
     * request URI and the template's mimeType.
     */
    public function testTemplateStringReturnUsesTemplateMimeType(): void
    {
        $server = new McpServer('test');
        $server->resourceTemplate(
            uriTemplate: 'test://template/{id}/data',
            name: 'Template Resource',
            callback: fn (string $id) => "value:{$id}",
            mimeType: 'application/json'
        );

        $handlers = $server->getServer()->getHandlers();
        $result = $handlers['resources/read']((object) ['uri' => 'test://template/7/data']);

        $this->assertSame('application/json', $result->contents[0]->mimeType);
        $this->assertSame('test://template/7/data', $result->contents[0]->uri);
    }

    /**
     * resources/templates/list on a server with no templates returns an empty
     * resourceTemplates array (behavior-change regression guard).
     */
    public function testTemplatesListEmptyByDefault(): void
    {
        $server = new McpServer('test');

        $handlers = $server->getServer()->getHandlers();
        $result = $handlers['resources/templates/list'](null);

        $this->assertInstanceOf(ListResourceTemplatesResult::class, $result);
        $this->assertSame([], $result->resourceTemplates);
    }

    /**
     * resourceTemplate() with an unsupported-operator template throws at
     * registration and leaves no template advertised (rec #4 — nothing
     * unreadable is ever advertised).
     */
    public function testUnsupportedOperatorThrowsAtRegistration(): void
    {
        $server = new McpServer('test');

        try {
            $server->resourceTemplate(
                uriTemplate: 'test://{?q}',
                name: 'Bad Template',
                callback: fn (string $q) => $q
            );
            $this->fail('Expected InvalidArgumentException for unsupported operator');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $handlers = $server->getServer()->getHandlers();
        $result = $handlers['resources/templates/list'](null);
        $this->assertSame([], $result->resourceTemplates);
    }

    /**
     * A {+path} template reads a multi-segment URI, while a {path} template
     * does NOT match the multi-segment URI (segment-semantics contract).
     */
    public function testReservedOperatorReadsMultiSegment(): void
    {
        $server = new McpServer('test');
        $server->resourceTemplate(
            uriTemplate: 'file:///{+path}',
            name: 'File Resource',
            callback: fn (string $path) => "path={$path}"
        );

        $handlers = $server->getServer()->getHandlers();
        $result = $handlers['resources/read']((object) ['uri' => 'file:///a/b/c.txt']);

        $this->assertSame('path=a/b/c.txt', $result->contents[0]->text);
    }

    /**
     * A single-segment {path} template does NOT match a multi-segment URI.
     */
    public function testSimpleVariableDoesNotMatchMultiSegment(): void
    {
        $server = new McpServer('test');
        $server->resourceTemplate(
            uriTemplate: 'file:///{path}',
            name: 'File Resource',
            callback: fn (string $path) => "path={$path}"
        );

        $handlers = $server->getServer()->getHandlers();

        $this->expectException(McpServerException::class);
        $handlers['resources/read']((object) ['uri' => 'file:///a/b/c.txt']);
    }

    /**
     * Existing resource() behavior is unchanged after the shared-normalization
     * refactor: a string return still wraps to TextResourceContents with the
     * resource URI and mimeType (guards the WI-2 refactor).
     */
    public function testStaticResourceNormalizationUnchanged(): void
    {
        $server = new McpServer('test');
        $server->resource(
            uri: 'test://static',
            name: 'Static',
            callback: fn () => 'static content',
            mimeType: 'text/plain'
        );

        $handlers = $server->getServer()->getHandlers();
        $result = $handlers['resources/read']((object) ['uri' => 'test://static']);

        $this->assertInstanceOf(ReadResourceResult::class, $result);
        $this->assertInstanceOf(TextResourceContents::class, $result->contents[0]);
        $this->assertSame('static content', $result->contents[0]->text);
        $this->assertSame('test://static', $result->contents[0]->uri);
        $this->assertSame('text/plain', $result->contents[0]->mimeType);
    }
}
