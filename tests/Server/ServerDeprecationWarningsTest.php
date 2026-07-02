<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Filename: tests/Server/ServerDeprecationWarningsTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\InitializationOptions;
use Mcp\Server\InitializationState;
use Mcp\Server\NotificationOptions;
use Mcp\Server\Server;
use Mcp\Server\ServerSession;
use Mcp\Server\Transport\Transport;
use Mcp\Tests\Shared\RecordingLogger;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\LoggingLevel;
use Mcp\Types\Meta;
use Mcp\Types\MetaKeys;
use PHPUnit\Framework\TestCase;

/**
 * SEP-2596/SEP-2577 runtime deprecation warnings, server side.
 *
 * Exercising a Deprecated feature (Logging, Sampling, Roots — SEP-2577;
 * the includeContext "thisServer"/"allServers" values — SEP-2596) emits
 * ONE PSR-3 warning per feature per session, and ONLY when the negotiated
 * protocol revision has the feature in the Deprecated state — a
 * 2025-11-25 session exercising Sampling is exercising an Active feature
 * and stays silent. Wire behavior is never affected (SEP-2596 defines no
 * wire-level deprecation signal).
 */
final class ServerDeprecationWarningsTest extends TestCase
{
    /** @return array{DeprecationEraSession, RecordingLogger} */
    private function makeSession(): array
    {
        $server = new Server('deprecation-warnings-test');
        $logger = new RecordingLogger();
        $session = new DeprecationEraSession(
            new DeprecationCaptureTransport(),
            new InitializationOptions(
                serverName: 'deprecation-warnings-test',
                serverVersion: '1.0.0',
                capabilities: $server->getCapabilities(new NotificationOptions(), []),
            ),
            $logger
        );
        return [$session, $logger];
    }

    /** @return list<string> */
    private function warningsMentioning(RecordingLogger $logger, string $needle): array
    {
        return array_values(array_filter(
            $logger->warnings(),
            static fn (string $m): bool => str_contains($m, $needle)
        ));
    }

    public function testSendLogMessageWarnsOnceOnDeprecatingRevision(): void
    {
        [$session, $logger] = $this->makeSession();
        $session->forceNegotiated('2026-07-28');

        $session->sendLogMessage(LoggingLevel::INFO, 'one');
        $session->sendLogMessage(LoggingLevel::INFO, 'two');

        $warnings = $this->warningsMentioning($logger, "'logging'");
        $this->assertCount(1, $warnings, 'Exactly one warning per feature per session');
        $this->assertStringContainsString('SEP-2577', $warnings[0]);
        $this->assertStringContainsString('stderr', $warnings[0]);
    }

    public function testLegacyRevisionExercisesAnActiveFeatureSilently(): void
    {
        [$session, $logger] = $this->makeSession();
        $session->forceNegotiated('2025-11-25');

        $session->sendLogMessage(LoggingLevel::INFO, 'legacy');

        $this->assertSame([], $logger->warnings(), 'Logging is Active at 2025-11-25 — no warning');
    }

    public function testUninitializedSessionNeverWarns(): void
    {
        [$session, $logger] = $this->makeSession();

        $session->sendLogMessage(LoggingLevel::INFO, 'early');

        $this->assertSame([], $logger->warnings(), 'No negotiated revision — no deprecation state to warn about');
    }

    public function testRcWindowDraftAliasWarnsLikeTheDatedRevision(): void
    {
        [$session, $logger] = $this->makeSession();
        $session->forceNegotiated(\Mcp\Shared\Version::DRAFT_MODERN_PROTOCOL_VERSION);

        $session->sendLogMessage(LoggingLevel::INFO, 'draft');

        $this->assertCount(1, $this->warningsMentioning($logger, "'logging'"));
    }

    public function testSamplingWarnsOnModernRevision(): void
    {
        [$session, $logger] = $this->makeSession();
        $session->forceNegotiated('2026-07-28');

        try {
            // No client capabilities are on file, so on the modern era the
            // capability check raises -32021 — after the deprecation
            // warning, which is the subject here.
            $session->sendSamplingRequest(messages: [], maxTokens: 8);
        } catch (\Mcp\Shared\McpError $e) {
            // expected: MissingRequiredClientCapability
        }

        $this->assertCount(1, $this->warningsMentioning($logger, "'sampling'"));
    }

    public function testIncludeContextValuesWarnAtTheirEarlierDeprecationRevision(): void
    {
        [$session, $logger] = $this->makeSession();
        $session->forceNegotiated('2025-11-25');

        $session->sendSamplingRequest(messages: [], maxTokens: 8, includeContext: 'thisServer');

        $this->assertCount(
            1,
            $this->warningsMentioning($logger, 'sampling.includeContext'),
            'The deprecated includeContext values warn from 2025-11-25 (SEP-2596)'
        );
        $this->assertCount(
            0,
            $this->warningsMentioning($logger, "'sampling'"),
            'The Sampling feature itself is still Active at 2025-11-25'
        );
    }

    public function testLogLevelMetaOptInWarnsAsLoggingNegotiation(): void
    {
        [$session, $logger] = $this->makeSession();
        $session->forceNegotiated('2026-07-28');
        $meta = new Meta();
        $meta->setField(MetaKeys::LOG_LEVEL, 'info');
        $session->forceRawMeta($meta);

        $this->assertSame('info', $session->getCurrentRequestLogLevel());
        $this->assertCount(1, $this->warningsMentioning($logger, "'logging'"));
    }
}

/**
 * Test subclass exposing the era state the deprecation gate reads —
 * warnings depend only on the negotiated revision, which in production is
 * set by the legacy handshake or per-request modern envelope adoption.
 */
final class DeprecationEraSession extends ServerSession
{
    public function forceNegotiated(string $version): void
    {
        $this->negotiatedProtocolVersion = $version;
        $this->initializationState = InitializationState::Initialized;
    }

    public function forceRawMeta(Meta $meta): void
    {
        $this->currentRawMeta = $meta;
    }
}

/**
 * Minimal transport swallowing written messages.
 */
final class DeprecationCaptureTransport implements Transport
{
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
    }
}
