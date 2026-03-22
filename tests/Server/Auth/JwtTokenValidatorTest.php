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
 * Filename: Tests/Server/Auth/JwtTokenValidatorTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server\Auth;

use Mcp\Server\Auth\JwtTokenValidator;
use Mcp\Server\Auth\TokenValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JwtTokenValidator covering HS256/RS256 signature verification,
 * structural validation, and claim checks (exp, nbf, iat, iss, aud).
 */
final class JwtTokenValidatorTest extends TestCase
{
    private const SECRET = 'test-secret-key-for-hmac-256-validation';

    /**
     * Test that a valid HS256 token with a correct secret passes validation,
     * returns valid=true, and includes the expected claims in the result.
     */
    public function testValidHs256TokenSucceeds(): void
    {
        $payload = [
            'sub' => 'user-123',
            'name' => 'Test User',
            'exp' => time() + 3600,
            'iat' => time(),
        ];

        $token = $this->createHs256Token($payload, self::SECRET);
        $validator = new JwtTokenValidator(self::SECRET, 'HS256');
        $result = $validator->validate($token);

        $this->assertTrue($result->valid);
        $this->assertNull($result->error);
        $this->assertSame('user-123', $result->claims['sub']);
        $this->assertSame('Test User', $result->claims['name']);
    }

    /**
     * Test that an HS256 token signed with a different secret fails validation.
     * The signature check must reject tokens signed with the wrong key.
     */
    public function testHs256TokenWithWrongSecretFails(): void
    {
        $payload = [
            'sub' => 'user-123',
            'exp' => time() + 3600,
        ];

        $token = $this->createHs256Token($payload, 'wrong-secret');
        $validator = new JwtTokenValidator(self::SECRET, 'HS256');
        $result = $validator->validate($token);

        $this->assertFalse($result->valid);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('Signature', $result->error);
    }

    /**
     * Test that a token with only two parts (missing signature) is rejected
     * as malformed. The JWT specification requires exactly three dot-separated parts.
     */
    public function testMalformedTokenWithTwoPartsFails(): void
    {
        $validator = new JwtTokenValidator(self::SECRET, 'HS256');
        $result = $validator->validate('a.b');

        $this->assertFalse($result->valid);
        $this->assertSame('Malformed token', $result->error);
    }

    /**
     * Test that a token with four dot-separated parts is rejected as malformed.
     * Only exactly three parts (header.payload.signature) are valid.
     */
    public function testMalformedTokenWithFourPartsFails(): void
    {
        $validator = new JwtTokenValidator(self::SECRET, 'HS256');
        $result = $validator->validate('a.b.c.d');

        $this->assertFalse($result->valid);
        $this->assertSame('Malformed token', $result->error);
    }

    /**
     * Test that a token with an invalid base64-encoded payload that causes
     * json_decode to return null is rejected with an encoding error.
     */
    public function testInvalidBase64EncodingFails(): void
    {
        // Create a valid header but a payload that decodes to invalid JSON
        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        // base64url-encode a string that is not valid JSON
        $payload = $this->base64UrlEncode('not-valid-json{{{');
        $signature = $this->base64UrlEncode('fake-signature');

        $token = $header . '.' . $payload . '.' . $signature;
        $validator = new JwtTokenValidator(self::SECRET, 'HS256');
        $result = $validator->validate($token);

        $this->assertFalse($result->valid);
        $this->assertSame('Invalid encoding', $result->error);
    }

