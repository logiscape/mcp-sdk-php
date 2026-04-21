<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 */

/**
 * GET    /api/logs.php?limit=200  — tail of the webclient internal log file.
 * DELETE /api/logs.php            — truncate the log file.
 *
 * This is a convenience endpoint for operators poking around; the per-request
 * logs returned inline with every other API response are usually enough.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/Bootstrap.php';
Bootstrap::init();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$logFile = Bootstrap::root() . '/logs/mcp-web-tester.log';

if ($method === 'DELETE') {
    if (is_file($logFile)) {
        @file_put_contents($logFile, '');
    }
    Bootstrap::json(['success' => true]);
}

if ($method !== 'GET') {
    Bootstrap::json(['success' => false, 'error' => 'Method not allowed'], 405);
}

if (!is_file($logFile)) {
    Bootstrap::json(['success' => true, 'entries' => []]);
}

$limit = max(1, min(1000, (int)($_GET['limit'] ?? 200)));
$contents = @file_get_contents($logFile);
if (!is_string($contents)) {
    Bootstrap::json(['success' => true, 'entries' => []]);
}
$lines = preg_split('/\r?\n/', trim($contents));
$tail = array_slice($lines, -$limit);
Bootstrap::json([
    'success' => true,
    'entries' => array_values(array_filter($tail, static fn($l) => $l !== '')),
]);
