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
 * Filename: Types/InitializeResult.php
 */

declare(strict_types=1);

namespace Mcp\Types;

class InitializeResult extends Result {
    /**
     * `$serverInfo` is nullable only for the modern (2026-07-28) era, where
     * this object doubles as the session's initialization result: since spec
     * PR #3002 a modern server's identity is an optional result-`_meta`
     * field, so an anonymous server yields null here rather than a
     * fabricated Implementation. The legacy initialize handshake still
     * requires serverInfo on the wire — ClientSession::initialize() rejects
     * a legacy result without it.
     */
    public function __construct(
        public readonly ServerCapabilities $capabilities,
        public readonly ?Implementation $serverInfo,
        public readonly string $protocolVersion,
        public ?string $instructions = null,
        ?Meta $_meta = null,
    ) {
        parent::__construct($_meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromResponseData(array $data): self {
        // Extract _meta
        $meta = null;
        if (isset($data['_meta'])) {
            $metaData = $data['_meta'];
            unset($data['_meta']);
            $meta = new Meta();
            foreach ($metaData as $k => $v) {
                $meta->$k = $v;
            }
        }

        // Extract known fields
        $protocolVersion = $data['protocolVersion'] ?? '';
        $capabilitiesData = $data['capabilities'] ?? [];
        $serverInfoData = $data['serverInfo'] ?? null;
        $instructions = $data['instructions'] ?? null;

        unset($data['protocolVersion'], $data['capabilities'], $data['serverInfo'], $data['instructions']);

        // Construct nested objects. serverInfo may be absent: a persisted
        // modern-era session with an anonymous server round-trips through
        // here on resume (Client::resumeHttpSession). A present value must
        // still parse as a well-formed Implementation. The legacy handshake
        // path enforces presence separately (ClientSession::initialize()).
        $capabilities = ServerCapabilities::fromArray($capabilitiesData);
        $serverInfo = $serverInfoData === null ? null : Implementation::fromArray($serverInfoData);

        $obj = new self($capabilities, $serverInfo, $protocolVersion, $instructions, $meta);

        // Extra fields
        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        parent::validate();
        $this->capabilities->validate();
        $this->serverInfo?->validate();
        if (empty($this->protocolVersion)) {
            throw new \InvalidArgumentException('Protocol version cannot be empty');
        }
    }

    public function jsonSerialize(): mixed {
        $data = parent::jsonSerialize();
        $data['capabilities'] = $this->capabilities;
        if ($this->serverInfo !== null) {
            $data['serverInfo'] = $this->serverInfo;
        }
        $data['protocolVersion'] = $this->protocolVersion;
        if ($this->instructions !== null) {
            $data['instructions'] = $this->instructions;
        }
        return $data;
    }
}