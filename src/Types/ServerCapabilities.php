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
 * Filename: Types/ServerCapabilities.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Server capabilities
 * According to the schema:
 * ServerCapabilities {
 *   experimental?: { ... },
 *   logging?: object,
 *   completions?: object,
 *   prompts?: { listChanged?: boolean },
 *   resources?: { subscribe?: boolean, listChanged?: boolean },
 *   tools?: { listChanged?: boolean },
 *   extensions?: { [extensionId: string]: object },
 *   [key: string]: unknown
 * }
 *
 * `extensions` is the SEP-2133 extension-declaration map (revision
 * 2026-07-28): each key is a reverse-DNS extension id (see
 * {@see ExtensionIds}) whose value is an object of extension-specific
 * settings; the empty object `{}` means "supported, no settings". The Tasks
 * extension (SEP-2663) is declared here rather than via a dedicated
 * capability slot.
 */
class ServerCapabilities extends Capabilities {
    /**
     * @param array<string, mixed>|null $extensions SEP-2133 extension map
     */
    public function __construct(
        /**
         * @deprecated Deprecated as of protocol version 2026-07-28
         *             (SEP-2577 deprecates the Logging feature). The
         *             capability keeps negotiating unchanged for at least
         *             twelve months; see the deprecated features registry.
         */
        public ?ServerLoggingCapability $logging = null,
        public ?ServerCompletionsCapability $completions = null,
        public ?ServerPromptsCapability $prompts = null,
        public ?ServerResourcesCapability $resources = null,
        public ?ServerToolsCapability $tools = null,
        ?ExperimentalCapabilities $experimental = null,
        public ?array $extensions = null,
    ) {
        parent::__construct($experimental);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        // Handle experimental from parent class
        $experimental = self::parseExperimental($data);

        $loggingData = $data['logging'] ?? null;
        unset($data['logging']);
        $logging = null;
        if ($loggingData !== null && is_array($loggingData)) {
            $logging = ServerLoggingCapability::fromArray($loggingData);
        }

        $completionsData = $data['completions'] ?? null;
        unset($data['completions']);
        $completions = null;
        if ($completionsData !== null && is_array($completionsData)) {
            $completions = ServerCompletionsCapability::fromArray($completionsData);
        }

        $promptsData = $data['prompts'] ?? null;
        unset($data['prompts']);
        $prompts = null;
        if ($promptsData !== null && is_array($promptsData)) {
            $prompts = ServerPromptsCapability::fromArray($promptsData);
        }

        $resourcesData = $data['resources'] ?? null;
        unset($data['resources']);
        $resources = null;
        if ($resourcesData !== null && is_array($resourcesData)) {
            $resources = ServerResourcesCapability::fromArray($resourcesData);
        }

        $toolsData = $data['tools'] ?? null;
        unset($data['tools']);
        $tools = null;
        if ($toolsData !== null && is_array($toolsData)) {
            $tools = ServerToolsCapability::fromArray($toolsData);
        }

        $extensions = self::parseExtensions($data);
        unset($data['extensions']);

        $obj = new self(
            logging: $logging,
            completions: $completions,
            prompts: $prompts,
            resources: $resources,
            tools: $tools,
            experimental: $experimental,
            extensions: $extensions
        );

        // Extra fields
        foreach ($data as $k => $v) {
            $obj->$k = $v;
        }

        $obj->validate();
        return $obj;
    }

    public function validate(): void {
        parent::validate();
        if ($this->prompts !== null) {
            $this->prompts->validate();
        }
        if ($this->resources !== null) {
            $this->resources->validate();
        }
        if ($this->tools !== null) {
            $this->tools->validate();
        }
        if ($this->logging !== null) {
            $this->logging->validate();
        }
        if ($this->completions !== null) {
            $this->completions->validate();
        }
    }

    public function jsonSerialize(): mixed {
        $data = parent::jsonSerialize();
        if ($this->logging !== null) {
            $data['logging'] = $this->logging;
        }
        if ($this->completions !== null) {
            $data['completions'] = $this->completions;
        }
        if ($this->prompts !== null) {
            $data['prompts'] = $this->prompts;
        }
        if ($this->resources !== null) {
            $data['resources'] = $this->resources;
        }
        if ($this->tools !== null) {
            $data['tools'] = $this->tools;
        }
        if ($this->extensions !== null) {
            $data['extensions'] = self::serializeExtensions($this->extensions);
        }
        return $data;
    }

    /**
     * Parse the SEP-2133 `extensions` map from decoded capability data,
     * normalizing each entry to an associative array (the empty object `{}`
     * becomes `[]`). Returns null when absent.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public static function parseExtensions(array $data): ?array {
        $extensionsData = $data['extensions'] ?? null;
        if (!is_array($extensionsData)) {
            return null;
        }
        $extensions = [];
        foreach ($extensionsData as $id => $settings) {
            if (is_object($settings)) {
                $settings = (array) $settings;
            }
            // SEP-2133: an extension's value MUST be a settings OBJECT (the
            // empty object `{}` = supported, no settings). Anything that is
            // not object-shaped is a malformed declaration and does NOT count
            // as opting in — skip it so a malformed map can never unlock a
            // feature. After json_decode(assoc), a JSON object becomes an
            // associative array and the empty object `{}` becomes `[]`, but a
            // JSON array (`[1]`) also becomes a PHP array; reject the latter
            // (a non-empty list is unambiguously a JSON array, not an object).
            if (!self::isObjectShaped($settings)) {
                continue;
            }
            $extensions[(string) $id] = $settings;
        }
        return $extensions;
    }

    /**
     * Whether a json_decode(assoc) value represents a JSON object: an
     * associative array, or the empty array (the empty object `{}` decodes to
     * `[]`). A non-empty list array is a JSON array, not an object.
     */
    private static function isObjectShaped(mixed $value): bool {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * Serialize an `extensions` map for the wire: every value becomes a JSON
     * object. Empty settings and any non-object-shaped value serialize as
     * `{}` (never `[]` or a JSON array); an object-shaped associative array
     * already encodes as a JSON object.
     *
     * @param array<string, mixed> $extensions
     * @return array<string, mixed>
     */
    public static function serializeExtensions(array $extensions): array {
        $result = [];
        foreach ($extensions as $id => $settings) {
            $result[$id] = (is_array($settings) && $settings !== [] && !array_is_list($settings))
                ? $settings
                : new \stdClass();
        }
        return $result;
    }
}