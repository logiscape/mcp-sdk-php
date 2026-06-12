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
 * Filename: Types/SubscriptionFilter.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * The notification filter of a `subscriptions/listen` request (SEP-2575,
 * revision 2026-07-28).
 *
 * Each field opts in to exactly one change-notification type; omission means
 * not subscribed, and the server MUST NOT send notification types the client
 * has not requested. `resourceSubscriptions` lists resource URIs whose
 * `notifications/resources/updated` events the client wants — it replaces
 * the removed `resources/subscribe` RPC.
 */
class SubscriptionFilter implements McpModel {
    use ExtraFieldsTrait;

    /**
     * @param string[]|null $resourceSubscriptions
     */
    public function __construct(
        public ?bool $toolsListChanged = null,
        public ?bool $promptsListChanged = null,
        public ?bool $resourcesListChanged = null,
        public ?array $resourceSubscriptions = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $uris = null;
        if (isset($data['resourceSubscriptions']) && is_array($data['resourceSubscriptions'])) {
            $uris = array_values(array_filter($data['resourceSubscriptions'], 'is_string'));
        }
        $filter = new self(
            toolsListChanged: is_bool($data['toolsListChanged'] ?? null) ? $data['toolsListChanged'] : null,
            promptsListChanged: is_bool($data['promptsListChanged'] ?? null) ? $data['promptsListChanged'] : null,
            resourcesListChanged: is_bool($data['resourcesListChanged'] ?? null) ? $data['resourcesListChanged'] : null,
            resourceSubscriptions: $uris,
        );
        foreach ($data as $key => $value) {
            if (!in_array($key, ['toolsListChanged', 'promptsListChanged', 'resourcesListChanged', 'resourceSubscriptions'], true)) {
                $filter->$key = $value;
            }
        }
        return $filter;
    }

    /**
     * Whether a server notification passes this filter.
     *
     * @param string $method The notification method
     * @param string|null $uri For notifications/resources/updated, the
     *        resource URI the event concerns
     */
    public function wants(string $method, ?string $uri = null): bool {
        return match ($method) {
            'notifications/tools/list_changed' => $this->toolsListChanged === true,
            'notifications/prompts/list_changed' => $this->promptsListChanged === true,
            'notifications/resources/list_changed' => $this->resourcesListChanged === true,
            'notifications/resources/updated' => $uri !== null
                && is_array($this->resourceSubscriptions)
                && in_array($uri, $this->resourceSubscriptions, true),
            default => false,
        };
    }

    /**
     * The subset of this filter a server with the given capabilities agrees
     * to honor — the shape echoed back in
     * `notifications/subscriptions/acknowledged` (unsupported types are
     * omitted, not refused).
     *
     * The list_changed types are gated on the corresponding capability
     * flags. `resourceSubscriptions` is gated on
     * $resourceUpdatesDeliverable — whether the serving transport can
     * actually deliver `notifications/resources/updated` to this stream
     * (the modern replacement for the removed resources/subscribe RPC) —
     * NOT on the legacy `resources.subscribe` capability, which describes
     * the pre-2026 RPC surface and may differ.
     */
    public function intersectWithCapabilities(
        ?ServerCapabilities $capabilities,
        bool $resourceUpdatesDeliverable = false
    ): self {
        $tools = $this->toolsListChanged === true
            && ($capabilities->tools->listChanged ?? false) === true;
        $prompts = $this->promptsListChanged === true
            && ($capabilities->prompts->listChanged ?? false) === true;
        $resources = $this->resourcesListChanged === true
            && ($capabilities->resources->listChanged ?? false) === true;

        return new self(
            toolsListChanged: $tools ? true : null,
            promptsListChanged: $prompts ? true : null,
            resourcesListChanged: $resources ? true : null,
            resourceSubscriptions: ($resourceUpdatesDeliverable && !empty($this->resourceSubscriptions))
                ? $this->resourceSubscriptions
                : null,
        );
    }

    public function isEmpty(): bool {
        return $this->toolsListChanged !== true
            && $this->promptsListChanged !== true
            && $this->resourcesListChanged !== true
            && empty($this->resourceSubscriptions);
    }

    public function validate(): void {
        // All fields optional; nothing mandatory to enforce.
    }

    public function jsonSerialize(): mixed {
        $data = [];
        if ($this->toolsListChanged !== null) {
            $data['toolsListChanged'] = $this->toolsListChanged;
        }
        if ($this->promptsListChanged !== null) {
            $data['promptsListChanged'] = $this->promptsListChanged;
        }
        if ($this->resourcesListChanged !== null) {
            $data['resourcesListChanged'] = $this->resourcesListChanged;
        }
        if ($this->resourceSubscriptions !== null) {
            $data['resourceSubscriptions'] = $this->resourceSubscriptions;
        }
        if (!empty($this->extraFields)) {
            $data = array_merge($data, $this->extraFields);
        }
        return !empty($data) ? $data : new \stdClass();
    }
}
