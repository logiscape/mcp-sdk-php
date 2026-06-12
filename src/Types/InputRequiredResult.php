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
 * Filename: Types/InputRequiredResult.php
 */

declare(strict_types=1);

namespace Mcp\Types;

/**
 * SEP-2322 multi-round-trip result (revision 2026-07-28).
 *
 * Returned instead of the normal result when the server needs client-side
 * input (elicitation, sampling, roots) before it can complete a
 * `tools/call`, `prompts/get`, or `resources/read` request — the only
 * three methods allowed to return it. This TERMINATES the original
 * JSON-RPC request: the client fulfills `inputRequests`, then retries the
 * same method with the same params plus `inputResponses` (keyed
 * identically) and the verbatim `requestState`, under a NEW request id.
 *
 * At least one of `inputRequests` / `requestState` must be present.
 * `requestState` is opaque to the client and attacker-controlled from the
 * server's perspective — this SDK signs it (see
 * {@see \Mcp\Server\InputRequired\RequestStateCodec}).
 */
class InputRequiredResult extends Result {
    public const RESULT_TYPE_INPUT_REQUIRED = 'input_required';

    /**
     * @param array<string, Request>|null $inputRequests Pending input
     *        requests keyed by the names the retry's inputResponses must
     *        echo
     * @param string|null $requestState Opaque state echoed verbatim on the
     *        retry
     */
    public function __construct(
        public ?array $inputRequests = null,
        public ?string $requestState = null,
        ?Meta $_meta = null,
    ) {
        parent::__construct($_meta);
        $this->resultType = self::RESULT_TYPE_INPUT_REQUIRED;
    }

    public function validate(): void {
        if (($this->inputRequests === null || $this->inputRequests === [])
            && ($this->requestState === null || $this->requestState === '')
        ) {
            throw new \InvalidArgumentException(
                'InputRequiredResult requires at least one of inputRequests or requestState'
            );
        }
    }

    public function jsonSerialize(): mixed {
        $data = parent::jsonSerialize();
        $data = $data instanceof \stdClass ? (array) $data : (is_array($data) ? $data : []);

        if ($this->inputRequests !== null && $this->inputRequests !== []) {
            $requests = [];
            foreach ($this->inputRequests as $key => $request) {
                $requests[$key] = [
                    'method' => $request->method,
                    'params' => $request->params !== null
                        ? $request->params->jsonSerialize()
                        : new \stdClass(),
                ];
            }
            $data['inputRequests'] = $requests;
        }
        if ($this->requestState !== null) {
            $data['requestState'] = $this->requestState;
        }

        return $data;
    }
}
