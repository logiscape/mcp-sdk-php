<?php

declare(strict_types=1);

namespace Mcp\Tests\Server\Elicitation;

use PHPUnit\Framework\TestCase;
use Mcp\Server\Elicitation\PendingElicitation;

final class PendingElicitationTest extends TestCase
{
    /**
     * Test basic construction with all required fields.
     */
    public function testConstruction(): void
    {
        $pending = new PendingElicitation(
            toolName: 'my-tool',
            toolArguments: ['arg1' => 'value1'],
            originalRequestId: 1,
            serverRequestId: 100,
            elicitationSequence: 0,
            previousResults: [],
            createdAt: 1234567890.123,
        );

        $this->assertSame('my-tool', $pending->toolName);
        $this->assertSame(['arg1' => 'value1'], $pending->toolArguments);
        $this->assertSame(1, $pending->originalRequestId);
        $this->assertSame(100, $pending->serverRequestId);
        $this->assertSame(0, $pending->elicitationSequence);
        $this->assertSame([], $pending->previousResults);
        $this->assertSame(1234567890.123, $pending->createdAt);
    }

    /**
     * Test serialization to array.
     */
    public function testToArray(): void
    {
        $pending = new PendingElicitation(
            toolName: 'configure',
            toolArguments: ['action' => 'setup'],
            originalRequestId: 5,
            serverRequestId: 200,
            elicitationSequence: 1,
            previousResults: [0 => ['action' => 'accept', 'content' => ['name' => 'test']]],
            createdAt: 1000.0,
        );

        $data = $pending->toArray();

        $this->assertSame('configure', $data['toolName']);
        $this->assertSame(['action' => 'setup'], $data['toolArguments']);
        $this->assertSame(5, $data['originalRequestId']);
        $this->assertSame(200, $data['serverRequestId']);
        $this->assertSame(1, $data['elicitationSequence']);
        $this->assertArrayHasKey(0, $data['previousResults']);
        $this->assertSame(1000.0, $data['createdAt']);
    }

    /**
     * Test deserialization from array.
     */
    public function testFromArray(): void
    {
        $data = [
            'toolName' => 'my-tool',
            'toolArguments' => ['key' => 'val'],
            'originalRequestId' => 42,
            'serverRequestId' => 300,
            'elicitationSequence' => 2,
            'previousResults' => [
                0 => ['action' => 'accept', 'content' => ['x' => 1]],
                1 => ['action' => 'accept', 'content' => ['y' => 2]],
            ],
            'createdAt' => 9999.5,
        ];

        $pending = PendingElicitation::fromArray($data);

        $this->assertSame('my-tool', $pending->toolName);
        $this->assertSame(42, $pending->originalRequestId);
        $this->assertSame(300, $pending->serverRequestId);
        $this->assertSame(2, $pending->elicitationSequence);
        $this->assertCount(2, $pending->previousResults);
        $this->assertSame(9999.5, $pending->createdAt);
    }

    /**
     * Test round-trip serialization: toArray() -> fromArray() preserves data.
     */
    public function testRoundTrip(): void
    {
        $original = new PendingElicitation(
            toolName: 'round-trip',
            toolArguments: ['a' => 1, 'b' => 'two'],
            originalRequestId: 10,
            serverRequestId: 500,
            elicitationSequence: 3,
            previousResults: [0 => ['action' => 'accept']],
            createdAt: 12345.6,
        );

        $restored = PendingElicitation::fromArray($original->toArray());

        $this->assertSame($original->toolName, $restored->toolName);
        $this->assertSame($original->toolArguments, $restored->toolArguments);
        $this->assertSame($original->originalRequestId, $restored->originalRequestId);
        $this->assertSame($original->serverRequestId, $restored->serverRequestId);
        $this->assertSame($original->elicitationSequence, $restored->elicitationSequence);
        $this->assertSame($original->previousResults, $restored->previousResults);
        $this->assertSame($original->createdAt, $restored->createdAt);
    }

    /**
     * Test fromArray with minimal data (missing optional fields use defaults).
     */
    public function testFromArrayMinimal(): void
    {
        $data = [
            'toolName' => 'minimal',
            'originalRequestId' => 1,
            'serverRequestId' => 2,
        ];

        $pending = PendingElicitation::fromArray($data);

        $this->assertSame('minimal', $pending->toolName);
        $this->assertSame([], $pending->toolArguments);
        $this->assertSame(0, $pending->elicitationSequence);
        $this->assertSame([], $pending->previousResults);
    }
}
