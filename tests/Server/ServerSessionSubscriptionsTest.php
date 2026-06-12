<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Filename: tests/Server/ServerSessionSubscriptionsTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\InitializationOptions;
use Mcp\Server\NotificationOptions;
use Mcp\Server\Server;
use Mcp\Server\ServerSession;
use Mcp\Server\Transport\Transport;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCError;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\MetaKeys;
use Mcp\Types\RequestId;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * subscriptions/listen on the stdio transport (SEP-2575, 2026-07-28).
 *
 * On stdio the subscription lives in-session: the server sends
 * `notifications/subscriptions/acknowledged` as the FIRST message of the
 * subscription (never a JSON-RPC response), registers the honored filter,
 * and tags every forwarded notification — including the ack — with
 * `_meta["io.modelcontextprotocol/subscriptionId"]` (the stringified
 * listen request id) so a client can demultiplex concurrent
 * subscriptions.
 */
final class ServerSessionSubscriptionsTest extends TestCase
{
    /** @return array{SubscriptionsCaptureTransport, ServerSession} */
    private function makeSession(): array
    {
        $server = new Server('stdio-subscriptions-test');
        // Capabilities only include tools/prompts/resources when handlers
        // exist — the listChanged flags hang off those capability objects,
        // and resources presence gates resourceSubscriptions delivery.
        $server->registerHandler('tools/list', fn ($params) => new \Mcp\Types\ListToolsResult([]));
        $server->registerHandler('prompts/list', fn ($params) => new \Mcp\Types\ListPromptsResult([]));
        $server->registerHandler('resources/list', fn ($params) => new \Mcp\Types\ListResourcesResult([]));
        $capabilities = $server->getCapabilities(
            new NotificationOptions(promptsChanged: true, toolsChanged: true),
            []
        );
        $transport = new SubscriptionsCaptureTransport();
        $session = new ServerSession(
            $transport,
            new InitializationOptions(
                serverName: 'stdio-subscriptions-test',
                serverVersion: '1.0.0',
                capabilities: $capabilities,
            ),
            new NullLogger()
        );
        return [$transport, $session];
    }

    private function envelope(): array
    {
        return [
            MetaKeys::PROTOCOL_VERSION => '2026-07-28',
            MetaKeys::CLIENT_INFO => ['name' => 'stdio-sub-client', 'version' => '1.0.0'],
            MetaKeys::CLIENT_CAPABILITIES => [],
        ];
    }

    private function listen(ServerSession $session, array $notifications, int|string $id): void
    {
        $wireParams = [
            'notifications' => $notifications,
            '_meta' => $this->envelope(),
        ];
        $responder = new \Mcp\Shared\RequestResponder(
            new RequestId($id),
            $wireParams,
            \Mcp\Types\ClientRequest::fromMethodAndParams('subscriptions/listen', $wireParams),
            $session
        );
        $session->handleRequest($responder);
    }

    public function testListenAcknowledgesWithoutResponding(): void
    {
        [$transport, $session] = $this->makeSession();

        $this->listen($session, ['toolsListChanged' => true], id: 31);

        $this->assertCount(1, $transport->written);
        $inner = $transport->written[0]->message;
        $this->assertInstanceOf(JSONRPCNotification::class, $inner, 'The listen request gets no JSON-RPC response');
        $this->assertSame('notifications/subscriptions/acknowledged', $inner->method);

        $params = json_decode((string) json_encode($inner->params), true);
        $this->assertSame('31', $params['_meta'][MetaKeys::SUBSCRIPTION_ID], 'Ack carries the subscription id');
        $this->assertTrue($params['notifications']['toolsListChanged'] ?? false);

        $this->assertArrayHasKey('31', $session->getActiveSubscriptions());
    }

    public function testDeliveryHonorsFilterAndTagsSubscriptionId(): void
    {
        [$transport, $session] = $this->makeSession();
        $this->listen($session, ['toolsListChanged' => true], id: 'listen-1');
        $transport->written = [];

        $session->deliverSubscriptionNotification('notifications/tools/list_changed');
        $session->deliverSubscriptionNotification('notifications/prompts/list_changed');

        $this->assertCount(1, $transport->written, 'Only opted-in notification types may flow (strict filter)');
        $inner = $transport->written[0]->message;
        $this->assertInstanceOf(JSONRPCNotification::class, $inner);
        $this->assertSame('notifications/tools/list_changed', $inner->method);
        $params = json_decode((string) json_encode($inner->params), true);
        $this->assertSame('listen-1', $params['_meta'][MetaKeys::SUBSCRIPTION_ID]);
    }

    public function testConcurrentSubscriptionsDemultiplexById(): void
    {
        [$transport, $session] = $this->makeSession();
        $this->listen($session, ['toolsListChanged' => true], id: 1);
        $this->listen($session, ['toolsListChanged' => true, 'promptsListChanged' => true], id: 2);
        $transport->written = [];

        $session->deliverSubscriptionNotification('notifications/tools/list_changed');

        $this->assertCount(2, $transport->written, 'Both subscriptions opted into tools changes');
        $ids = [];
        foreach ($transport->written as $msg) {
            $params = json_decode((string) json_encode($msg->message->params), true);
            $ids[] = $params['_meta'][MetaKeys::SUBSCRIPTION_ID];
        }
        sort($ids);
        $this->assertSame(['1', '2'], $ids);
    }

