<?php

declare(strict_types=1);

namespace Mcp\Tests\Server\Elicitation;

use PHPUnit\Framework\TestCase;
use Mcp\Server\Elicitation\ElicitationManager;
use Mcp\Server\Elicitation\PendingElicitation;

final class ElicitationManagerTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/mcp_elicitation_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (is_dir($this->storagePath)) {
            $files = glob($this->storagePath . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($this->storagePath);
        }
    }

    /**
     * Test that the manager creates the storage directory on construction.
     */
    public function testConstructorCreatesDirectory(): void
    {
        $this->assertDirectoryDoesNotExist($this->storagePath);
        $manager = new ElicitationManager($this->storagePath);
        $this->assertDirectoryExists($this->storagePath);
    }

    /**
     * Test saving and retrieving a pending elicitation.
     */
    public function testSaveAndGetPending(): void
    {
        $manager = new ElicitationManager($this->storagePath);

        $pending = new PendingElicitation(
            toolName: 'test-tool',
            toolArguments: ['key' => 'value'],
            originalRequestId: 1,
            serverRequestId: 100,
            elicitationSequence: 0,
            createdAt: microtime(true),
        );

        $manager->savePending($pending);

        $retrieved = $manager->getPendingByRequestId(100);
        $this->assertNotNull($retrieved);
        $this->assertSame('test-tool', $retrieved->toolName);
        $this->assertSame(1, $retrieved->originalRequestId);
        $this->assertSame(100, $retrieved->serverRequestId);
    }

    /**
     * Test that getting a non-existent pending returns null.
     */
    public function testGetNonExistentPending(): void
    {
        $manager = new ElicitationManager($this->storagePath);
        $this->assertNull($manager->getPendingByRequestId(999));
    }

    /**
     * Test deleting a pending elicitation.
     */
    public function testDeletePending(): void
    {
        $manager = new ElicitationManager($this->storagePath);

        $pending = new PendingElicitation(
            toolName: 'delete-me',
            toolArguments: [],
            originalRequestId: 2,
            serverRequestId: 200,
            elicitationSequence: 0,
            createdAt: microtime(true),
        );

        $manager->savePending($pending);
        $this->assertNotNull($manager->getPendingByRequestId(200));

        $manager->deletePending(200);
        $this->assertNull($manager->getPendingByRequestId(200));
    }

    /**
     * Test that cleanup removes expired entries.
     */
    public function testCleanupExpired(): void
    {
        $manager = new ElicitationManager($this->storagePath);

        // Save a "very old" pending elicitation
        $old = new PendingElicitation(
            toolName: 'old-tool',
            toolArguments: [],
            originalRequestId: 3,
            serverRequestId: 300,
            elicitationSequence: 0,
            createdAt: microtime(true) - 7200, // 2 hours ago
        );

        $manager->savePending($old);
        $this->assertNotNull($manager->getPendingByRequestId(300));

        // Cleanup with 1-hour max age
        $manager->cleanup(3600);

        $this->assertNull($manager->getPendingByRequestId(300));
    }

    /**
     * Test that cleanup does not remove recent entries.
     */
    public function testCleanupKeepsRecent(): void
    {
        $manager = new ElicitationManager($this->storagePath);

        $recent = new PendingElicitation(
            toolName: 'recent-tool',
            toolArguments: [],
            originalRequestId: 4,
            serverRequestId: 400,
            elicitationSequence: 0,
            createdAt: microtime(true),
        );

        $manager->savePending($recent);
        $manager->cleanup(3600);

        $this->assertNotNull($manager->getPendingByRequestId(400));
    }
}
