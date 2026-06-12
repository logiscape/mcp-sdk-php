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
 * Filename: tests/Client/Auth/Jwt/ClientAssertionJwtTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Client\Auth\Jwt;

use Mcp\Client\Auth\Jwt\ClientAssertionJwt;
use Mcp\Client\Auth\OAuthException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the RFC 7523 JWT client assertion builder (private_key_jwt).
 *
 * Validates:
 *   - Claim structure per RFC 7523 Section 3 (iss/sub = client_id, aud = AS
 *     issuer, exp/iat window, unique jti)
 *   - ES256 signatures are emitted in raw R||S JOSE format (RFC 7518 3.4),
 *     converted from openssl's DER encoding, and verify against the public key
 *   - RS256 signatures verify directly
 *   - DER-to-concatenated conversion handles padding and sign bytes
 *   - Unsupported algorithms are rejected
 *
 * The embedded keys are throwaway test fixtures generated for this test only.
 */
final class ClientAssertionJwtTest extends TestCase
{
    /**
     * Throwaway EC P-256 private key (PKCS#8), test fixture only.
     */
    private const EC_PRIVATE_KEY = <<<PEM
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgTYCyR5GoHl4JFLvA
X1WnrcYUn5lmG/F2IPzJMb7s1WehRANCAASNsKdKS2BLjZAiUtB3DekfbgSKgs/9
3XKxjbf0zGzlvRvtgGOH/3rseq8ql4y5d3uiYWb+oqUreIpd4n0tilwh
-----END PRIVATE KEY-----
PEM;

    private const EC_PUBLIC_KEY = <<<PEM
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEjbCnSktgS42QIlLQdw3pH24EioLP
/d1ysY239Mxs5b0b7YBjh/967HqvKpeMuXd7omFm/qKlK3iKXeJ9LYpcIQ==
-----END PUBLIC KEY-----
PEM;

    /**
     * Throwaway RSA-2048 private key (PKCS#8), test fixture only.
     */
    private const RSA_PRIVATE_KEY = <<<PEM
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDwnFYGpmmCz/6T
6P0W4aK1sii2ZFDLw/ZGhqhDYEeC/H5JVw9lq26FIfK2QoahAiCwU/AKfnsR12Yz
f/q95E9wgG12tvpHGf7h/e/ee0l7fzwQ/KkhwqFNhBQikoS18A9plun4QRDYws6q
Dd6jFvLXNWVcnENj6JkqCeIK5bq+meZUKDRBWj2W4vo7vKaEfuMGrlpg8fIYNKmL
fcJfGoWeRxe8ED/yyIZHCJleVggzrOhmiiTgCqXLHtCudrhVTUqu1oB0IMBTppWH
zy5+ewNihgdW3k2tjL56xmBFxj/UqzuFih/ikxhBRlW9nbUiYQyoc09bPC0E7kyl
K02XuScrAgMBAAECggEACV3ss2w9u0v28IcneIE8NlqYidE0nATQTO/nvl0R1krs
wlTTUp/ujjGYzfj1CMZDw4Q2WhI7yaFbEdzaRBOOoXs+clquj59hdCbQnxapm6yD
sshDRVSFtwt8moPzCqotJMKCmpmIhbJqcpBWVH8Li86WWEP1E6n1xynZ/5abQy7j
RiOvV5pVG3ntfXcjznX4nWRTocCr9HmVLqXJpOH036wKxv44uGkp3LG5FFX/6HBo
N5odkrinYGZxI7f9QR8QnTrE09qZN5QlmoycsunWgW+l0t+ZfsTAMyNhRzZzNwhZ
lq3fBetotVEcIoAzSQ/5i3O1v1rZvXNmDrpuRy26EQKBgQD+d44h1vJGHEwt6UDJ
wEFb4LwSlNJwkhs9n4GGDnjJgFH3uS3l89uLOJyO4rfz5vJIN84VU95G8fQXS5yk
oeizr26JrCAvX0IL8uRbvJtppkNN2QNArlUR8GPh6khVsl8lDQ9Vedz21P7XC589
IRu2ulaLwlQUQIWe+mDI06lSzQKBgQDyD2lKvoX/UxXMUPLwNKSi1sjFWQlTp2Q+
E7B7urKMqQDaZ8rmm80+95YSki+dQLk41O4/F2wK/gsQIWJOuQv7pG0tCTa1hwT2
WNNaMlV1Jpr2x8N3p5ATkS7gkF1ckrio7iHJKLffqTy2lxHBZNCuFf0R9MH92JAx
1dI+gBkR1wKBgQCh0svbb0MBHQvBAp3F73JhCGjx9BxjpDaYmLAY3Wko02bM/8FV
hB+wyJ6fK0TKYargDEUNQRmQylts2RPTindelYjZGlIZbh2lVaCtSiMXK7mPbxtn
bbYBw3rxdzjq7pUdDdIbjHTdr5Qkk2p/yGdEa/mPxVQM0P9om1cjP59lXQKBgEg5
5v4BeXQk83UilkqsOo/ILOIN3iWS5etL7rYMkCg+aV3B6J4E0So/sAzch86ROHNH
vcsfjwJ8qQpoG7BHsNamSllMzYE+x3FGMdJRAITTPxvxoVZ7rhbYlDc3fFQaB3WI
kJY4Sx02n0IQM6EUFtxK/Vc1RAkdbHEiTviNOm3DAoGBAPRtkW6tG3e2XGL5iPxW
8V/UQaH07zTvkKS/PpvyGduQ0RWM752NzbRLTsSWK11vqOxMs8GzszD/dAWl5je1
jhNaI0jblUPTSl5p2sgqHq7EZ1FhqHZ17Pi4ROIs+dvGb8DAOdHdXFG2f13mVWuT
2UbJUMKPg9d9GIqiObiVh2c6
-----END PRIVATE KEY-----
PEM;