    public function testResourceSubscriptionsDeliveredByUriOnStdio(): void
    {
        // stdio delivers in-session, so resourceSubscriptions is honored
        // whenever the server serves resources — and updates flow only
        // for opted-in URIs.
        [$transport, $session] = $this->makeSession();
        $this->listen($session, ['resourceSubscriptions' => ['test://watched']], id: 51);

        $ackParams = json_decode((string) json_encode($transport->written[0]->message->params), true);
        $this->assertSame(['test://watched'], $ackParams['notifications']['resourceSubscriptions'] ?? null);
        $transport->written = [];

        $session->deliverSubscriptionNotification('notifications/resources/updated', ['uri' => 'test://watched']);
        $session->deliverSubscriptionNotification('notifications/resources/updated', ['uri' => 'test://other']);

        $this->assertCount(1, $transport->written, 'Only opted-in URIs may flow');
        $params = json_decode((string) json_encode($transport->written[0]->message->params), true);
        $this->assertSame('test://watched', $params['uri']);
    }

    public function testSubscriptionBusDoesNotFakeLegacySubscribeCapability(): void
    {
        // Configuring the modern bus must not advertise the LEGACY
        // resources/subscribe RPC: McpServer has no legacy update
        // channel, so pre-2026 clients would subscribe into silence.
        $mcp = new \Mcp\Server\McpServer('cap-honesty-test');
        $mcp->resource(uri: 'test://r', name: 'R', callback: fn () => 'r');
        $mcp->subscriptionBus(new \Mcp\Server\Subscriptions\InMemorySubscriptionBus());

        $handlers = $mcp->getServer()->getHandlers();
        $this->assertArrayNotHasKey('resources/subscribe', $handlers);

        $caps = $mcp->getServer()->getCapabilities(new NotificationOptions(), []);
        $this->assertNotNull($caps->resources);
        $this->assertFalse(
            (bool) $caps->resources->subscribe,
            'The legacy subscribe capability must reflect actual legacy RPC support'
        );
    }

    public function testCancelledNotificationEndsSubscription(): void
    {
        // SEP-2575 stdio binding: notifications/cancelled referencing the
        // listen request id terminates the subscription — no further
        // notifications may be forwarded for it.
        [$transport, $session] = $this->makeSession();
        $this->listen($session, ['toolsListChanged' => true], id: 41);
        $transport->written = [];

        $cancel = \Mcp\Types\ClientNotification::fromMethodAndParams('notifications/cancelled', [
            'requestId' => 41,
            '_meta' => $this->envelope(),
        ]);
        $session->handleNotification($cancel);

        $this->assertArrayNotHasKey('41', $session->getActiveSubscriptions());

        $session->deliverSubscriptionNotification('notifications/tools/list_changed');
        $this->assertSame([], $transport->written, 'A cancelled subscription receives nothing');
    }

    public function testCancellingUnrelatedRequestIdKeepsSubscription(): void
    {
        [$transport, $session] = $this->makeSession();
        $this->listen($session, ['toolsListChanged' => true], id: 42);
        $transport->written = [];

        $cancel = \Mcp\Types\ClientNotification::fromMethodAndParams('notifications/cancelled', [
            'requestId' => 999,
            '_meta' => $this->envelope(),
        ]);
        $session->handleNotification($cancel);

        $this->assertArrayHasKey('42', $session->getActiveSubscriptions());
        $session->deliverSubscriptionNotification('notifications/tools/list_changed');
        $this->assertCount(1, $transport->written);
    }

    public function testListenWithoutFilterAnswersInvalidParams(): void
    {
        [$transport, $session] = $this->makeSession();

        $wireParams = ['_meta' => $this->envelope()];
        $responder = new \Mcp\Shared\RequestResponder(
            new RequestId(33),
            $wireParams,
            \Mcp\Types\ClientRequest::fromMethodAndParams('subscriptions/listen', $wireParams),
            $session
        );
        $session->handleRequest($responder);

        $this->assertCount(1, $transport->written);
        $inner = $transport->written[0]->message;
        $this->assertInstanceOf(JSONRPCError::class, $inner);
        $this->assertSame(-32602, $inner->error->code);
    }
}

/**
 * Minimal transport capturing every written message.
 */
final class SubscriptionsCaptureTransport implements Transport
{
    /** @var list<JsonRpcMessage> */
    public array $written = [];

    public function start(): void
    {
    }

    public function stop(): void
    {
    }

    public function readMessage(): ?JsonRpcMessage
    {
        return null;
    }

    public function writeMessage(JsonRpcMessage $message): void
    {
        $this->written[] = $message;
    }
}
