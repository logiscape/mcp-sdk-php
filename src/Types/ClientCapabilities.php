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
 * Filename: Types/ClientCapabilities.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Client capabilities
 * 
 * According to schema:
 * ClientCapabilities {
 *   experimental?: { ... },        // handled by parent class
 *   roots?: { listChanged?: bool }, 
 *   sampling?: object
 * }
 * 
 * We have a SamplingCapability class for sampling.
 */
class ClientCapabilities extends Capabilities {
    public function __construct(
        public ?ClientRootsCapability $roots = null,
        public ?SamplingCapability $sampling = null,
        ?ExperimentalCapabilities $experimental = null,
        public ?ElicitationCapability $elicitation = null,
        public ?TaskCapability $tasks = null,
    ) {
        parent::__construct($experimental);
    }

    public static function fromArray(array $data): self {
        $experimental = self::parseExperimental($data);

        $rootsData = $data['roots'] ?? null;
        unset($data['roots']);
        $roots = null;
        if ($rootsData !== null && is_array($rootsData)) {
            $listChanged = $rootsData['listChanged'] ?? null;
            unset($rootsData['listChanged']);
            $roots = new ClientRootsCapability(
                listChanged: $listChanged
            );
            foreach ($rootsData as $k => $v) {
                $roots->$k = $v;
            }
        }

        $samplingData = $data['sampling'] ?? null;
        unset($data['sampling']);
        $sampling = null;
        if ($samplingData !== null) {
            $sampling = new SamplingCapability();
            if (is_array($samplingData)) {
                foreach ($samplingData as $k => $v) {
                    $sampling->$k = $v;
                }
            }
        }

        $elicitationData = $data['elicitation'] ?? null;
        unset($data['elicitation']);
        $elicitation = null;
        if ($elicitationData !== null && is_array($elicitationData)) {
            $elicitation = ElicitationCapability::fromArray($elicitationData);
        }

        $tasksData = $data['tasks'] ?? null;
        unset($data['tasks']);
        $tasks = null;
        if ($tasksData !== null && is_array($tasksData)) {
            $tasks = TaskCapability::fromArray($tasksData);
        }

        $obj = new self(
            roots: $roots,
            sampling: $sampling,
            experimental: $experimental,
            elicitation: $elicitation,
            tasks: $tasks,
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
        if ($this->roots !== null) {
            $this->roots->validate();
        }
        if ($this->sampling !== null) {
            $this->sampling->validate();
        }
        if ($this->elicitation !== null) {
            $this->elicitation->validate();
        }
        if ($this->tasks !== null) {
            $this->tasks->validate();
        }
    }

    public function jsonSerialize(): mixed {
        $data = parent::jsonSerialize();
        if ($this->roots !== null) {
            $data['roots'] = $this->roots;
        }
        if ($this->sampling !== null) {
            $data['sampling'] = $this->sampling;
        }
        if ($this->elicitation !== null) {
            $data['elicitation'] = $this->elicitation;
        }
        if ($this->tasks !== null) {
            $data['tasks'] = $this->tasks;
        }
        return empty($data) ? new \stdClass() : $data;
    }
}