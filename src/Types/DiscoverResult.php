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
 *
 * Filename: Types/DiscoverResult.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Result of the `server/discover` request (SEP-2575, revision 2026-07-28).
 *
 * Carries what the legacy InitializeResult carried, minus the negotiation:
 * instead of a single negotiated `protocolVersion`, the server advertises
 * every revision it supports in `supportedVersions` and the client picks one.
 * The draft schema additionally makes this a cacheable result (SEP-2549), so
 * `ttlMs` / `cacheScope` are required on the wire under 2026-07-28.
 */
class DiscoverResult extends Result implements CacheableResult {
    use CacheableResultTrait;

    /**
     * @param string[] $supportedVersions Protocol revisions the server supports
     */
    public function __construct(
        public readonly array $supportedVersions,
        public readonly ServerCapabilities $capabilities,
        public readonly Implementation $serverInfo,
        public ?string $instructions = null,
        ?Meta $_meta = null,
    ) {
        parent::__construct($_meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromResponseData(array $data): self {
        $meta = null;
        if (isset($data['_meta'])) {
            $metaData = $data['_meta'];
            unset($data['_meta']);
            $meta = new Meta();
            foreach ($metaData as $k => $v) {
                $meta->$k = $v;
            }
        }

        $supportedVersions = $data['supportedVersions'] ?? [];
        $capabilitiesData = $data['capabilities'] ?? [];
        $serverInfoData = $data['serverInfo'] ?? [];
        $instructions = $data['instructions'] ?? null;
        unset($data['supportedVersions'], $data['capabilities'], $data['serverInfo'], $data['instructions']);

        $capabilities = ServerCapabilities::fromArray($capabilitiesData);
        $serverInfo = Implementation::fromArray($serverInfoData);

        $obj = new self($supportedVersions, $capabilities, $serverInfo, $instructions, $meta);

        // Declared nullable fields (ttlMs, cacheScope, resultType) and extra
        // fields are both handled by direct assignment: declared properties
        // are set normally, everything else lands in extraFields.
        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        parent::validate();
        if (empty($this->supportedVersions)) {
            throw new \InvalidArgumentException('DiscoverResult supportedVersions cannot be empty');
        }
        foreach ($this->supportedVersions as $version) {
            if (!is_string($version) || $version === '') {
                throw new \InvalidArgumentException('DiscoverResult supportedVersions must be non-empty strings');
            }
        }
        $this->capabilities->validate();
        $this->serverInfo->validate();
        $this->validateCacheHints();
    }
}
