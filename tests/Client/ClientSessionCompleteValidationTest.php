<?php

declare(strict_types=1);

namespace Mcp\Tests\Client;

use PHPUnit\Framework\TestCase;
use Mcp\Client\ClientSession;
use Mcp\Shared\MemoryStream;
use Mcp\Shared\Version;
use Mcp\Types\InitializeResult;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\Implementation;
use Mcp\Types\PromptReference;

/**
 * Tests for ClientSession::complete() argument validation.
 *
 * Validates that complete() throws InvalidArgumentException when the
 * $argument array is missing required keys, even though the method
 * accepts a loosely-typed array at runtime.
 */
final class ClientSessionCompleteValidationTest extends TestCase
{
    /**
     * Test that complete() throws when 'value' key is missing.
     *
     * Regression test: the runtime guard must reject arrays without 'value'
     * before reaching CompletionArgument construction.
     */
    public function testCompleteThrowsWhenValueKeyMissing(): void
    {
        $session = $this->createRestoredSession();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must have "name" and "value"');

        $session->complete(
            new PromptReference(type: 'ref/prompt', name: 'test'),
            ['name' => 'argName']
        );
    }

    /**
     * Test that complete() throws when 'name' key is empty.
     */
    public function testCompleteThrowsWhenNameKeyEmpty(): void
    {
        $session = $this->createRestoredSession();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must have "name" and "value"');

        $session->complete(
            new PromptReference(type: 'ref/prompt', name: 'test'),
            ['name' => '', 'value' => 'v']
        );
    }

    /**
     * Test that complete() throws when 'name' key is missing entirely.
     */
    public function testCompleteThrowsWhenNameKeyMissing(): void
    {
        $session = $this->createRestoredSession();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must have "name" and "value"');

        $session->complete(
            new PromptReference(type: 'ref/prompt', name: 'test'),
            ['value' => 'v']
        );
    }

    private function createRestoredSession(): ClientSession
    {
        return ClientSession::createRestored(
            readStream: new MemoryStream(),
            writeStream: new MemoryStream(),
            initResult: new InitializeResult(
                capabilities: new ServerCapabilities(),
                serverInfo: new Implementation(name: 'test-server', version: '1.0.0'),
                protocolVersion: Version::LATEST_PROTOCOL_VERSION
            ),
            negotiatedProtocolVersion: Version::LATEST_PROTOCOL_VERSION,
            nextRequestId: 1
        );
    }
}
