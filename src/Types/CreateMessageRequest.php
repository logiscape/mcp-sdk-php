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
 * Filename: Types/CreateMessageRequest.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * Request to create a message via sampling
 */
class CreateMessageRequest extends Request {
    /**
     * @param SamplingMessage[] $messages
     * @param string[]|null $stopSequences
     * @param Tool[]|null $tools
     */
    public function __construct(
        public readonly array $messages,
        public readonly int $maxTokens,
        public ?array $stopSequences = null,
        public ?string $systemPrompt = null,
        public ?float $temperature = null,
        public ?Meta $metadata = null,
        public ?ModelPreferences $modelPreferences = null,
        public ?string $includeContext = null,
        public ?array $tools = null,
        public ?ToolChoice $toolChoice = null,
        public ?TaskRequestParams $task = null,
        public ?Meta $_meta = null,
    ) {
        $params = new RequestParams(_meta: $_meta);
        $params->messages = $messages;
        $params->maxTokens = $maxTokens;
        if ($stopSequences !== null) {
            $params->stopSequences = $stopSequences;
        }
        if ($systemPrompt !== null) {
            $params->systemPrompt = $systemPrompt;
        }
        if ($temperature !== null) {
            $params->temperature = $temperature;
        }
        if ($metadata !== null) {
            $params->metadata = $metadata;
        }
        if ($modelPreferences !== null) {
            $params->modelPreferences = $modelPreferences;
        }
        if ($includeContext !== null) {
            $params->includeContext = $includeContext;
        }
        if ($tools !== null) {
            $params->tools = $tools;
        }
        if ($toolChoice !== null) {
            $params->toolChoice = $toolChoice;
        }
        if ($task !== null) {
            $params->task = $task;
        }
        parent::__construct('sampling/createMessage', $params);
    }

    public function validate(): void {
        parent::validate();
        if (empty($this->messages)) {
            throw new \InvalidArgumentException('Messages array cannot be empty');
        }
        foreach ($this->messages as $message) {
            if (!$message instanceof SamplingMessage) {
                throw new \InvalidArgumentException('Messages must be instances of SamplingMessage');
            }
            $message->validate();
        }
        if ($this->maxTokens <= 0) {
            throw new \InvalidArgumentException('Max tokens must be greater than 0');
        }
        if ($this->includeContext !== null && !in_array($this->includeContext, ['allServers', 'none', 'thisServer'])) {
            throw new \InvalidArgumentException('Invalid includeContext value');
        }
        if ($this->modelPreferences !== null) {
            $this->modelPreferences->validate();
        }
        // metadata is a Meta object, it's allowed arbitrary fields
        if ($this->metadata !== null) {
            $this->metadata->validate();
        }
        if ($this->tools !== null) {
            foreach ($this->tools as $i => $tool) {
                if (!$tool instanceof Tool) {
                    throw new \InvalidArgumentException(
                        "tools[{$i}] must be an instance of " . Tool::class
                    );
                }
                $tool->validate();
            }
        }
        if ($this->toolChoice !== null) {
            $this->toolChoice->validate();
        }

        $this->validateToolUseBalance();
    }

