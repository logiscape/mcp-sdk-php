<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Filename: tests/Client/ClientDeprecationWarningsTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client;

use Mcp\Client\ClientSession;
use Mcp\Shared\MemoryStream;
use Mcp\Tests\Shared\RecordingLogger;
use Mcp\Types\CreateMessageRequest;
use Mcp\Types\CreateMessageResult;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\ListRootsResult;
use Mcp\Types\LoggingLevel;
use Mcp\Types\RequestId;
use Mcp\Types\Role;
use Mcp\Types\Root;
use Mcp\Types\TextContent;
use PHPUnit\Framework\TestCase;

/**
 * SEP-2596/SEP-2577 runtime deprecation warnings, client side: servicing
 * a sampling or roots input request on a modern (2026-07-28) session,
 * calling setLoggingLevel(), or emitting notifications/roots/list_changed
 * exercises a Deprecated feature and emits one PSR-3 warning per feature
 * per session. Wire behavior is unchanged — the calls still work.
 */
final class ClientDeprecationWarningsTest extends TestCase
{
    /**
     * @return array{ClientSession, MemoryStream, MemoryStream, RecordingLogger}
     */
    private function modernSession(?callable $configure = null): array
    {
        $readStream = new MemoryStream();
        $writeStream = new MemoryStream();
        $logger = new RecordingLogger();
        $session = new ClientSession($readStream, $writeStream, readTimeout: 2.0, logger: $logger);
        if ($configure !== null) {
            $configure($session);
        }
        $session->negotiate('modern');
        return [$session, $readStream, $writeStream, $logger];
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

    /** @return list<string> */
    private function warningsMentioning(RecordingLogger $logger, string $needle): array
    {
        return array_values(array_filter(
            $logger->warnings(),
            static fn (string $m): bool => str_contains($m, $needle)
        ));
    }

    public function testServicingSamplingAndRootsInputRequestsWarnsPerFeature(): void
    {
        [$session, $readStream, , $logger] = $this->modernSession(
            static function (ClientSession $session): void {
                $session->onSampling(
                    static fn (CreateMessageRequest $r): CreateMessageResult =>
                        new CreateMessageResult(
                            content: new TextContent('sampled'),
                            model: 'test-model',
                            role: Role::ASSISTANT
                        )
                );
                $session->onListRoots(
                    static fn (): ListRootsResult =>
                        new ListRootsResult([new Root(uri: 'file:///ws', name: 'ws')])
                );
            }
        );

        $readStream->send($this->response(0, [
            'resultType' => 'input_required',
            'inputRequests' => [
                'llm' => [
                    'method' => 'sampling/createMessage',
                    'params' => [
                        'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'hi']]],
                        'maxTokens' => 16,
                    ],
                ],
                'roots' => ['method' => 'roots/list', 'params' => []],
            ],
        ]));
        $readStream->send($this->response(1, [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'done']],
        ]));

        $session->callTool('test_tool', []);

        $this->assertCount(1, $this->warningsMentioning($logger, "'sampling'"));
        $this->assertCount(1, $this->warningsMentioning($logger, "'roots'"));
    }

    public function testSetLoggingLevelWarnsOnceOnModernSession(): void
    {
        [$session, $readStream, , $logger] = $this->modernSession();

        $readStream->send($this->response(0, ['resultType' => 'complete']));
        $session->setLoggingLevel(LoggingLevel::INFO);
        $readStream->send($this->response(1, ['resultType' => 'complete']));
        $session->setLoggingLevel(LoggingLevel::DEBUG);

        $this->assertCount(
            1,
            $this->warningsMentioning($logger, "'logging'"),
            'One warning per feature per session; the calls themselves still go out'
        );
    }

    public function testSendRootsListChangedWarnsOnModernSession(): void
    {
        [$session, , $writeStream, $logger] = $this->modernSession();

        $session->sendRootsListChanged();

        $this->assertCount(1, $this->warningsMentioning($logger, "'roots'"));
        $this->assertNotNull($writeStream->receive(), 'The deprecated notification is still emitted (wire behavior unchanged)');
    }
}
