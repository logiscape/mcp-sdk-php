<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * Developed by:
 * - Josh Abbott
 * - Claude Fable 5 (Anthropic AI model)
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
 * Filename: Client/Auth/CrossAppAccessConfiguration.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth;

/**
 * Configuration for the SEP-990 cross-app access flow.
 *
 * Cross-app access lets an enterprise application that already holds an
 * identity provider (IdP) ID token obtain MCP access tokens without an
 * interactive browser flow:
 *
 *   1. RFC 8693 token exchange at the IdP token endpoint: the ID token is
 *      exchanged for an identity assertion JWT authorization grant (ID-JAG)
 *      audienced at the MCP server's authorization server.
 *   2. RFC 7523 jwt-bearer grant at the authorization server token endpoint:
 *      the ID-JAG is presented as the assertion, with the client
 *      authenticating using its registered credentials.
 *
 * When this configuration is present on an OAuthConfiguration, the
 * OAuthClient uses the cross-app access flow instead of the interactive
 * authorization code flow. Pre-registered client credentials (for the
 * authorization server) must also be configured.
 */
class CrossAppAccessConfiguration
{
    /**
     * @param string $idpTokenEndpoint The IdP token endpoint URL where the
     *        RFC 8693 token exchange is performed
     * @param string $idpIdToken The ID token issued by the IdP for the user
     * @param string $idpClientId The client identifier registered with the IdP
     * @param string|null $idpIssuer The IdP issuer identifier (informational)
     */
    public function __construct(
        public readonly string $idpTokenEndpoint,
        public readonly string $idpIdToken,
        public readonly string $idpClientId,
        public readonly ?string $idpIssuer = null
    ) {
    }
}
