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

namespace Mcp\Tests\Types;

use Mcp\Types\ClientRequest;
use Mcp\Types\CompleteRequestParams;
use Mcp\Types\CompletionArgument;
use Mcp\Types\CompletionContext;
use Mcp\Types\PromptReference;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CompletionContext plumbing (rec #1): the new type plus the
 * CompleteRequestParams / ClientRequest parsing that carries
 * `context.arguments` from a completion request to the server-side provider.
 */
final class CompletionContextTest extends TestCase
{
    /**
     * fromArray() reads the arguments map and jsonSerialize() round-trips it.
     */
    public function testFromArrayRoundTrips(): void
    {
        $context = CompletionContext::fromArray(['arguments' => ['language' => 'python']]);

        $this->assertSame(['language' => 'python'], $context->arguments);
        $this->assertSame(
            '{"arguments":{"language":"python"}}',
            json_encode($context)
        );
    }

    /**
     * Non-string argument values are coerced to strings on parse.
     */
    public function testArgumentValuesCoercedToString(): void
    {
        $context = CompletionContext::fromArray(['arguments' => ['count' => 3]]);

        $this->assertSame(['count' => '3'], $context->arguments);
    }

    /**
     * An empty context omits the `arguments` key and serializes as an object.
     */
    public function testEmptyContextSerializesAsEmptyObject(): void
    {
        $context = new CompletionContext();

        $this->assertSame('{}', json_encode($context));
    }

    /**
     * ClientRequest parses `context.arguments` into a CompletionContext.
     */
    public function testClientRequestParsesContext(): void
    {
        $request = ClientRequest::fromMethodAndParams('completion/complete', [
            'argument' => ['name' => 'framework', 'value' => 'dj'],
            'ref' => ['type' => 'ref/prompt', 'name' => 'greet'],
            'context' => ['arguments' => ['language' => 'python']],
        ]);

        $params = $request->getRequest()->params;
        $this->assertInstanceOf(CompleteRequestParams::class, $params);
        $this->assertInstanceOf(CompletionContext::class, $params->context);
        $this->assertSame(['language' => 'python'], $params->context->arguments);
    }

    /**
     * With no `context` key, the parsed params carry a null context.
     */
    public function testClientRequestNoContextIsNull(): void
    {
        $request = ClientRequest::fromMethodAndParams('completion/complete', [
            'argument' => ['name' => 'framework', 'value' => 'dj'],
            'ref' => ['type' => 'ref/prompt', 'name' => 'greet'],
        ]);

        $params = $request->getRequest()->params;
        $this->assertInstanceOf(CompleteRequestParams::class, $params);
        $this->assertNull($params->context);
    }

    /**
     * CompleteRequestParams serializes `context` only when present
     * (backward-compatibility guard).
     */
    public function testCompleteRequestParamsSerializesContextOnlyWhenPresent(): void
    {
        $argument = new CompletionArgument('framework', 'dj');
        $ref = new PromptReference('greet');

        $withContext = new CompleteRequestParams(
            $argument,
            $ref,
            context: new CompletionContext(['language' => 'python'])
        );
        $withoutContext = new CompleteRequestParams($argument, $ref);

        $this->assertArrayHasKey('context', (array) $withContext->jsonSerialize());
        $this->assertArrayNotHasKey('context', (array) $withoutContext->jsonSerialize());
    }
}