    /**
     * Enforce the cross-message invariants the 2025-11-25 sampling spec places
     * on tool-use transcripts:
     *
     * - **Tool-result content purity.** A user message that contains any
     *   tool_result block must contain only tool_result blocks. Provider APIs
     *   with dedicated tool-result roles (OpenAI `tool`, Gemini `function`)
     *   reject mixed content.
     *
     * - **Bidirectional adjacent id matching.** An assistant message whose
     *   content includes tool_use blocks must be immediately followed by a
     *   user message whose content consists entirely of tool_result blocks,
     *   and the two id sets must match as multisets — every tool_use.id is
     *   answered by exactly one tool_result.toolUseId and vice versa. Both
     *   anchors are checked: assistant→user (forward) and user→assistant
     *   (backward) so a standalone user tool_result without a valid
     *   predecessor is also caught.
     *
     * Runs unconditionally — the constraint is keyed on message content, not
     * on whether the current request's `tools` field is set, because a
     * transcript replay may include tool_use history without re-offering
     * tools.
     */
    private function validateToolUseBalance(): void {
        $messages = array_values($this->messages);
        $count = count($messages);

        // Tool-result content purity: a user message with any tool_result block
        // must contain only tool_result blocks.
        foreach ($messages as $i => $msg) {
            if ($msg->role !== Role::USER) {
                continue;
            }
            $blocks = is_array($msg->content) ? $msg->content : [$msg->content];
            $hasResult = false;
            $hasNonResult = false;
            foreach ($blocks as $block) {
                if ($block instanceof ToolResultContent) {
                    $hasResult = true;
                } else {
                    $hasNonResult = true;
                }
            }
            if ($hasResult && $hasNonResult) {
                throw new \InvalidArgumentException(
                    "User message at index {$i} mixes tool_result with other content types; "
                    . 'per MCP spec, a user message containing tool_result blocks must contain only tool_results.'
                );
            }
        }

        // Forward anchor: every assistant message with tool_use blocks is
        // followed by a user message whose tool_results match the ids as a
        // multiset (exactly one tool_result per tool_use, no extras).
        for ($i = 0; $i < $count; $i++) {
            $msg = $messages[$i];
            if ($msg->role !== Role::ASSISTANT) {
                continue;
            }
            $blocks = is_array($msg->content) ? $msg->content : [$msg->content];
            $toolUseIds = [];
            foreach ($blocks as $block) {
                if ($block instanceof ToolUseContent) {
                    $toolUseIds[] = $block->id;
                }
            }
            if (empty($toolUseIds)) {
                continue;
            }

            // Uniqueness: tool_use.id is a unique identifier per the schema.
            // Duplicate ids in the same assistant message make the
            // tool_use → tool_result mapping ambiguous for provider-native
            // tool APIs, so reject before the paired-ids comparison below.
            if (count($toolUseIds) !== count(array_unique($toolUseIds))) {
                foreach (array_count_values($toolUseIds) as $id => $occurrences) {
                    if ($occurrences > 1) {
                        throw new \InvalidArgumentException(
                            "tool_use id '{$id}' appears {$occurrences} times in assistant message "
                            . "index {$i}; tool_use ids must be unique within a message."
                        );
                    }
                }
            }

            if ($i + 1 >= $count) {
                throw new \InvalidArgumentException(
                    "Assistant message at index {$i} contains tool_use blocks but is not followed by a user "
                    . 'message with matching tool_results.'
                );
            }
            $next = $messages[$i + 1];
            if ($next->role !== Role::USER) {
                throw new \InvalidArgumentException(
                    "Assistant message at index {$i} has tool_use blocks; the following message must be a user "
                    . 'message with matching tool_results.'
                );
            }

            $nextBlocks = is_array($next->content) ? $next->content : [$next->content];
            $resolvedIds = [];
            foreach ($nextBlocks as $block) {
                if (!$block instanceof ToolResultContent) {
                    throw new \InvalidArgumentException(
                        'User message at index ' . ($i + 1) . ' must consist entirely of tool_result blocks '
                        . "(follow-up to assistant tool_use at index {$i})."
                    );
                }
                $resolvedIds[] = $block->toolUseId;
            }
            // Check both directions so missing results and unsolicited result
            // ids are both reported with specific error messages before the
            // multiset-equality check below.
            foreach ($toolUseIds as $id) {
                if (!in_array($id, $resolvedIds, true)) {
                    throw new \InvalidArgumentException(
                        "tool_use id '{$id}' (assistant message index {$i}) has no matching tool_result "
                        . 'in the following user message.'
                    );
                }
            }
            foreach ($resolvedIds as $resultId) {
                if (!in_array($resultId, $toolUseIds, true)) {
                    throw new \InvalidArgumentException(
                        "tool_result toolUseId '{$resultId}' (user message index " . ($i + 1) . ') does not match '
                        . "any tool_use in the preceding assistant message (index {$i})."
                    );
                }
            }

            // Multiset equality (exact 1:1 pairing) — set membership alone would
            // accept duplicate result ids answering a single tool_use.
            $useCount = array_count_values($toolUseIds);
            $resultCount = array_count_values($resolvedIds);
            ksort($useCount);
            ksort($resultCount);
            if ($useCount !== $resultCount) {
                foreach ($useCount as $id => $count) {
                    $otherCount = $resultCount[$id] ?? 0;
                    if ($count !== $otherCount) {
                        throw new \InvalidArgumentException(
                            "tool_use id '{$id}' (assistant message index {$i}) is referenced by "
                            . "{$otherCount} tool_result block(s) in the following user message but expected "
                            . "{$count} — exactly one tool_result per tool_use is required."
                        );
                    }
                }
            }
        }

        // Backward anchor: every user message containing tool_result blocks is
        // immediately preceded by an assistant message with tool_use blocks.
        // (The id-set equality for any such adjacent pair is already covered by
        // the forward-anchored pass above.)
        foreach ($messages as $i => $msg) {
            if ($msg->role !== Role::USER) {
                continue;
            }
            $blocks = is_array($msg->content) ? $msg->content : [$msg->content];
            $hasResult = false;
            foreach ($blocks as $block) {
                if ($block instanceof ToolResultContent) {
                    $hasResult = true;
                    break;
                }
            }
            if (!$hasResult) {
                continue;
            }

            if ($i === 0) {
                throw new \InvalidArgumentException(
                    'User message at index 0 contains tool_result blocks but has no preceding '
                    . 'assistant tool_use message.'
                );
            }
            $prev = $messages[$i - 1];
            if ($prev->role !== Role::ASSISTANT) {
                throw new \InvalidArgumentException(
                    "User message at index {$i} contains tool_result blocks; the preceding message "
                    . 'must be an assistant tool_use message.'
                );
            }
            $prevBlocks = is_array($prev->content) ? $prev->content : [$prev->content];
            $prevHasToolUse = false;
            foreach ($prevBlocks as $block) {
                if ($block instanceof ToolUseContent) {
                    $prevHasToolUse = true;
                    break;
                }
            }
            if (!$prevHasToolUse) {
                throw new \InvalidArgumentException(
                    "User message at index {$i} contains tool_result blocks but preceding assistant "
                    . 'message (index ' . ($i - 1) . ') has no tool_use blocks.'
                );
            }
        }
    }
}
