<?php

/**
 * Elicitation example server (SEP-2322 multi-round-trip input).
 *
 * A tool can ask the connected client for structured input mid-execution by
 * declaring an ElicitationContext parameter (injected by type hint — it is
 * not part of the tool's input schema) and calling $elicit->form().
 *
 * The wire mechanics differ by era, and the SDK handles both behind the same
 * context API:
 *
 *   - 2026-07-28 (modern): servers cannot initiate requests. tools/call
 *     instead answers an InputRequiredResult carrying the input request plus
 *     signed, opaque requestState; the client re-invokes the call with its
 *     inputResponses and the tool body resumes. The `inputKey` names the
 *     round so the answer resolves to the right request.
 *   - 2025-11-25 and older (legacy): a classic server-initiated
 *     elicitation/create request over the session.
 *
 * Clients that do not support elicitation are detected up front with
 * $elicit->isSupported() — always guard, since unguarded form() calls reject
 * modern clients that lack the capability with -32021.
 *
 * Run:
 *   stdio: php examples/elicitation_server.php   (spawned by examples/elicitation_client.php)
 *   HTTP:  php -S localhost:8000 examples/elicitation_server.php
 */

require 'vendor/autoload.php';

use Mcp\Server\Elicitation\ElicitationContext;
use Mcp\Server\McpServer;

$server = new McpServer('elicitation-example-server');

$server
    ->tool(
        'book-table',
        'Books a restaurant table, asking the user for preferences',
        function (ElicitationContext $elicit, string $restaurant): string {
            if (!$elicit->isSupported()) {
                // Graceful degradation for clients without the capability.
                return "Booked a table for 2 at {$restaurant} (defaults used — client cannot be asked).";
            }

            $answer = $elicit->form(
                "How many seats at {$restaurant}, and any dietary notes?",
                [
                    'type' => 'object',
                    'properties' => [
                        'seats' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'default' => 2],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['seats'],
                ],
                inputKey: 'preferences',
            );

            if ($answer === null || $answer->action !== 'accept') {
                return "Booking at {$restaurant} cancelled — the user declined.";
            }

            $content = (array) $answer->content;
            $seats = $content['seats'] ?? 2;
            $notes = $content['notes'] ?? 'none';

            return "Booked a table for {$seats} at {$restaurant} (dietary notes: {$notes}).";
        },
    )

    // run() auto-selects stdio on the CLI and HTTP under a web server.
    ->run();
