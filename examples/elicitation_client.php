<?php

/**
 * Elicitation example client (SEP-2322 multi-round-trip input).
 *
 * Registers an elicitation handler BEFORE connecting — that is what makes
 * the client advertise the `elicitation` capability, and what the SDK
 * invokes whenever a server asks for input:
 *
 *   - against a 2026-07-28 server, callTool() transparently services the
 *     InputRequiredResult exchange: the SDK calls the handler for each input
 *     request, echoes the server's opaque requestState back, and re-invokes
 *     the call until the real result arrives (bounded rounds);
 *   - against a legacy server, the same handler answers the classic
 *     server-initiated elicitation/create request.
 *
 * Either way, this file's callTool() line looks like an ordinary tool call.
 *
 * Usage:
 *   php examples/elicitation_client.php                       (spawns elicitation_server.php over stdio)
 *   php examples/elicitation_client.php http://localhost:8000 (existing HTTP server)
 */

require 'vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Types\ElicitationCreateRequest;
use Mcp\Types\ElicitationCreateResult;

$client = new Client();

// The handler receives the server's message + requestedSchema and returns an
// action ('accept' | 'decline' | 'cancel') with the content on accept. A real
// client would render a form for the user; this one answers programmatically.
$client->onElicit(function (ElicitationCreateRequest $request): ElicitationCreateResult {
    echo "  server asks: {$request->message}\n";
    echo '  requested schema properties: '
        . implode(', ', array_keys($request->requestedSchema['properties'] ?? [])) . "\n";

    return new ElicitationCreateResult(
        action: 'accept',
        content: ['seats' => 4, 'notes' => 'one vegetarian'],
    );
});

try {
    $target = $argv[1] ?? null;

    if ($target !== null && (str_starts_with($target, 'http://') || str_starts_with($target, 'https://'))) {
        $session = $client->connect($target);
    } else {
        $session = $client->connect('php', [__DIR__ . '/elicitation_server.php']);
    }

    $era = $session->isModernMode() ? 'modern' : 'legacy';
    echo "Connected ({$era} era, {$session->getNegotiatedProtocolVersion()}).\n\n";

    echo "callTool('book-table', {restaurant: 'Chez Test'})\n";
    $result = $session->callTool('book-table', ['restaurant' => 'Chez Test']);

    echo "\nResult: {$result->content[0]->text}\n";
} catch (\Exception $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
} finally {
    $client->close();
}
