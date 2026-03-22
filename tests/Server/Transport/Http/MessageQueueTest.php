<?php

declare(strict_types=1);

namespace Mcp\Tests\Server\Transport\Http;

use Mcp\Server\Transport\Http\MessageQueue;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\RequestId;
use Mcp\Types\Result;
use PHPUnit\Framework\TestCase;

final class MessageQueueTest extends TestCase
{
    private MessageQueue $queue;

    protected function setUp(): void
    {
        $this->queue = new MessageQueue();
    }

    /**
     * Verify that incoming messages are dequeued in FIFO order.
     * Three messages are queued and must come back in the exact order they were added.
     */
    public function testQueueIncomingAndDequeueFifo(): void
    {
        $msg1 = $this->createRequestMessage(1, 'method/one');
        $msg2 = $this->createRequestMessage(2, 'method/two');
        $msg3 = $this->createRequestMessage(3, 'method/three');

        $this->assertTrue($this->queue->queueIncoming($msg1));
        $this->assertTrue($this->queue->queueIncoming($msg2));
        $this->assertTrue($this->queue->queueIncoming($msg3));

        $this->assertSame($msg1, $this->queue->dequeueIncoming());
        $this->assertSame($msg2, $this->queue->dequeueIncoming());
        $this->assertSame($msg3, $this->queue->dequeueIncoming());
    }

    /**
     * Verify that dequeuing from an empty incoming queue returns null
     * rather than throwing an error.
     */
    public function testDequeueEmptyReturnsNull(): void
    {
        $this->assertNull($this->queue->dequeueIncoming());
    }

    /**
     * Verify that queueIncoming returns false when the queue has reached its
     * maximum size, preventing unbounded memory growth.
     */
    public function testQueueIncomingRejectsWhenFull(): void
    {
        $queue = new MessageQueue(2);

        $this->assertTrue($queue->queueIncoming($this->createRequestMessage(1)));
        $this->assertTrue($queue->queueIncoming($this->createRequestMessage(2)));
        $this->assertFalse($queue->queueIncoming($this->createRequestMessage(3)));

        // Only the first two messages should be retrievable
        $this->assertSame(2, $queue->countIncoming());
    }

    /**
     * Verify that queueOutgoing correctly associates messages with a session
     * and that hasOutgoing and countOutgoing reflect the queued state.
     */
    public function testQueueOutgoingForSession(): void
    {
        $sessionId = 'session-alpha';
        $msg1 = $this->createNotificationMessage('notify/one');
        $msg2 = $this->createNotificationMessage('notify/two');

        $this->assertFalse($this->queue->hasOutgoing($sessionId));
        $this->assertSame(0, $this->queue->countOutgoing($sessionId));

        $this->assertTrue($this->queue->queueOutgoing($msg1, $sessionId));
        $this->assertTrue($this->queue->queueOutgoing($msg2, $sessionId));

        $this->assertTrue($this->queue->hasOutgoing($sessionId));
        $this->assertSame(2, $this->queue->countOutgoing($sessionId));
    }

    /**
     * Verify that the per-session outgoing queue enforces the max queue size.
     * Once a session's queue is full, further messages are rejected.
     */
    public function testQueueOutgoingRejectsWhenFull(): void
    {
        $queue = new MessageQueue(2);
        $sessionId = 'session-beta';

        $this->assertTrue($queue->queueOutgoing($this->createNotificationMessage(), $sessionId));
        $this->assertTrue($queue->queueOutgoing($this->createNotificationMessage(), $sessionId));
        $this->assertFalse($queue->queueOutgoing($this->createNotificationMessage(), $sessionId));

        $this->assertSame(2, $queue->countOutgoing($sessionId));
    }

    /**
     * Verify that flushOutgoing returns all queued messages for the session
     * and clears the queue so hasOutgoing returns false afterwards.
     */
    public function testFlushOutgoingReturnsAndClears(): void
    {
        $sessionId = 'session-gamma';
        $msg1 = $this->createNotificationMessage('notify/a');
        $msg2 = $this->createNotificationMessage('notify/b');

        $this->queue->queueOutgoing($msg1, $sessionId);
        $this->queue->queueOutgoing($msg2, $sessionId);

        $flushed = $this->queue->flushOutgoing($sessionId);

        $this->assertCount(2, $flushed);
        $this->assertSame($msg1, $flushed[0]);
        $this->assertSame($msg2, $flushed[1]);
        $this->assertFalse($this->queue->hasOutgoing($sessionId));
        $this->assertSame(0, $this->queue->countOutgoing($sessionId));
    }

    /**
     * Verify that flushing a session ID that was never used returns an empty
     * array without error.
     */
    public function testFlushUnknownSessionReturnsEmpty(): void
    {
        $result = $this->queue->flushOutgoing('nonexistent-session');

        $this->assertSame([], $result);
    }

    /**
     * Verify that queueResponse routes a response to the correct session.
     * A request is queued outgoing for session "A", which records the request ID
     * mapping. A response with the same ID is then routed to session "A".
     */
    public function testQueueResponseRoutesToCorrectSession(): void
    {
        $sessionA = 'session-A';
        $sessionB = 'session-B';
        $requestId = 42;

        // Queue a request for session A — this creates the ID-to-session mapping
        $request = $this->createRequestMessage($requestId);
        $this->queue->queueOutgoing($request, $sessionA);

        // Flush session A so we start clean for counting
        $this->queue->flushOutgoing($sessionA);

        // Now queue a response with the same request ID
        $response = $this->createResponseMessage($requestId);
        $this->assertTrue($this->queue->queueResponse($response));

        // The response should have been routed to session A, not B
        $this->assertTrue($this->queue->hasOutgoing($sessionA));
        $this->assertFalse($this->queue->hasOutgoing($sessionB));

        $flushed = $this->queue->flushOutgoing($sessionA);
        $this->assertCount(1, $flushed);
        $this->assertSame($response, $flushed[0]);
    }

