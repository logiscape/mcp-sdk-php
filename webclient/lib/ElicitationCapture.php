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
 */

declare(strict_types=1);

use Mcp\Types\ElicitationCreateRequest;
use Mcp\Types\ElicitationCreateResult;

/**
 * Synchronous elicitation handler for a web environment.
 *
 * A web request cannot block waiting for a browser form, so this handler
 * records the server's ElicitationCreateRequest in memory and immediately
 * declines. After the blocking callTool() returns, the owning endpoint reads
 * events() and ships the captured schema back to the browser, which renders
 * it as a "server requested elicitation" preview card.
 *
 * Usage (first connect — handler advertises the capability):
 *   $capture = new ElicitationCapture();
 *   $client->onElicit($capture);
 *   $session = $client->connect($url, $headers, $httpOptions);
 *
 * Usage (resumed HTTP session — capability was negotiated earlier):
 *   $capture = new ElicitationCapture();
 *   $session = $client->resumeHttpSession(...);
 *   $session->onElicit($capture);
 *
 * After a tool call:
 *   if ($capture->hasEvents()) {
 *       $response['elicitations'] = $capture->events();
 *   }
 */
final class ElicitationCapture
{
    /** @var list<array{mode: string, message: string, requestedSchema: ?array<string, mixed>, url: ?string, elicitationId: ?string}> */
    private array $events = [];

    public function __invoke(ElicitationCreateRequest $request): ElicitationCreateResult
    {
        $this->events[] = [
            'mode' => $request->mode ?? 'form',
            'message' => $request->message,
            'requestedSchema' => $request->requestedSchema,
            'url' => $request->url,
            'elicitationId' => $request->elicitationId,
        ];
        return new ElicitationCreateResult('decline');
    }

    /**
     * @return list<array{mode: string, message: string, requestedSchema: ?array<string, mixed>, url: ?string, elicitationId: ?string}>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function hasEvents(): bool
    {
        return $this->events !== [];
    }

    public function reset(): void
    {
        $this->events = [];
    }
}
