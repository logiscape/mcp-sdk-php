<?php

declare(strict_types=1);

// Answers like a stdio server whose large message reaches the client in two
// reads: the first half, a pause, then the rest with the newline.
$message = json_encode([
    'jsonrpc' => '2.0',
    'id' => 1,
    'result' => ['content' => [['type' => 'text', 'text' => str_repeat('a', (int) ($argv[1] ?? 100000))]]],
]);
$half = intdiv(strlen($message), 2);

fwrite(STDOUT, substr($message, 0, $half));
fflush(STDOUT);
usleep(200000);
fwrite(STDOUT, substr($message, $half) . (($argv[2] ?? '') === 'no-newline' ? '' : "\n"));
fflush(STDOUT);
// Keep stdout open unless asked to end without a trailing newline
if (($argv[2] ?? '') !== 'no-newline') {
    usleep(500000);
}
