<?php

declare(strict_types=1);

namespace Mcp\Tests\Types;

use Mcp\Types\ClientRequest;
use Mcp\Types\InitializeRequest;
use Mcp\Types\InitializeRequestParams;
use Mcp\Types\Meta;
use PHPUnit\Framework\TestCase;

/**
 * An `initialize` request may carry `_meta` on the wire (SEP-414 trace
 * context is allowed on any request). It arrives as a decoded array and has
 * to be converted to `Meta` like every other request family does, otherwise
 * the typed `?Meta` parameter of `InitializeRequestParams` raises a
 * TypeError and the handshake dies before the session can answer.
 */
final class ClientRequestInitializeMetaTest extends TestCase
{
    public function testInitializeWithArrayMetaIsConvertedToMeta(): void
    {
        $request = ClientRequest::fromMethodAndParams('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
            '_meta' => [
                'traceparent' => '00-0cb6548322595e40d670720bbba87ac4-fc8b88c394fe972b-01',
                'progressToken' => 42,
            ],
        ]);

        $inner = $request->getRequest();
        $this->assertInstanceOf(InitializeRequest::class, $inner);

        $params = $inner->params;
        $this->assertInstanceOf(InitializeRequestParams::class, $params);
        $this->assertInstanceOf(Meta::class, $params->_meta);
        $this->assertSame(
            '00-0cb6548322595e40d670720bbba87ac4-fc8b88c394fe972b-01',
            $params->_meta->traceparent
        );
        $this->assertSame(42, $params->_meta->progressToken);

        // The whole request must stay valid: validate() walks into _meta.
        $request->validate();
    }

    public function testInitializeWithoutMetaKeepsNullMeta(): void
    {
        $request = ClientRequest::fromMethodAndParams('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
        ]);

        $params = $request->getRequest()->params;
        $this->assertInstanceOf(InitializeRequestParams::class, $params);
        $this->assertNull($params->_meta);
    }

    public function testInitializeWithScalarMetaIsIgnoredRatherThanFatal(): void
    {
        $request = ClientRequest::fromMethodAndParams('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
            '_meta' => 'not-an-object',
        ]);

        $params = $request->getRequest()->params;
        $this->assertInstanceOf(InitializeRequestParams::class, $params);
        $this->assertNull($params->_meta);
    }
}
