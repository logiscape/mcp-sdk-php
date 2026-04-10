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
 * Filename: Server/Elicitation/PendingElicitation.php
 */

declare(strict_types=1);

namespace Mcp\Server\Elicitation;

/**
 * Serializable value object representing a suspended tool call awaiting
 * an elicitation response from the client.
 *
 * Used in the HTTP transport's suspend/resume pattern to persist state
 * across stateless HTTP request/response cycles.
 */
class PendingElicitation
{
    /**
     * @param array<string, mixed> $toolArguments
     * @param array<int, array<string, mixed>> $previousResults
     */
    public function __construct(
        public readonly string $toolName,
        public readonly array $toolArguments,
        public readonly int $originalRequestId,
        public readonly int $serverRequestId,
        public readonly int $elicitationSequence,
        public readonly array $previousResults = [],
        public readonly float $createdAt = 0.0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'toolName' => $this->toolName,
            'toolArguments' => $this->toolArguments,
            'originalRequestId' => $this->originalRequestId,
            'serverRequestId' => $this->serverRequestId,
            'elicitationSequence' => $this->elicitationSequence,
            'previousResults' => $this->previousResults,
            'createdAt' => $this->createdAt ?: microtime(true),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            toolName: $data['toolName'],
            toolArguments: $data['toolArguments'] ?? [],
            originalRequestId: (int) $data['originalRequestId'],
            serverRequestId: (int) $data['serverRequestId'],
            elicitationSequence: (int) ($data['elicitationSequence'] ?? 0),
            previousResults: $data['previousResults'] ?? [],
            createdAt: (float) ($data['createdAt'] ?? 0.0),
        );
    }
}
