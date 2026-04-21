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

use Mcp\Client\Auth\Callback\AuthorizationCallbackInterface;
use Mcp\Client\Auth\Exception\AuthorizationRedirectException;

/**
 * Web-based OAuth callback handler for the webclient.
 *
 * Unlike LoopbackCallbackHandler which creates a local HTTP server,
 * this handler works with browser redirects in a web hosting environment.
 *
 * The authorize() method throws an AuthorizationRedirectException with all
 * the information needed for the web application to redirect the user to
 * the authorization server.
 */
class WebCallbackHandler implements AuthorizationCallbackInterface
{
    private string $callbackUrl;

    /**
     * @param string $callbackUrl The full URL to oauth_callback.php
     */
    public function __construct(string $callbackUrl)
    {
        $this->callbackUrl = $callbackUrl;
    }

    /**
     * {@inheritdoc}
     *
     * In web context, this method cannot complete synchronously because
     * authorization requires a browser redirect. Instead, it throws an
     * AuthorizationRedirectException with the authorization URL and state
     * for the webclient to redirect the user.
     *
     * @throws AuthorizationRedirectException Always throws with authorization URL
     */
    public function authorize(string $authUrl, string $state): string
    {
        // In web hosting context, we can't wait synchronously for the callback.
        // The webclient must redirect the browser to the authorization URL
        // and handle the callback in oauth_callback.php.
        throw new AuthorizationRedirectException(
            $authUrl,
            $state,
            $this->callbackUrl
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getRedirectUri(): string
    {
        return $this->callbackUrl;
    }
}
