<?php

declare(strict_types=1);

namespace Mcp\Tests\Server\Elicitation;

use Mcp\Server\Elicitation\ElicitationContext;
use Mcp\Server\Elicitation\ElicitationSuspendException;
use Mcp\Server\InitializationOptions;
use Mcp\Server\InitializationState;
use Mcp\Server\ServerSession;
use Mcp\Server\Transport\Transport;
use Mcp\Types\ClientCapabilities;
use Mcp\Types\ElicitationCapability;
use Mcp\Types\Implementation;
use Mcp\Types\InitializeRequestParams;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\ServerCapabilities;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Regression test for SEP-1330 enum elicitation schemas.
 *
 * Replays the five server-side assertions from the conformance tool's
 * elicitation-sep1330-enums scenario against a fully serialized
 * ElicitationCreateRequest, so that any future change that drops or
 * mutates a `oneOf`/`anyOf`/`enum`/`enumNames`/`items` field before it
 * hits the wire is caught here instead of in the Node-based conformance
 * suite.
 */
final class ElicitationSep1330SchemaTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function buildSep1330Schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'untitledSingle' => [
                    'type' => 'string',
                    'enum' => ['option1', 'option2', 'option3'],
                ],
                'titledSingle' => [
                    'type' => 'string',
                    'oneOf' => [
                        ['const' => 'value1', 'title' => 'First Option'],
                        ['const' => 'value2', 'title' => 'Second Option'],
                        ['const' => 'value3', 'title' => 'Third Option'],
                    ],
                ],
                'legacyEnum' => [
                    'type' => 'string',
                    'enum' => ['opt1', 'opt2', 'opt3'],
                    'enumNames' => ['Option One', 'Option Two', 'Option Three'],
                ],
                'untitledMulti' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => ['option1', 'option2', 'option3'],
                    ],
                ],
                'titledMulti' => [
                    'type' => 'array',
                    'items' => [
                        'anyOf' => [
                            ['const' => 'value1', 'title' => 'First Choice'],
                            ['const' => 'value2', 'title' => 'Second Choice'],
                            ['const' => 'value3', 'title' => 'Third Choice'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function createSession(): ServerSession
    {
        $transport = new class implements Transport {
            public function start(): void {}
            public function stop(): void {}
            public function readMessage(): ?JsonRpcMessage { return null; }
            public function writeMessage(JsonRpcMessage $message): void {}
        };
        $session = new ServerSession(
            $transport,
            new InitializationOptions(
                serverName: 'sep1330-test',
                serverVersion: '1.0.0',
                capabilities: new ServerCapabilities(),
            ),
            new NullLogger(),
        );

        $ref = new \ReflectionClass($session);
        $ref->getProperty('initializationState')
            ->setValue($session, InitializationState::Initialized);
        $ref->getProperty('clientParams')->setValue(
            $session,
            new InitializeRequestParams(
                protocolVersion: '2025-11-25',
                capabilities: new ClientCapabilities(
                    elicitation: new ElicitationCapability(form: true),
                ),
                clientInfo: new Implementation(name: 'test', version: '1.0'),
            ),
        );
        $ref->getProperty('negotiatedProtocolVersion')
            ->setValue($session, '2025-11-25');

        return $session;
    }

    /**
     * Round-trip the SEP-1330 schema through ElicitationCreateRequest and
     * json_encode, then replay the five conformance-tool assertions on the
     * decoded wire form. This guarantees no serialization hop collapses an
     * associative-keyed shape into a JSON array (or vice versa) or drops a
     * legacy/untitled field.
     *
     * @return array<string, mixed>
     */
    private function roundTripSchemaOnTheWire(): array
    {
        $context = new ElicitationContext(
            session: $this->createSession(),
            httpMode: true,
            preloadedResults: [],
            toolName: 'test_elicitation_sep1330_enums',
            toolArguments: [],
            originalRequestId: 1,
        );

        try {
            $context->form('Please select options', $this->buildSep1330Schema());
            $this->fail('Expected ElicitationSuspendException');
        } catch (ElicitationSuspendException $e) {
            $wire = json_encode($e->request, JSON_THROW_ON_ERROR);
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($wire, true, flags: JSON_THROW_ON_ERROR);
            return $decoded;
        }
    }

    public function testWireFormatCarriesElicitationCreateMethod(): void
    {
        $decoded = $this->roundTripSchemaOnTheWire();
        $this->assertSame('elicitation/create', $decoded['method']);
        $this->assertArrayHasKey('params', $decoded);
        $this->assertArrayHasKey('requestedSchema', $decoded['params']);
        $this->assertArrayHasKey('properties', $decoded['params']['requestedSchema']);
    }

    public function testUntitledSingleEmitsPlainEnumArray(): void
    {
        $props = $this->roundTripSchemaOnTheWire()['params']['requestedSchema']['properties'];
        $this->assertArrayHasKey('untitledSingle', $props);
        $field = $props['untitledSingle'];
        $this->assertSame('string', $field['type']);
        $this->assertIsArray($field['enum']);
        $this->assertTrue(array_is_list($field['enum']));
        $this->assertSame(['option1', 'option2', 'option3'], $field['enum']);
        $this->assertArrayNotHasKey('oneOf', $field);
        $this->assertArrayNotHasKey('enumNames', $field);
    }

    public function testTitledSingleEmitsOneOfWithConstAndTitle(): void
    {
        $props = $this->roundTripSchemaOnTheWire()['params']['requestedSchema']['properties'];
        $this->assertArrayHasKey('titledSingle', $props);
        $field = $props['titledSingle'];
        $this->assertSame('string', $field['type']);
        $this->assertIsArray($field['oneOf']);
        $this->assertTrue(array_is_list($field['oneOf']));
        $this->assertArrayNotHasKey('enum', $field);
        foreach ($field['oneOf'] as $option) {
            $this->assertIsString($option['const']);
            $this->assertIsString($option['title']);
        }
    }

    public function testLegacyEnumFieldNameAndParallelEnumNames(): void
    {
        $props = $this->roundTripSchemaOnTheWire()['params']['requestedSchema']['properties'];

        // Field name must be `legacyEnum` — this is the assertion the
        // conformance tool's fixture looks up by literal property key.
        $this->assertArrayHasKey('legacyEnum', $props);
        $this->assertArrayNotHasKey('legacyTitled', $props);

        $field = $props['legacyEnum'];
        $this->assertSame('string', $field['type']);
        $this->assertIsArray($field['enum']);
        $this->assertIsArray($field['enumNames']);
        $this->assertCount(count($field['enum']), $field['enumNames']);
    }

    public function testUntitledMultiEmitsArrayWithItemsEnum(): void
    {
        $props = $this->roundTripSchemaOnTheWire()['params']['requestedSchema']['properties'];
        $this->assertArrayHasKey('untitledMulti', $props);
        $field = $props['untitledMulti'];
        $this->assertSame('array', $field['type']);
        $this->assertIsArray($field['items']);
        $this->assertSame('string', $field['items']['type']);
        $this->assertIsArray($field['items']['enum']);
        $this->assertArrayNotHasKey('anyOf', $field['items']);
    }

    public function testTitledMultiEmitsArrayWithItemsAnyOf(): void
    {
        $props = $this->roundTripSchemaOnTheWire()['params']['requestedSchema']['properties'];
        $this->assertArrayHasKey('titledMulti', $props);
        $field = $props['titledMulti'];
        $this->assertSame('array', $field['type']);
        $this->assertIsArray($field['items']);
        $this->assertIsArray($field['items']['anyOf']);
        $this->assertTrue(array_is_list($field['items']['anyOf']));
        $this->assertArrayNotHasKey('enum', $field['items']);
        foreach ($field['items']['anyOf'] as $option) {
            $this->assertIsString($option['const']);
            $this->assertIsString($option['title']);
        }
    }
}
