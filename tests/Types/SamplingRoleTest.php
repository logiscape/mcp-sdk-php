<?php

declare(strict_types=1);

namespace Mcp\Tests\Types;

use Mcp\Types\Role;
use Mcp\Types\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests that ServerRequest correctly converts string roles to Role enums
 * when parsing sampling/createMessage requests.
 */
final class SamplingRoleTest extends TestCase
{
    /**
     * Test that valid role strings are converted to Role enums.
     */
    public function testValidRolesConvertedToEnums(): void {
        $request = ServerRequest::fromMethodAndParams('sampling/createMessage', [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => ['type' => 'text', 'text' => 'Hello'],
                ],
                [
                    'role' => 'assistant',
                    'content' => ['type' => 'text', 'text' => 'Hi there'],
                ],
            ],
            'maxTokens' => 100,
        ]);

        $inner = $request->getRequest();
        $this->assertInstanceOf(\Mcp\Types\CreateMessageRequest::class, $inner);

        $messages = $inner->messages;
        $this->assertCount(2, $messages);
        $this->assertSame(Role::USER, $messages[0]->role);
        $this->assertSame(Role::ASSISTANT, $messages[1]->role);
    }

    /**
     * Test that an invalid role string throws an exception.
     */
    public function testInvalidRoleThrowsException(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('valid role');

        ServerRequest::fromMethodAndParams('sampling/createMessage', [
            'messages' => [
                [
                    'role' => 'system',
                    'content' => ['type' => 'text', 'text' => 'test'],
                ],
            ],
            'maxTokens' => 100,
        ]);
    }

    /**
     * Test that missing role throws an exception.
     */
    public function testMissingRoleThrowsException(): void {
        $this->expectException(\InvalidArgumentException::class);

        ServerRequest::fromMethodAndParams('sampling/createMessage', [
            'messages' => [
                [
                    'content' => ['type' => 'text', 'text' => 'test'],
                ],
            ],
            'maxTokens' => 100,
        ]);
    }
}
