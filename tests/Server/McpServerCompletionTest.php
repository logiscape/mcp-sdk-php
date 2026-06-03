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
use Mcp\Server\NotificationOptions;
use Mcp\Types\CompleteRequestParams;
use Mcp\Types\CompleteResult;
use Mcp\Types\CompletionArgument;
use Mcp\Types\CompletionContext;
use Mcp\Types\CompletionObject;
use Mcp\Types\PromptReference;
use Mcp\Types\ResourceReference;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the server-side completions API (B14 + B15): provider registration,
 * capability advertisement, context plumbing (rec #1), invalid-ref error
 * handling (rec #3), and the 100-value send-side cap (rec #2).
 */
final class McpServerCompletionTest extends TestCase
{
    /**
     * Build a real CompleteRequestParams the way the request parser would.
     */
    private function completeParams(
        PromptReference|ResourceReference $ref,
        string $argName,
        string $argValue,
        ?CompletionContext $context = null
    ): CompleteRequestParams {
        return new CompleteRequestParams(
            new CompletionArgument($argName, $argValue),
            $ref,
            context: $context
        );
    }

    /**
     * Registering a prompt completion provider advertises the `completions`
     * capability.
     */
    public function testCompletionProviderAdvertisesCapability(): void
    {
        $server = new McpServer('test');
        $server
            ->prompt('greet', 'Greeting', fn (string $name) => "Hi {$name}")
            ->completionForPrompt('greet', 'name', fn (string $value) => ['Alice', 'Bob']);

        $caps = $server->getServer()->getCapabilities(new NotificationOptions(), []);
        $this->assertNotNull($caps->completions);
    }

    /**
     * A server that registers no completion provider does NOT advertise the
     * capability (proves the lazy registration is honest).
     */
    public function testNoProviderDoesNotAdvertiseCapability(): void
    {
        $server = new McpServer('test');
        $server->prompt('greet', 'Greeting', fn (string $name) => "Hi {$name}");

        $caps = $server->getServer()->getCapabilities(new NotificationOptions(), []);
        $this->assertNull($caps->completions);
    }

    /**
     * A prompt-reference completion returns the provider's suggestions.
     */
    public function testPromptCompletionReturnsSuggestions(): void
    {
        $server = new McpServer('test');
        $server
            ->prompt('greet', 'Greeting', fn (string $name) => "Hi {$name}")
            ->completionForPrompt('greet', 'name', fn (string $value) => array_values(array_filter(
                ['Alice', 'Bob', 'Carol'],
                fn ($n) => str_starts_with($n, $value)
            )));

        $handlers = $server->getServer()->getHandlers();
        $params = $this->completeParams(new PromptReference('greet'), 'name', 'A');
        $result = $handlers['completion/complete']($params);

        $this->assertInstanceOf(CompleteResult::class, $result);
        $this->assertSame(['Alice'], $result->completion->values);
    }

    /**
     * A resource-template completion keyed on the registered uriTemplate
     * returns its suggestions.
     */
    public function testResourceTemplateCompletionReturnsSuggestions(): void
    {
        $server = new McpServer('test');
        $server
            ->resourceTemplate('test://template/{id}/data', 'Tmpl', fn (string $id) => $id)
            ->completionForResourceTemplate('test://template/{id}/data', 'id', fn (string $value) => ['item-1', 'item-2']);

        $handlers = $server->getServer()->getHandlers();
        $params = $this->completeParams(new ResourceReference('test://template/{id}/data'), 'id', '');
        $result = $handlers['completion/complete']($params);

        $this->assertSame(['item-1', 'item-2'], $result->completion->values);
    }

    /**
     * rec #1: a provider declaring (string $value, array $context) receives the
     * client's context.arguments and can vary suggestions on a prior argument.
     */
    public function testProviderReceivesContext(): void
    {
        $server = new McpServer('test');
        $server
            ->prompt('build', 'Build', fn (string $framework) => $framework)
            ->completionForPrompt('build', 'framework', function (string $value, array $context) {
                $byLanguage = [
                    'python' => ['django', 'flask'],
                    'php' => ['laravel', 'symfony'],
                ];
                $lang = $context['language'] ?? '';
                return $byLanguage[$lang] ?? [];
            });

        $handlers = $server->getServer()->getHandlers();

        $params = $this->completeParams(
            new PromptReference('build'),
            'framework',
            '',
            new CompletionContext(['language' => 'php'])
        );
        $result = $handlers['completion/complete']($params);

        $this->assertSame(['laravel', 'symfony'], $result->completion->values);
    }

    /**
     * rec #3: a ref/prompt naming an unregistered prompt yields a -32602 error.
     */
    public function testUnknownPromptRefThrows32602(): void
    {
        $server = new McpServer('test');
        $server
            ->prompt('greet', 'Greeting', fn (string $name) => "Hi {$name}")
            ->completionForPrompt('greet', 'name', fn (string $value) => ['Alice']);

        $handlers = $server->getServer()->getHandlers();
        $params = $this->completeParams(new PromptReference('nonexistent'), 'name', '');

        try {
            $handlers['completion/complete']($params);
            $this->fail('Expected McpServerException for unknown prompt ref');
        } catch (McpServerException $e) {
            $this->assertSame(-32602, $e->error->code);
        }
    }

    /**
     * rec #3: a ref/resource URI matching no registered template yields -32602.
     */
    public function testUnknownTemplateRefThrows32602(): void
    {
        $server = new McpServer('test');
        $server
            ->resourceTemplate('test://template/{id}/data', 'Tmpl', fn (string $id) => $id)
            ->completionForResourceTemplate('test://template/{id}/data', 'id', fn (string $value) => ['item-1']);

        $handlers = $server->getServer()->getHandlers();
        $params = $this->completeParams(new ResourceReference('test://unregistered/{x}'), 'x', '');

        try {
            $handlers['completion/complete']($params);
            $this->fail('Expected McpServerException for unknown template ref');
        } catch (McpServerException $e) {
            $this->assertSame(-32602, $e->error->code);
        }
    }

    /**
     * rec #3: a valid ref whose specific argument has no provider returns an
     * empty values array (not an error) — the "known argument, no suggestions"
     * case.
     */
    public function testValidRefNoProviderReturnsEmpty(): void
    {
        $server = new McpServer('test');
        $server
            ->prompt('greet', 'Greeting', fn (string $name) => "Hi {$name}")
            // Provider registered for a DIFFERENT argument so the handler exists.
            ->completionForPrompt('greet', 'other', fn (string $value) => ['x']);

        $handlers = $server->getServer()->getHandlers();
        $params = $this->completeParams(new PromptReference('greet'), 'name', '');
        $result = $handlers['completion/complete']($params);

        $this->assertInstanceOf(CompleteResult::class, $result);
        $this->assertSame([], $result->completion->values);
    }

    /**
     * A provider returning a CompletionObject is passed through unchanged.
     */
    public function testCompletionObjectPassthrough(): void
    {
        $object = new CompletionObject(values: ['only'], total: 1, hasMore: false);

        $server = new McpServer('test');
        $server
            ->prompt('greet', 'Greeting', fn (string $name) => "Hi {$name}")
            ->completionForPrompt('greet', 'name', fn (string $value) => $object);

        $handlers = $server->getServer()->getHandlers();
        $params = $this->completeParams(new PromptReference('greet'), 'name', '');
        $result = $handlers['completion/complete']($params);

        $this->assertSame($object, $result->completion);
    }

    /**
     * rec #2: a string[] provider returning >100 items is truncated to 100 with
     * hasMore=true and total=full count.
     */
    public function testStringArrayOver100Truncates(): void
    {
        $values = array_map(static fn ($i) => "v{$i}", range(1, 150));

        $server = new McpServer('test');
        $server
            ->prompt('greet', 'Greeting', fn (string $name) => "Hi {$name}")
            ->completionForPrompt('greet', 'name', fn (string $value) => $values);

        $handlers = $server->getServer()->getHandlers();
        $params = $this->completeParams(new PromptReference('greet'), 'name', '');
        $result = $handlers['completion/complete']($params);

        $this->assertCount(100, $result->completion->values);
        $this->assertTrue($result->completion->hasMore);
        $this->assertSame(150, $result->completion->total);
    }

    /**
     * rec #2: a hand-built CompletionObject of >100 values raises
     * InvalidArgumentException (validate-on-passthrough) — the author took
     * explicit control and built a spec-violating object.
     */
    public function testOversizedCompletionObjectThrows(): void
    {
        $oversized = new CompletionObject(values: array_map(static fn ($i) => "v{$i}", range(1, 101)));

        $server = new McpServer('test');
        $server
            ->prompt('greet', 'Greeting', fn (string $name) => "Hi {$name}")
            ->completionForPrompt('greet', 'name', fn (string $value) => $oversized);

        $handlers = $server->getServer()->getHandlers();
        $params = $this->completeParams(new PromptReference('greet'), 'name', '');

        $this->expectException(InvalidArgumentException::class);
        $handlers['completion/complete']($params);
    }
}
