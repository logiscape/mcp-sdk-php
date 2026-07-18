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
 *
 * Since spec PR #3002 the server's identity is no longer a top-level field:
 * it rides `_meta["io.modelcontextprotocol/serverInfo"]` like every other
 * result's, and it is optional — an anonymous server is valid. Read it via
 * {@see getServerInfo()}.
 */
class DiscoverResult extends Result implements CacheableResult {
    use CacheableResultTrait;

    /**
     * @param string[] $supportedVersions Protocol revisions the server supports
     */
    public function __construct(
        public readonly array $supportedVersions,
        public readonly ServerCapabilities $capabilities,
        public ?string $instructions = null,
        ?Meta $_meta = null,
    ) {
        parent::__construct($_meta);
    }

    /**
     * The server identity carried in `_meta["io.modelcontextprotocol/serverInfo"]`,
     * or null when the server is anonymous or the value is malformed.
     *
     * Lenient by design: the field is self-reported, unverified, and
     * display-only per the spec, so a malformed value is treated as absent
     * rather than failing the result. A valid value is parsed in full —
     * optional Implementation metadata (title, description, icons,
     * websiteUrl) and extension fields survive, not just name/version.
     */
    public function getServerInfo(): ?Implementation {
        $value = $this->_meta?->getField(MetaKeys::SERVER_INFO);
        if ($value instanceof Implementation) {
            return $value;
        }
        if ($value instanceof \stdClass) {
            // Deep-normalize: nested structures (e.g. icons) may also be
            // stdClass when the result came off the wire.
            $value = json_decode((string) json_encode($value), true);
        }
        if (!is_array($value)) {
            return null;
        }
        try {
            return Implementation::fromArray($value);
        } catch (\Throwable $e) {
            // Missing/empty name or version, or an unparseable shape:
            // treated as absent, never fatal.
            return null;
        }
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
        $instructions = $data['instructions'] ?? null;
        // A top-level `serverInfo` is not a spec field since PR #3002.
        // Discard a stray one (pre-#3002 peers) rather than letting it land
        // in extraFields and be re-emitted on serialization; identity is
        // read exclusively from `_meta`.
        unset($data['supportedVersions'], $data['capabilities'], $data['serverInfo'], $data['instructions']);

        $capabilities = ServerCapabilities::fromArray($capabilitiesData);

        $obj = new self($supportedVersions, $capabilities, $instructions, $meta);

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
        $this->validateCacheHints();
    }
}