    private const RSA_PUBLIC_KEY = <<<PEM
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA8JxWBqZpgs/+k+j9FuGi
tbIotmRQy8P2RoaoQ2BHgvx+SVcPZatuhSHytkKGoQIgsFPwCn57EddmM3/6veRP
cIBtdrb6Rxn+4f3v3ntJe388EPypIcKhTYQUIpKEtfAPaZbp+EEQ2MLOqg3eoxby
1zVlXJxDY+iZKgniCuW6vpnmVCg0QVo9luL6O7ymhH7jBq5aYPHyGDSpi33CXxqF
nkcXvBA/8siGRwiZXlYIM6zoZook4Aqlyx7Qrna4VU1KrtaAdCDAU6aVh88ufnsD
YoYHVt5NrYy+esZgRcY/1Ks7hYof4pMYQUZVvZ21ImEMqHNPWzwtBO5MpStNl7kn
KwIDAQAB
-----END PUBLIC KEY-----
PEM;

    /**
     * Decode a base64url JOSE segment.
     */
    private static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        self::assertIsString($decoded, 'JOSE segment must be valid base64url');
        return $decoded;
    }

    /**
     * Re-encode a raw R||S ECDSA signature as ASN.1 DER so openssl_verify
     * can check it.
     */
    private static function concatenatedToDer(string $raw): string
    {
        $partLength = intdiv(strlen($raw), 2);
        $encodeInt = static function (string $bytes): string {
            $bytes = ltrim($bytes, "\x00");
            if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
                $bytes = "\x00" . $bytes;
            }
            return "\x02" . chr(strlen($bytes)) . $bytes;
        };
        $body = $encodeInt(substr($raw, 0, $partLength)) . $encodeInt(substr($raw, $partLength));
        return "\x30" . chr(strlen($body)) . $body;
    }

    /**
     * ES256 assertion: header, RFC 7523 claims, raw 64-byte JOSE signature,
     * and signature verification against the EC public key after converting
     * the raw R||S form back to DER.
     */
    public function testEs256AssertionStructureAndSignature(): void
    {
        $before = time();
        $jwt = ClientAssertionJwt::create(
            clientId: 'test-client',
            audience: 'https://as.example.com',
            privateKeyPem: self::EC_PRIVATE_KEY,
            algorithm: 'ES256'
        );
        $after = time();

        $segments = explode('.', $jwt);
        $this->assertCount(3, $segments, 'Compact JWS must have three segments');

        $header = json_decode(self::base64UrlDecode($segments[0]), true);
        $this->assertSame('ES256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);

        $claims = json_decode(self::base64UrlDecode($segments[1]), true);
        $this->assertSame('test-client', $claims['iss']);
        $this->assertSame('test-client', $claims['sub']);
        $this->assertSame('https://as.example.com', $claims['aud']);
        $this->assertIsString($claims['jti']);
        $this->assertNotSame('', $claims['jti']);
        $this->assertGreaterThanOrEqual($before, $claims['iat']);
        $this->assertLessThanOrEqual($after, $claims['iat']);
        $this->assertGreaterThan($claims['iat'], $claims['exp']);

        // RFC 7518 Section 3.4: ES256 signatures are exactly 64 raw bytes.
        $signature = self::base64UrlDecode($segments[2]);
        $this->assertSame(64, strlen($signature));

        // The signature must verify against the public key.
        $der = self::concatenatedToDer($signature);
        $verified = openssl_verify(
            "{$segments[0]}.{$segments[1]}",
            $der,
            self::EC_PUBLIC_KEY,
            OPENSSL_ALGO_SHA256
        );
        $this->assertSame(1, $verified, 'ES256 signature must verify against the public key');
    }

    /**
     * RS256 assertions need no DER conversion and verify directly.
     */
    public function testRs256AssertionVerifies(): void
    {
        $jwt = ClientAssertionJwt::create(
            clientId: 'rsa-client',
            audience: 'https://as.example.com',
            privateKeyPem: self::RSA_PRIVATE_KEY,
            algorithm: 'RS256'
        );

        $segments = explode('.', $jwt);
        $this->assertCount(3, $segments);

        $header = json_decode(self::base64UrlDecode($segments[0]), true);
        $this->assertSame('RS256', $header['alg']);

        $verified = openssl_verify(
            "{$segments[0]}.{$segments[1]}",
            self::base64UrlDecode($segments[2]),
            self::RSA_PUBLIC_KEY,
            OPENSSL_ALGO_SHA256
        );
        $this->assertSame(1, $verified, 'RS256 signature must verify against the public key');
    }

    /**
     * Each assertion gets a unique jti (replay protection).
     */
    public function testJtiIsUniquePerAssertion(): void
    {
        $extractJti = function (string $jwt): string {
            $claims = json_decode(self::base64UrlDecode(explode('.', $jwt)[1]), true);
            return $claims['jti'];
        };

        $first = ClientAssertionJwt::create('c', 'https://as.example.com', self::EC_PRIVATE_KEY, 'ES256');
        $second = ClientAssertionJwt::create('c', 'https://as.example.com', self::EC_PRIVATE_KEY, 'ES256');

        $this->assertNotSame($extractJti($first), $extractJti($second));
    }

    /**
     * Unsupported algorithms are rejected with a clear error.
     */
    public function testUnsupportedAlgorithmThrows(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Unsupported JWT signing algorithm');

        ClientAssertionJwt::create('c', 'https://as.example.com', self::EC_PRIVATE_KEY, 'HS256');
    }

    /**
     * An invalid PEM is rejected before signing.
     */
    public function testInvalidPrivateKeyThrows(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to load private key');

        ClientAssertionJwt::create('c', 'https://as.example.com', 'not-a-pem', 'ES256');
    }

    /**
     * DER-to-concatenated conversion: a DER signature whose INTEGERs carry a
     * sign-padding zero byte (high bit set) and a short R component must
     * produce fixed-length, left-zero-padded R||S output.
     */
    public function testDerSignatureToConcatenatedHandlesPaddingAndShortIntegers(): void
    {
        // R = 31 bytes of 0x11 (short: needs left padding to 32)
        // S = 32 bytes starting 0x80 (high bit: DER adds a 0x00 sign byte)
        $r = str_repeat("\x11", 31);
        $s = "\x80" . str_repeat("\x22", 31);

        $derR = "\x02" . chr(strlen($r)) . $r;
        $derS = "\x02" . chr(strlen($s) + 1) . "\x00" . $s;
        $der = "\x30" . chr(strlen($derR . $derS)) . $derR . $derS;

        $raw = ClientAssertionJwt::derSignatureToConcatenated($der, 32);

        $this->assertSame(64, strlen($raw));
        $this->assertSame("\x00" . $r, substr($raw, 0, 32), 'Short R must be left-padded with zeros');
        $this->assertSame($s, substr($raw, 32), 'DER sign-padding byte must be stripped from S');
    }

    /**
     * Malformed DER input is rejected.
     */
    public function testDerSignatureToConcatenatedRejectsMalformedInput(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Malformed DER ECDSA signature');

        ClientAssertionJwt::derSignatureToConcatenated("\x02\x01\x00", 32);
    }
}
