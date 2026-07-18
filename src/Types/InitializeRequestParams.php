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
 * Filename: Types/InitializeRequestParams.php
 */

declare(strict_types=1);

namespace Mcp\Types;

class InitializeRequestParams extends RequestParams {
    /**
     * `$clientInfo` is nullable only for the modern (2026-07-28) per-request
     * adoption path: since spec PR #3002 the `_meta` envelope's clientInfo is
     * a SHOULD, so a spec-valid modern request may carry no client identity —
     * the server stores null rather than fabricating one. The legacy
     * `initialize` request still requires clientInfo on the wire: its parse
     * path (ClientRequest) rejects a missing value before construction, and
     * validate() below enforces it for directly constructed instances.
     */
    public function __construct(
        public readonly string $protocolVersion,
        public readonly ClientCapabilities $capabilities,
        public readonly ?Implementation $clientInfo,
        ?Meta $_meta = null
    ) {
        // Call parent constructor, passing $_meta if needed. If you don't have meta for Initialize, you can just pass null.
        parent::__construct($_meta);
    }

    public function validate(): void {
        // First call parent to validate _meta if present
        parent::validate();

        if (empty($this->protocolVersion)) {
            throw new \InvalidArgumentException('Protocol version cannot be empty');
        }
        $this->capabilities->validate();
        if ($this->clientInfo === null) {
            // Only the modern envelope may omit client identity; a legacy
            // initialize request without clientInfo is malformed.
            throw new \InvalidArgumentException('clientInfo is required on the initialize request');
        }
        $this->clientInfo->validate();
    }

    public function jsonSerialize(): mixed {
        $data = [
            'protocolVersion' => $this->protocolVersion,
            'capabilities' => $this->capabilities,
        ];
        if ($this->clientInfo !== null) {
            $data['clientInfo'] = $this->clientInfo;
        }

        // Merge with extra fields from parent (ExtraFieldsTrait)
        return array_merge($data, $this->extraFields);
    }
}