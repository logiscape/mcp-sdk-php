<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2025 Logiscape LLC <https://logiscape.com>
 *
 * Developed by:
 * - Josh Abbott
 * - Claude Opus 4 (Anthropic AI model)
 * - OpenAI Codex
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
 * Filename: Server/Auth/JwtTokenValidator.php
 */

declare(strict_types=1);

namespace Mcp\Server\Auth;

/**
 * JWT validator supporting HS256 and RS256 algorithms with optional
 * claim verification and JWKS support for RS256.
 * 
 * For HS256: Provide a shared secret key
 * For RS256: Provide either a PEM public key OR a JWKS URI to fetch keys dynamically
 */
class JwtTokenValidator implements TokenValidatorInterface
{
    /** @var array<string, mixed>|null */
    private ?array $jwksCache = null;
    private ?int $jwksCacheTime = null;
    private int $jwksCacheTtl = 3600; // Cache JWKS for 1 hour

    /**
     * @param string $key The key for validation:
     *                    - For HS256: The shared secret
     *                    - For RS256: A PEM-formatted public key (if not using JWKS)
     * @param string $algorithm Expected algorithm ('HS256' or 'RS256')
     * @param string|null $issuer Expected issuer claim (iss)
     * @param string|null $audience Expected audience claim (aud)
     * @param string|null $jwksUri URI to fetch JSON Web Key Set for RS256 validation
     *                             (e.g., 'https://your-tenant.auth0.com/.well-known/jwks.json')
     */
    public function __construct(
        private readonly string $key,
        private readonly string $algorithm = 'HS256',
        private readonly ?string $issuer = null,
        private readonly ?string $audience = null,
        private readonly ?string $jwksUri = null,
    ) {
    }

