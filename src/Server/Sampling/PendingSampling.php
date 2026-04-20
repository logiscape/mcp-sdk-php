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
 * Filename: Server/Sampling/PendingSampling.php
 */

declare(strict_types=1);

namespace Mcp\Server\Sampling;

/**
 * Serializable value object representing a suspended tool call awaiting a
 * `sampling/createMessage` response from the client.
 *
 * Persisted across stateless HTTP request/response cycles so the tool handler
 * can be re-invoked with the prior sampling result preloaded.
 */
class PendingSampling
{
    /**
     * @param array<string, mixed> $toolArguments
     * @param array<int, array<string, mixed>> $previousResults
     * @param array<string, mixed> $originalRequestParams Serialized original tools/call request params (_meta, task, etc.)
     * @param int|string $originalRequestId JSON-RPC id of the originating tools/call (`string | number`).
     */
    public function __construct(
        public readonly string $toolName,
        public readonly array $toolArguments,
        public readonly int|string $originalRequestId,
        public readonly int $serverRequestId,
        public readonly int $samplingSequence,
        public readonly array $previousResults = [],
        public readonly float $createdAt = 0.0,
        public readonly array $originalRequestParams = [],
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
            'samplingSequence' => $this->samplingSequence,
            'previousResults' => $this->previousResults,
            'createdAt' => $this->createdAt ?: microtime(true),
            'originalRequestParams' => $this->originalRequestParams,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // JSON-RPC request ids are `string | number`; preserve the original
        // shape so the resumed response is addressed to the same id the client
        // sent.
        $rawOriginal = $data['originalRequestId'];
        $originalId = is_int($rawOriginal) ? $rawOriginal : (is_string($rawOriginal) ? $rawOriginal : (int) $rawOriginal);

        return new self(
            toolName: $data['toolName'],
            toolArguments: $data['toolArguments'] ?? [],
            originalRequestId: $originalId,
            serverRequestId: (int) $data['serverRequestId'],
            samplingSequence: (int) ($data['samplingSequence'] ?? 0),
            previousResults: $data['previousResults'] ?? [],
            createdAt: (float) ($data['createdAt'] ?? 0.0),
            originalRequestParams: $data['originalRequestParams'] ?? [],
        );
    }
}
