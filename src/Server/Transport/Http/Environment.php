<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2025 Logiscape LLC <https://logiscape.com>
 *
 * Developed by:
 * - Josh Abbott
 * - Claude 3.7 Sonnet (Anthropic AI model)
 * - ChatGPT o1 pro mode
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php 
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 *
 * Filename: Server/Transport/Http/Environment.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport\Http;

/**
 * Environment detection for HTTP transport configuration.
 * 
 * This class provides methods to detect the current PHP environment's capabilities
 * and limitations, especially regarding HTTP and SSE support.
 */
class Environment
{
    /**
     * Check if the current environment is likely a shared hosting environment.
     *
     * @return bool True if the environment appears to be shared hosting
     */
    public static function isSharedHosting(): bool
    {
        // Check for common shared hosting indicators
        $server = $_SERVER['SERVER_SOFTWARE'] ?? '';
        
        // Common shared hosting identifiers
        $sharedHostingIdentifiers = [
            'cpanel', 'plesk', 'directadmin', 'hostgator', 'bluehost', 
            'godaddy', 'cloudlinux', 'litespeed'
        ];
        
        foreach ($sharedHostingIdentifiers as $identifier) {
            if (stripos($server, $identifier) !== false) {
                return true;
            }
        }
        
        // Check for open_basedir restrictions (common in shared hosting)
        if (ini_get('open_basedir') !== '') {
            return true;
        }
        
        // Check for suPHP or other restrictive environments
        if (strpos(php_sapi_name() ?: '', 'cgi') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Detect the maximum PHP execution time allowed by the environment.
     *
     * @return int The maximum execution time in seconds (0 means no limit)
     */
    public static function detectMaxExecutionTime(): int
    {
        $maxExecution = (int)ini_get('max_execution_time');
        
        // 0 means no time limit
        if ($maxExecution <= 0) {
            return 0;
        }
        
        return $maxExecution;
    }
    
    /**
     * Check if PHP is running in CLI mode.
     *
     * @return bool True if running in CLI mode
     */
    public static function isCliMode(): bool
    {
        return php_sapi_name() === 'cli';
    }
    
    /**
     * Check if the environment can support Server-Sent Events (SSE).
     *
     * The SDK's SSE implementation is *resumable*: each POST produces a
     * bounded SSE response body and may close early, with the client
     * reconnecting via GET + Last-Event-ID. Because responses do not
     * require a long-lived connection, the gate is permissive — it fails
     * only when the runtime would actively mangle the SSE wire format
     * (output compression) or cannot flush output at all.
     *
     * @return bool True if SSE can be supported
     */
    public static function canSupportSse(): bool
    {
        // Output compression would chunk/gzip the response body and break
        // SSE event framing as parsed by clients.
        if (ini_get('zlib.output_compression') == '1') {
            return false;
        }

        // If an output buffer is active we need to be able to flush it
        // before writing SSE headers. ob_end_flush is near-universal; this
        // check exists purely for exotic SAPIs that disable it.
        if (ob_get_level() > 0 && !function_exists('ob_end_flush')) {
            return false;
        }

        return true;
    }
    
    /**
     * Get recommended configuration based on the detected environment.
     *
     * @return array{session_timeout: int, enable_sse: bool, max_queue_size: int} Recommended configuration options
     */
    public static function getRecommendedConfig(): array
    {
        $config = [
            'session_timeout' => 3600, // 1 hour default
            'enable_sse' => false,     // Default disabled for compatibility
            'max_queue_size' => 1000,  // Maximum messages in queue
        ];
        
        // Adjust for CLI mode (development)
        if (self::isCliMode()) {
            $config['session_timeout'] = 86400; // 24 hours for development
        }

        // Adjust for production environments
        if (!self::isCliMode() && self::isSharedHosting()) {
            $config['session_timeout'] = 1800; // 30 minutes
            $config['max_queue_size'] = 500;  // Smaller queue
        }

        // Note: `enable_sse` is intentionally NOT auto-toggled here. Flipping
        // it silently in CLI or non-shared-hosting environments would change
        // the wire `Content-Type` of POST responses (JSON → text/event-stream)
        // for any spec-compliant MCP client (2025-11-25 requires clients to
        // list both media types in Accept), contradicting the documented
        // default and surprising users. Callers must opt in explicitly via
        // ['enable_sse' => true].
        
        // Determine maximum execution time and adjust accordingly
        $maxExecution = self::detectMaxExecutionTime();
        if ($maxExecution > 0) {
            // Ensure session timeout is not longer than max execution time
            // with some margin for safety
            $safeTimeout = (int)($maxExecution * 0.8);
            if ($safeTimeout < $config['session_timeout']) {
                $config['session_timeout'] = $safeTimeout;
            }
        }
        
        return $config;
    }
}
