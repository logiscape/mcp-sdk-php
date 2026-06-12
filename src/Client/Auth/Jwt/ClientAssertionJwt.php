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
 * Filename: Client/Auth/Jwt/ClientAssertionJwt.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth\Jwt;

use Mcp\Client\Auth\OAuthException;

/**
 * Builder for RFC 7523 JWT client assertions (private_key_jwt).
 *
 * Produces a signed JWT suitable for the client_assertion token request
 * parameter with client_assertion_type
 * urn:ietf:params:oauth:client-assertion-type:jwt-bearer.
 *
 * Claims per RFC 7523 Section 3:
 *   - iss/sub: the client_id
 *   - aud: the authorization server issuer identifier (base URL)
 *   - exp/iat: issued-at and expiry
 *   - jti: unique token identifier
 *
 * Supported algorithms: RS256/RS384/RS512 (RSASSA-PKCS1-v1_5) and
 * ES256/ES384/ES512 (ECDSA). For ECDSA, openssl produces DER-encoded
 * signatures which are converted to the raw concatenated R||S format
 * required by JOSE (RFC 7518 Section 3.4).
 */
final class ClientAssertionJwt
{
    /**
     * The client_assertion_type value for JWT bearer client assertions.
     */
    public const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    /**
     * Map of supported JWS algorithms to [openssl digest algorithm, ECDSA part length].
     * A part length of null indicates an RSA algorithm (no DER conversion needed).
     *
     * @var array<string, array{int, int|null}>
     */
    private const ALGORITHMS = [
        'RS256' => [OPENSSL_ALGO_SHA256, null],
        'RS384' => [OPENSSL_ALGO_SHA384, null],
        'RS512' => [OPENSSL_ALGO_SHA512, null],
        'ES256' => [OPENSSL_ALGO_SHA256, 32],
        'ES384' => [OPENSSL_ALGO_SHA384, 48],
        'ES512' => [OPENSSL_ALGO_SHA512, 66],
    ];

    /**
     * Create a signed JWT client assertion.
     *
     * @param string $clientId The OAuth client identifier (used as iss and sub)
     * @param string $audience The intended audience — the authorization server
     *        issuer identifier (base URL), NOT the token endpoint
     * @param string $privateKeyPem PEM-encoded private key (PKCS#8 or traditional)
     * @param string $algorithm JWS algorithm (RS256/RS384/RS512/ES256/ES384/ES512)
     * @param int $lifetimeSeconds Assertion validity window in seconds
     * @return string The compact-serialized signed JWT
     * @throws OAuthException If the algorithm is unsupported or signing fails
     */
    public static function create(
        string $clientId,
        string $audience,
        string $privateKeyPem,
        string $algorithm = 'ES256',
        int $lifetimeSeconds = 300
    ): string {
        if (!isset(self::ALGORITHMS[$algorithm])) {
            throw new OAuthException(
                "Unsupported JWT signing algorithm: {$algorithm}. Supported: "
                . implode(', ', array_keys(self::ALGORITHMS))
            );
        }
        [$digest, $ecdsaPartLength] = self::ALGORITHMS[$algorithm];

        $now = time();
        $header = [
            'alg' => $algorithm,
            'typ' => 'JWT',
        ];
        $claims = [
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + $lifetimeSeconds,
            'jti' => bin2hex(random_bytes(16)),
        ];

        $signingInput = self::base64UrlEncode(self::jsonEncode($header))
            . '.'
            . self::base64UrlEncode(self::jsonEncode($claims));

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new OAuthException(
                'Failed to load private key for JWT client assertion: '
                . (openssl_error_string() ?: 'invalid PEM')
            );
        }

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $key, $digest)) {
            throw new OAuthException(
                'Failed to sign JWT client assertion: '
                . (openssl_error_string() ?: 'openssl_sign failed')
            );
        }

        if ($ecdsaPartLength !== null) {
            // openssl emits ECDSA signatures in ASN.1 DER; JOSE requires the
            // raw fixed-length R||S concatenation (RFC 7518 Section 3.4).
            $signature = self::derSignatureToConcatenated($signature, $ecdsaPartLength);
        }

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Convert a DER-encoded ECDSA signature (SEQUENCE of two INTEGERs) to the
     * raw concatenated R||S format required by JOSE.
     *
     * @param string $der The DER-encoded signature
     * @param int $partLength The byte length of each of R and S for the curve
     * @return string The raw concatenated signature (2 * $partLength bytes)
     * @throws OAuthException If the DER structure is malformed
     */
    public static function derSignatureToConcatenated(string $der, int $partLength): string
    {
        $offset = 0;

        // SEQUENCE tag
        $tag = self::readDerByte($der, $offset);
        if ($tag !== 0x30) {
            throw new OAuthException('Malformed DER ECDSA signature: missing SEQUENCE tag');
        }

        // SEQUENCE length (value unused; reading advances past it)
        self::readDerLength($der, $offset);

        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            // INTEGER tag
            $tag = self::readDerByte($der, $offset);
            if ($tag !== 0x02) {
                throw new OAuthException('Malformed DER ECDSA signature: missing INTEGER tag');
            }

            $intLength = self::readDerLength($der, $offset);
            if ($offset + $intLength > strlen($der)) {
                throw new OAuthException('Malformed DER ECDSA signature: INTEGER overruns data');
            }
            $value = substr($der, $offset, $intLength);
            $offset += $intLength;

            // Strip leading zero padding bytes added for DER sign encoding,
            // then left-pad to the fixed curve length.
            $value = ltrim($value, "\x00");
            if (strlen($value) > $partLength) {
                throw new OAuthException('Malformed DER ECDSA signature: INTEGER too large for curve');
            }
            $parts[] = str_pad($value, $partLength, "\x00", STR_PAD_LEFT);
        }

        return $parts[0] . $parts[1];
    }

    /**
     * Read a single byte from a DER buffer, advancing the offset.
     *
     * @param string $der The DER buffer
     * @param int $offset The current offset (advanced by reference)
     * @return int The byte value (0-255)
     * @throws OAuthException If the buffer is exhausted
     */
    private static function readDerByte(string $der, int &$offset): int
    {
        if ($offset >= strlen($der)) {
            throw new OAuthException('Malformed DER ECDSA signature: unexpected end of data');
        }

        return ord($der[$offset++]);
    }

    /**
     * Read a DER length field (short or long form), advancing the offset.
     *
     * @param string $der The DER buffer
     * @param int $offset The current offset (advanced by reference)
     * @return int The decoded length
     * @throws OAuthException If the buffer is exhausted
     */
    private static function readDerLength(string $der, int &$offset): int
    {
        $length = self::readDerByte($der, $offset);
        if (($length & 0x80) === 0) {
            return $length;
        }

        $lengthBytes = $length & 0x7F;
        $length = 0;
        for ($i = 0; $i < $lengthBytes; $i++) {
            $length = ($length << 8) | self::readDerByte($der, $offset);
        }

        return $length;
    }

    /**
     * Base64url-encode without padding per RFC 7515.
     *
     * @param string $data The raw bytes to encode
     * @return string The base64url-encoded string
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * JSON-encode for JOSE segments (no escaped slashes/unicode).
     *
     * @param array<string, mixed> $data The data to encode
     * @return string The JSON string
     * @throws OAuthException If encoding fails
     */
    private static function jsonEncode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new OAuthException('Failed to JSON-encode JWT segment: ' . json_last_error_msg());
        }
        return $json;
    }
}
