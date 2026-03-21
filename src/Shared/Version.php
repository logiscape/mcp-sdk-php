<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2024 Logiscape LLC <https://logiscape.com>
 *
 * Based on the Python SDK for the Model Context Protocol
 * https://github.com/modelcontextprotocol/python-sdk
 *
 * PHP conversion developed by:
 * - Josh Abbott
 * - Claude 3.5 Sonnet (Anthropic AI model)
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
 * Filename: Shared/Version.php
 */

declare(strict_types=1);

namespace Mcp\Shared;

/**
 * Provides version constants for the MCP protocol.
 *
 * Aligns with the Python constants:
 * LATEST_PROTOCOL_VERSION = "2024-11-05"
 * SUPPORTED_PROTOCOL_VERSIONS = [1, LATEST_PROTOCOL_VERSION]
 */
class Version {
    public const LATEST_PROTOCOL_VERSION = '2025-11-25';
    public const SUPPORTED_PROTOCOL_VERSIONS = [
        '2024-11-05',
        '2025-03-26',
        '2025-06-18',
        '2025-11-25',
    ];

    /**
     * Feature-to-minimum-version mapping.
     */
    private const FEATURE_VERSIONS = [
        // 2025-03-26
        'audio_content' => '2025-03-26',
        'annotations' => '2025-03-26',
        'tool_annotations' => '2025-03-26',
        'progress_message' => '2025-03-26',
        // 2025-06-18
        'elicitation' => '2025-06-18',
        'structured_content' => '2025-06-18',
        'tool_output_schema' => '2025-06-18',
        'resource_link_content' => '2025-06-18',
        'rich_metadata' => '2025-06-18',
        // 2025-11-25
        'tasks' => '2025-11-25',
        'url_elicitation' => '2025-11-25',
        'sampling_with_tools' => '2025-11-25',
        'cimd' => '2025-11-25',
    ];

    /**
     * Check if a negotiated protocol version supports a given feature.
     */
    public static function supportsFeature(string $negotiatedVersion, string $feature): bool {
        $minVersion = self::FEATURE_VERSIONS[$feature] ?? null;
        if ($minVersion === null) {
            return false;
        }
        return version_compare($negotiatedVersion, $minVersion, '>=');
    }
}