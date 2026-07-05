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
 * Filename: tests/Client/ClientOnSamplingTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client;

use Mcp\Client\Client;
use Mcp\Types\CallToolResult;
use Mcp\Types\CreateMessageRequest;
use Mcp\Types\CreateMessageResult;
use Mcp\Types\Role;
use Mcp\Types\TextContent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the Client-level onSampling() pre-connect registration wrapper.
 *
 * ClientSession::onSampling() must run before initialize() so the sampling
 * capability is advertised in the handshake — which makes a Client-level
 * wrapper (mirroring onElicit()/onListRoots()) the only way to register a
 * sampling handler through the public Client::connect() flow. These tests
 * pin the wrapper's guard and prove the handler is applied end-to-end:
 * a spawned stdio server whose tool samples via SamplingContext receives
 * the completion produced by the handler registered on the Client.
 */
final class ClientOnSamplingTest extends TestCase
{
    private ?string $serverScript = null;

    protected function tearDown(): void
    {
        if ($this->serverScript !== null && file_exists($this->serverScript)) {
            @unlink($this->serverScript);
        }
    }

    /**
     * A sampling handler registered on the Client before connect() is
     * applied to the created session pre-initialize, so the capability is
     * advertised and a tool that samples receives the handler's completion.
     */
    public function testOnSamplingHandlerServicesSamplingThroughConnect(): void
    {
        $this->serverScript = tempnam(sys_get_temp_dir(), 'mcp_sampling_srv_');
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        file_put_contents($this->serverScript, <<<PHP
<?php
require {$autoload};

use Mcp\Server\McpServer;
use Mcp\Server\Sampling\SamplingContext;
use Mcp\Types\TextContent;

\$server = new McpServer('sampling-fixture');
\$server->tool(
    'summarize',
    'Summarize a text via client-side sampling',
    function (string \$text, SamplingContext \$sampling): string {
        if (!\$sampling->supportsSampling()) {
            return 'sampling-unavailable';
        }
        \$result = \$sampling->prompt(text: "Summarize: {\$text}", maxTokens: 32);
        return (\$result !== null && \$result->content instanceof TextContent)
            ? 'LLM said: ' . \$result->content->text
            : 'sampling-null';
    }
)->runStdio();
PHP);

        $client = new Client();
        $client->onSampling(
            static fn (CreateMessageRequest $request): CreateMessageResult =>
                new CreateMessageResult(
                    content: new TextContent(text: 'handler-completion'),
                    model: 'test-model',
                    role: Role::ASSISTANT
                )
        );

        try {
            $session = $client->connect('php', [$this->serverScript], readTimeout: 20.0);
            $result = $session->callTool('summarize', ['text' => 'hello world']);
            $this->assertInstanceOf(CallToolResult::class, $result);
            $this->assertSame('LLM said: handler-completion', $result->content[0]->text);
        } finally {
            $client->close();
        }
    }

    /**
     * Registration after connect() throws — the capability can no longer
     * be advertised once the handshake has happened.
     */
    public function testOnSamplingAfterConnectThrows(): void
    {
        $this->serverScript = tempnam(sys_get_temp_dir(), 'mcp_sampling_srv_');
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        file_put_contents($this->serverScript, <<<PHP
<?php
require {$autoload};
(new \Mcp\Server\McpServer('guard-fixture'))
    ->tool('ping', 'Answers pong', fn (): string => 'pong')
    ->runStdio();
PHP);

        $client = new Client();
        try {
            $client->connect('php', [$this->serverScript], readTimeout: 20.0);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('onSampling() must be called before connect()');
            $client->onSampling(static fn (CreateMessageRequest $r): CreateMessageResult =>
                new CreateMessageResult(
                    content: new TextContent(text: 'x'),
                    model: 'test-model',
                    role: Role::ASSISTANT
                ));
        } finally {
            $client->close();
        }
    }
}