    /**
     * Verify that queueResponse returns false when the response ID does not
     * match any previously queued request.
     */
    public function testQueueResponseReturnsFalseForUnmappedId(): void
    {
        $response = $this->createResponseMessage(999);

        $this->assertFalse($this->queue->queueResponse($response));
    }

    /**
     * Verify that after a successful queueResponse, the ID mapping is cleaned up.
     * Attempting to route a second response with the same ID should fail.
     */
    public function testQueueResponseCleansUpMapping(): void
    {
        $sessionId = 'session-cleanup';
        $requestId = 77;

        $this->queue->queueOutgoing($this->createRequestMessage($requestId), $sessionId);

        // First response routes successfully
        $this->assertTrue($this->queue->queueResponse($this->createResponseMessage($requestId)));

        // Second response with the same ID should fail — mapping was removed
        $this->assertFalse($this->queue->queueResponse($this->createResponseMessage($requestId)));
    }

    /**
     * Verify that countIncoming accurately reflects the number of messages
     * currently in the incoming queue.
     */
    public function testCountIncoming(): void
    {
        $this->assertSame(0, $this->queue->countIncoming());

        $this->queue->queueIncoming($this->createRequestMessage(1));
        $this->assertSame(1, $this->queue->countIncoming());

        $this->queue->queueIncoming($this->createRequestMessage(2));
        $this->assertSame(2, $this->queue->countIncoming());

        $this->queue->dequeueIncoming();
        $this->assertSame(1, $this->queue->countIncoming());
    }

    /**
     * Verify that clear() removes all incoming messages, outgoing messages,
     * and request ID mappings from the queue.
     */
    public function testClearRemovesAll(): void
    {
        $sessionId = 'session-clear';

        $this->queue->queueIncoming($this->createRequestMessage(1));
        $this->queue->queueIncoming($this->createRequestMessage(2));
        $this->queue->queueOutgoing($this->createRequestMessage(10), $sessionId);
        $this->queue->queueOutgoing($this->createNotificationMessage(), $sessionId);

        $this->queue->clear();

        $this->assertSame(0, $this->queue->countIncoming());
        $this->assertNull($this->queue->dequeueIncoming());
        $this->assertFalse($this->queue->hasOutgoing($sessionId));
        $this->assertSame(0, $this->queue->countOutgoing($sessionId));

        // ID mapping should also be cleared — response routing should fail
        $this->assertFalse($this->queue->queueResponse($this->createResponseMessage(10)));
    }

    /**
     * Verify that cleanupExpiredSessions removes the outgoing queues for the
     * specified session IDs while preserving other sessions.
     */
    public function testCleanupExpiredSessions(): void
    {
        $keep = 'session-keep';
        $expire1 = 'session-expire-1';
        $expire2 = 'session-expire-2';

        $this->queue->queueOutgoing($this->createNotificationMessage(), $keep);
        $this->queue->queueOutgoing($this->createNotificationMessage(), $expire1);
        $this->queue->queueOutgoing($this->createNotificationMessage(), $expire2);

        $this->queue->cleanupExpiredSessions([$expire1, $expire2]);

        $this->assertTrue($this->queue->hasOutgoing($keep));
        $this->assertFalse($this->queue->hasOutgoing($expire1));
        $this->assertFalse($this->queue->hasOutgoing($expire2));
    }

    /**
     * Verify that cleanupExpiredSessions also removes the request ID-to-session
     * mappings for expired sessions, so response routing no longer works for
     * those sessions.
     */
    public function testCleanupRemovesMessageIdMappings(): void
    {
        $expiredSession = 'session-expired';
        $requestId = 55;

        // Queue a request to establish the ID mapping
        $this->queue->queueOutgoing($this->createRequestMessage($requestId), $expiredSession);

        // Clean up the session
        $this->queue->cleanupExpiredSessions([$expiredSession]);

        // Response routing should fail because the mapping was removed
        $this->assertFalse($this->queue->queueResponse($this->createResponseMessage($requestId)));
    }

    /**
     * Verify that setMaxQueueSize and getMaxQueueSize work correctly,
     * including the default value from the constructor.
     */
    public function testSetGetMaxQueueSize(): void
    {
        // Default value
        $this->assertSame(1000, $this->queue->getMaxQueueSize());

        // Custom constructor value
        $custom = new MessageQueue(500);
        $this->assertSame(500, $custom->getMaxQueueSize());

        // Setter
        $this->queue->setMaxQueueSize(50);
        $this->assertSame(50, $this->queue->getMaxQueueSize());
    }

    // -----------------------------------------------------------------------
    // Helper methods
    // -----------------------------------------------------------------------

    private function createRequestMessage(int $id, string $method = 'test/method'): JsonRpcMessage
    {
        return new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId($id),
            method: $method,
        ));
    }

    private function createResponseMessage(int $id): JsonRpcMessage
    {
        return new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId($id),
            result: (array)(new Result())->jsonSerialize(),
        ));
    }

    private function createNotificationMessage(string $method = 'test/notify'): JsonRpcMessage
    {
        return new JsonRpcMessage(new JSONRPCNotification(
            jsonrpc: '2.0',
            method: $method,
        ));
    }
}