    public function validate(string $token): TokenValidationResult
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return new TokenValidationResult(false, [], 'Malformed token');
        }

        [$encodedHeader, $encodedPayload, $encodedSig] = $parts;
        $header = json_decode($this->base64UrlDecode($encodedHeader), true);
        $payload = json_decode($this->base64UrlDecode($encodedPayload), true);
        if ($header === null || $payload === null) {
            return new TokenValidationResult(false, [], 'Invalid encoding');
        }

        // Get algorithm from token header, but validate it matches expected
        $tokenAlg = $header['alg'] ?? null;
        if ($tokenAlg === null) {
            return new TokenValidationResult(false, [], 'Missing algorithm in token header');
        }

        // Security: Verify the token's algorithm matches our expected algorithm
        // This prevents algorithm substitution attacks
        if ($tokenAlg !== $this->algorithm) {
            return new TokenValidationResult(
                false, 
                [], 
                "Algorithm mismatch: token uses {$tokenAlg}, expected {$this->algorithm}"
            );
        }

        $data = $encodedHeader . '.' . $encodedPayload;
        $signature = $this->base64UrlDecode($encodedSig);

        $valid = false;
        if ($this->algorithm === 'HS256') {
            $expected = hash_hmac('sha256', $data, $this->key, true);
            $valid = hash_equals($expected, $signature);
        } elseif ($this->algorithm === 'RS256') {
            $publicKey = $this->getPublicKeyForRS256($header);
            if ($publicKey === null) {
                return new TokenValidationResult(false, [], 'Unable to obtain public key for RS256 verification');
            }
            $result = openssl_verify($data, $signature, $publicKey, OPENSSL_ALGO_SHA256);
            $valid = ($result === 1);
        } else {
            return new TokenValidationResult(false, [], 'Unsupported algorithm');
        }

        if (!$valid) {
            return new TokenValidationResult(false, [], 'Signature verification failed');
        }

        $now = time();

        if (isset($payload['exp']) && $now >= (int) $payload['exp']) {
            return new TokenValidationResult(false, [], 'Token expired');
        }

        if (isset($payload['nbf']) && $now < (int) $payload['nbf']) {
            return new TokenValidationResult(false, [], 'Token not yet valid');
        }

        if (isset($payload['iat']) && $now < (int) $payload['iat']) {
            return new TokenValidationResult(false, [], 'Token issued in the future');
        }

        if ($this->issuer !== null && ($payload['iss'] ?? null) !== $this->issuer) {
            return new TokenValidationResult(false, [], 'Invalid issuer');
        }

        if ($this->audience !== null) {
            $aud = $payload['aud'] ?? null;
            $audValid = false;
            if (is_string($aud)) {
                $audValid = $aud === $this->audience;
            } elseif (is_array($aud)) {
                $audValid = in_array($this->audience, $aud, true);
            }
            if (!$audValid) {
                return new TokenValidationResult(false, [], 'Invalid audience');
            }
        }

        return new TokenValidationResult(true, $payload);
    }

    /**
     * Get the public key for RS256 verification.
     * 
     * If a JWKS URI is configured, fetches the key from the JWKS endpoint.
     * Otherwise, uses the key provided in the constructor (assumed to be PEM format).
     * 
     * @param array<string, mixed> $header The decoded JWT header (contains 'kid' for JWKS lookup)
     * @return \OpenSSLAsymmetricKey|null The public key or null on failure
     */
    private function getPublicKeyForRS256(array $header): \OpenSSLAsymmetricKey|null
    {
        // If JWKS URI is configured, fetch the key from there
        if ($this->jwksUri !== null) {
            return $this->getKeyFromJwks($header['kid'] ?? null);
        }

        // Otherwise, try to use the provided key as a PEM public key
        $publicKey = openssl_pkey_get_public($this->key);
        if ($publicKey === false) {
            return null;
        }
        return $publicKey;
    }

    /**
     * Fetch a public key from the JWKS endpoint.
     * 
     * @param string|null $kid The key ID to look for
     * @return \OpenSSLAsymmetricKey|null The public key or null on failure
     */
    private function getKeyFromJwks(?string $kid): \OpenSSLAsymmetricKey|null
    {
        $jwks = $this->fetchJwks();
        if ($jwks === null || !isset($jwks['keys']) || !is_array($jwks['keys'])) {
            return null;
        }

        // Find the key with matching kid, or use first key if no kid specified
        $jwk = null;
        foreach ($jwks['keys'] as $key) {
            // Skip keys that aren't for signing
            if (isset($key['use']) && $key['use'] !== 'sig') {
                continue;
            }
            // Skip keys that don't match the algorithm
            if (isset($key['alg']) && $key['alg'] !== 'RS256') {
                continue;
            }
            // Match by kid if provided
            if ($kid !== null) {
                if (isset($key['kid']) && $key['kid'] === $kid) {
                    $jwk = $key;
                    break;
                }
            } else {
                // No kid provided, use first suitable key
                $jwk = $key;
                break;
            }
        }

        if ($jwk === null) {
            // If we didn't find a matching key and we have a kid, try refreshing the cache
            if ($kid !== null && $this->jwksCache !== null) {
                $this->jwksCache = null;
                $this->jwksCacheTime = null;
                return $this->getKeyFromJwks($kid);
            }
            return null;
        }

        return $this->jwkToPem($jwk);
    }

    /**
     * Fetch the JWKS from the configured URI, with caching.
     * 
     * @return array<string, mixed>|null The JWKS or null on failure
     */
    private function fetchJwks(): ?array
    {
        // Check cache
        if ($this->jwksCache !== null && $this->jwksCacheTime !== null) {
            if ((time() - $this->jwksCacheTime) < $this->jwksCacheTtl) {
                return $this->jwksCache;
            }
        }

        // Fetch JWKS
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => 'Accept: application/json',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($this->jwksUri, false, $context);
        if ($response === false) {
            error_log("JwtTokenValidator: Failed to fetch JWKS from {$this->jwksUri}");
            return null;
        }

        $jwks = json_decode($response, true);
        if ($jwks === null || !isset($jwks['keys'])) {
            error_log("JwtTokenValidator: Invalid JWKS response from {$this->jwksUri}");
            return null;
        }

        // Cache the result
        $this->jwksCache = $jwks;
        $this->jwksCacheTime = time();

        return $jwks;
    }

    /**
     * Convert a JWK (JSON Web Key) to a PEM-formatted public key.
     * 
     * @param array<string, mixed> $jwk The JWK data
     * @return \OpenSSLAsymmetricKey|null The public key or null on failure
     */
    private function jwkToPem(array $jwk): \OpenSSLAsymmetricKey|null
    {
        // We only support RSA keys for now
        if (($jwk['kty'] ?? '') !== 'RSA') {
            error_log("JwtTokenValidator: Unsupported key type: " . ($jwk['kty'] ?? 'unknown'));
            return null;
        }

        if (!isset($jwk['n']) || !isset($jwk['e'])) {
            error_log("JwtTokenValidator: JWK missing required RSA components (n, e)");
            return null;
        }

        // Decode the modulus (n) and exponent (e) from base64url
        $modulus = $this->base64UrlDecode($jwk['n']);
        $exponent = $this->base64UrlDecode($jwk['e']);

        // Build the RSA public key in DER format
        $der = $this->buildRsaPublicKeyDer($modulus, $exponent);
        
        // Convert to PEM
        $pem = "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END PUBLIC KEY-----\n";

        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            error_log("JwtTokenValidator: Failed to parse constructed PEM key");
            return null;
        }

        return $publicKey;
    }

    /**
     * Build an RSA public key in DER format from modulus and exponent.
     * 
     * @param string $modulus The RSA modulus (n)
     * @param string $exponent The RSA exponent (e)
     * @return string The DER-encoded public key
     */
    private function buildRsaPublicKeyDer(string $modulus, string $exponent): string
    {
        // Ensure modulus has leading zero if high bit is set (to indicate positive number)
        if (ord($modulus[0]) > 0x7f) {
            $modulus = "\x00" . $modulus;
        }

        // Ensure exponent has leading zero if high bit is set
        if (ord($exponent[0]) > 0x7f) {
            $exponent = "\x00" . $exponent;
        }

        // Build the RSA public key sequence
        $rsaPublicKey = $this->asn1Sequence(
            $this->asn1Integer($modulus) .
            $this->asn1Integer($exponent)
        );

        // OID for rsaEncryption: 1.2.840.113549.1.1.1
        $rsaOid = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
        $nullParams = "\x05\x00";

        // AlgorithmIdentifier sequence
        $algorithmIdentifier = $this->asn1Sequence($rsaOid . $nullParams);

        // Wrap RSA key in BIT STRING
        $bitString = "\x00" . $rsaPublicKey; // Leading 0x00 for unused bits
        $bitStringEncoded = $this->asn1BitString($bitString);

        // Final SubjectPublicKeyInfo sequence
        return $this->asn1Sequence($algorithmIdentifier . $bitStringEncoded);
    }

    /**
     * Encode data as ASN.1 SEQUENCE.
     */
    private function asn1Sequence(string $data): string
    {
        return "\x30" . $this->asn1Length(strlen($data)) . $data;
    }

    /**
     * Encode data as ASN.1 INTEGER.
     */
    private function asn1Integer(string $data): string
    {
        return "\x02" . $this->asn1Length(strlen($data)) . $data;
    }

    /**
     * Encode data as ASN.1 BIT STRING.
     */
    private function asn1BitString(string $data): string
    {
        return "\x03" . $this->asn1Length(strlen($data)) . $data;
    }

    /**
     * Encode length in ASN.1 DER format.
     */
    private function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $temp = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($temp)) . $temp;
    }

    private function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($input, '-_', '+/')) ?: '';
    }
}