    /**
     * Test that a token whose header is missing the 'alg' field is rejected.
     * The algorithm claim is required for the validator to determine which
     * verification method to use and to prevent algorithm confusion attacks.
     */
    public function testMissingAlgorithmInHeaderFails(): void
    {
        $payload = ['sub' => 'user-123', 'exp' => time() + 3600];
        $token = $this->createHs256Token($payload, self::SECRET, ['alg' => null, 'typ' => 'JWT']);
        // Manually rebuild without alg - the helper merges, so we need to construct manually
        $header = ['typ' => 'JWT'];
        $encodedHeader = $this->base64UrlEncode(json_encode($header));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload));
        $data = $encodedHeader . '.' . $encodedPayload;
        $signature = hash_hmac('sha256', $data, self::SECRET, true);
        $token = $data . '.' . $this->base64UrlEncode($signature);

        $validator = new JwtTokenValidator(self::SECRET, 'HS256');
        $result = $validator->validate($token);

        $this->assertFalse($result->valid);
        $this->assertSame('Missing algorithm in token header', $result->error);
    }

    /**
     * Test that algorithm substitution is prevented. If the token header claims
     * HS256 but the validator is configured for RS256, validation must fail.
     * This is a critical security check against algorithm substitution attacks.
     */
    public function testAlgorithmMismatchFails(): void
    {
        $payload = ['sub' => 'user-123', 'exp' => time() + 3600];
        $token = $this->createHs256Token($payload, self::SECRET);

        // Validator expects RS256 but token uses HS256
        $validator = new JwtTokenValidator(self::SECRET, 'RS256');
        $result = $validator->validate($token);

        $this->assertFalse($result->valid);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('Algorithm mismatch', $result->error);
        $this->assertStringContainsString('HS256', $result->error);
        $this->assertStringContainsString('RS256', $result->error);
    }

    /**
     * Test that a token with an 'exp' claim set in the past is rejected.
     * The validator checks that the current time has not reached or exceeded
     * the expiration time.
     */
    public function testExpiredTokenFails(): void
    {
        $payload = [
            'sub' => 'user-123',
            'exp' => time() - 3600,
        ];

        $token = $this->createHs256Token($payload, self::SECRET);
        $validator = new JwtTokenValidator(self::SECRET, 'HS256');
        $result = $validator->validate($token);

        $this->assertFalse($result->valid);
        $this->assertSame('Token expired', $result->error);
    }

    /**
     * Test that a token with an 'nbf' (not before) claim set in the future
     * is rejected. The token should not be considered valid until the nbf time
     * has been reached.
     */
    public function testNotYetValidTokenFails(): void
    {
        $payload = [
            'sub' => 'user-123',
            'nbf' => time() + 3600,
            'exp' => time() + 7200,
        ];

        $token = $this->createHs256Token($payload, self::SECRET);
        $validator = new JwtTokenValidator(self::SECRET, 'HS256');
        $result = $validator->validate($token);

        $this->assertFalse($result->valid);
        $this->assertSame('Token not yet valid', $result->error);
    }

    /**
     * Test that a token with an 'iat' (issued at) claim set in the future
     * is rejected. A token cannot have been issued at a time that has not
     * yet occurred.
     */
    public function testIssuedInFutureFails(): void
    {
        $payload = [
            'sub' => 'user-123',
            'iat' => time() + 3600,
            'exp' => time() + 7200,
        ];

        $token = $this->createHs256Token($payload, self::SECRET);
        $validator = new JwtTokenValidator(self::SECRET, 'HS256');
        $result = $validator->validate($token);

        $this->assertFalse($result->valid);
        $this->assertSame('Token issued in the future', $result->error);
    }

    /**
     * Test that when the validator is configured with an expected issuer,
     * a token with a different issuer is rejected. This ensures tokens
     * from unauthorized issuers cannot be used.
     */
    public function testInvalidIssuerFails(): void
    {
        $payload = [
            'sub' => 'user-123',
            'iss' => 'bad-issuer',
            'exp' => time() + 3600,
        ];

        $token = $this->createHs256Token($payload, self::SECRET);
        $validator = new JwtTokenValidator(self::SECRET, 'HS256', 'good-issuer');
        $result = $validator->validate($token);

        $this->assertFalse($result->valid);
        $this->assertSame('Invalid issuer', $result->error);
    }

    /**
     * Test that when the validator is configured with an expected issuer,
     * a token whose issuer matches is accepted. The issuer claim validation
     * should pass when the values are identical.
     */
    public function testValidIssuerSucceeds(): void
    {
        $payload = [
            'sub' => 'user-123',
            'iss' => 'good-issuer',
            'exp' => time() + 3600,
        ];

        $token = $this->createHs256Token($payload, self::SECRET);
        $validator = new JwtTokenValidator(self::SECRET, 'HS256', 'good-issuer');
        $result = $validator->validate($token);

        $this->assertTrue($result->valid);
        $this->assertNull($result->error);
        $this->assertSame('good-issuer', $result->claims['iss']);
    }

    /**
     * Test that when the validator is configured with an expected audience,
     * a token with a different audience string is rejected. This prevents
     * tokens intended for other services from being accepted.
     */
    public function testInvalidAudienceStringFails(): void
    {
        $payload = [
            'sub' => 'user-123',
            'aud' => 'other-api',
            'exp' => time() + 3600,
        ];

        $token = $this->createHs256Token($payload, self::SECRET);
        $validator = new JwtTokenValidator(self::SECRET, 'HS256', null, 'my-api');
        $result = $validator->validate($token);

        $this->assertFalse($result->valid);
        $this->assertSame('Invalid audience', $result->error);
    }

    /**
     * Test that when the validator is configured with an expected audience,
     * a token with a matching audience string is accepted.
     */
    public function testValidAudienceStringSucceeds(): void
    {
        $payload = [
            'sub' => 'user-123',
            'aud' => 'my-api',
            'exp' => time() + 3600,
        ];

        $token = $this->createHs256Token($payload, self::SECRET);
        $validator = new JwtTokenValidator(self::SECRET, 'HS256', null, 'my-api');
        $result = $validator->validate($token);

        $this->assertTrue($result->valid);
        $this->assertNull($result->error);
    }

    /**
     * Test that when the token's audience claim is an array containing
     * the expected audience, validation succeeds. The JWT specification
     * allows the 'aud' claim to be either a string or an array of strings.
     */
    public function testValidAudienceArraySucceeds(): void
    {
        $payload = [
            'sub' => 'user-123',
            'aud' => ['api-1', 'api-2'],
            'exp' => time() + 3600,
        ];

        $token = $this->createHs256Token($payload, self::SECRET);
        $validator = new JwtTokenValidator(self::SECRET, 'HS256', null, 'api-1');
        $result = $validator->validate($token);

        $this->assertTrue($result->valid);
        $this->assertNull($result->error);
    }

    /**
     * Test that when the token's audience claim is an array that does NOT
     * contain the expected audience, validation fails. The validator must
     * check for membership in the array, not just any match.
     */
    public function testInvalidAudienceArrayFails(): void
    {
        $payload = [
            'sub' => 'user-123',
            'aud' => ['api-1'],
            'exp' => time() + 3600,
        ];

        $token = $this->createHs256Token($payload, self::SECRET);
        $validator = new JwtTokenValidator(self::SECRET, 'HS256', null, 'api-2');
        $result = $validator->validate($token);

        $this->assertFalse($result->valid);
        $this->assertSame('Invalid audience', $result->error);
    }

    /**
     * Test RS256 validation with a dynamically generated RSA keypair.
     * The token is signed with the private key and validated with the
     * corresponding public key in PEM format. Skips if OpenSSL is unavailable.
     */
    public function testRs256WithPemKeySucceeds(): void
    {
        if (!function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('OpenSSL extension is not available.');
        }

        $privateKey = $this->generateRsaPrivateKey();
        if ($privateKey === false) {
            $this->markTestSkipped('Unable to generate RSA key pair on this system (OpenSSL config may be missing).');
        }

        $details = openssl_pkey_get_details($privateKey);
        $this->assertNotFalse($details, 'Failed to get key details');
        $publicKeyPem = $details['key'];

        $payload = [
            'sub' => 'user-456',
            'iss' => 'test-issuer',
            'exp' => time() + 3600,
            'iat' => time(),
        ];

        $token = $this->createRs256Token($payload, $privateKey);
        $validator = new JwtTokenValidator($publicKeyPem, 'RS256', 'test-issuer');
        $result = $validator->validate($token);

        $this->assertTrue($result->valid, 'RS256 validation should succeed: ' . ($result->error ?? ''));
        $this->assertNull($result->error);
        $this->assertSame('user-456', $result->claims['sub']);
        $this->assertSame('test-issuer', $result->claims['iss']);
    }

    /**
     * Test that RS256 validation fails when the token is signed with one
     * private key but the validator is configured with a different public key.
     * This ensures that key mismatch is properly detected for asymmetric algorithms.
     */
    public function testRs256WithWrongKeyFails(): void
    {
        if (!function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('OpenSSL extension is not available.');
        }

        // Generate the signing key pair
        $signingKey = $this->generateRsaPrivateKey();
        if ($signingKey === false) {
            $this->markTestSkipped('Unable to generate RSA key pair on this system (OpenSSL config may be missing).');
        }

        // Generate a different key pair for validation
        $wrongKey = $this->generateRsaPrivateKey();
        $this->assertNotFalse($wrongKey, 'Failed to generate wrong RSA key pair');

        $wrongDetails = openssl_pkey_get_details($wrongKey);
        $this->assertNotFalse($wrongDetails, 'Failed to get wrong key details');
        $wrongPublicKeyPem = $wrongDetails['key'];

        $payload = [
            'sub' => 'user-789',
            'exp' => time() + 3600,
        ];

        $token = $this->createRs256Token($payload, $signingKey);
        $validator = new JwtTokenValidator($wrongPublicKeyPem, 'RS256');
        $result = $validator->validate($token);

        $this->assertFalse($result->valid);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('Signature', $result->error);
    }

    // -----------------------------------------------------------------------
    // Helper methods
    // -----------------------------------------------------------------------

    /**
     * Create an HS256-signed JWT token from the given payload and secret.
     *
     * @param array<string, mixed> $payload The JWT claims
     * @param string $secret The HMAC secret key
     * @param array<string, mixed>|null $headerOverrides Optional header field overrides
     * @return string The encoded JWT
     */
    private function createHs256Token(array $payload, string $secret, ?array $headerOverrides = null): string
    {
        $header = array_merge(['alg' => 'HS256', 'typ' => 'JWT'], $headerOverrides ?? []);
        // Remove keys with null values so we can test missing fields
        $header = array_filter($header, static fn($v) => $v !== null);
        $encodedHeader = $this->base64UrlEncode(json_encode($header));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload));
        $data = $encodedHeader . '.' . $encodedPayload;
        $signature = hash_hmac('sha256', $data, $secret, true);
        return $data . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * Create an RS256-signed JWT token from the given payload and private key.
     *
     * @param array<string, mixed> $payload The JWT claims
     * @param \OpenSSLAsymmetricKey $privateKey The RSA private key
     * @return string The encoded JWT
     */
    private function createRs256Token(array $payload, \OpenSSLAsymmetricKey $privateKey): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $encodedHeader = $this->base64UrlEncode(json_encode($header));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload));
        $data = $encodedHeader . '.' . $encodedPayload;
        openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        return $data . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * Base64url-encode a string per RFC 4648.
     *
     * @param string $data The data to encode
     * @return string The base64url-encoded string
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Generate an RSA private key, trying multiple OpenSSL config locations.
     *
     * @return \OpenSSLAsymmetricKey|false
     */
    private function generateRsaPrivateKey(): \OpenSSLAsymmetricKey|false
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        // Try without explicit config first
        $key = openssl_pkey_new($config);
        if ($key !== false) {
            return $key;
        }

        // Try common OpenSSL config locations (Windows)
        $configPaths = [
            'C:/php/extras/ssl/openssl.cnf',
            'C:/Program Files/Common Files/SSL/openssl.cnf',
            'C:/OpenSSL-Win64/bin/openssl.cnf',
        ];

        foreach ($configPaths as $path) {
            if (file_exists($path)) {
                $config['config'] = $path;
                $key = openssl_pkey_new($config);
                if ($key !== false) {
                    return $key;
                }
            }
        }

        return false;
    }
}
