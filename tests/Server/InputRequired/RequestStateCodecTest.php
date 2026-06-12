<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Filename: tests/Server/InputRequired/RequestStateCodecTest.php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server\InputRequired;

use Mcp\Server\InputRequired\RequestStateCodec;
use PHPUnit\Framework\TestCase;

/**
 * SEP-2322 requestState integrity protection: HMAC-signed payloads
 * round-trip, every form of tampering is rejected, expiry is enforced
 * (replay mitigation), states are unique per encode (nonce), and the
 * file-backed default secret is shared across codec instances (the
 * multi-process PHP hosting requirement).
 */
final class RequestStateCodecTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $codec = new RequestStateCodec('test-secret');
        $state = $codec->encode(['m' => 'tools/call', 'n' => 'ask', 'res' => ['k' => ['a' => 1]]]);
        $payload = $codec->decode($state);
        $this->assertIsArray($payload);
        $this->assertSame('tools/call', $payload['m']);
        $this->assertSame(['k' => ['a' => 1]], $payload['res']);
    }

    public function testTamperedSuffixRejected(): void
    {
        $codec = new RequestStateCodec('test-secret');
        $state = $codec->encode(['m' => 'tools/call', 'n' => 'ask']);
        $this->assertNull($codec->decode($state . '-TAMPERED'));
    }

    public function testFlippedCharacterRejected(): void
    {
        $codec = new RequestStateCodec('test-secret');
        $state = $codec->encode(['m' => 'tools/call', 'n' => 'ask']);
        $mutated = $state;
        $mutated[5] = $mutated[5] === 'A' ? 'B' : 'A';
        $this->assertNull($codec->decode($mutated));
    }

    public function testWrongSecretRejected(): void
    {
        $a = new RequestStateCodec('secret-a');
        $b = new RequestStateCodec('secret-b');
        $this->assertNull($b->decode($a->encode(['m' => 'x', 'n' => 'y'])));
    }

    public function testExpiryEnforced(): void
    {
        $codec = new RequestStateCodec('test-secret', -1);
        $this->assertNull($codec->decode($codec->encode(['m' => 'x', 'n' => 'y'])));
    }

    public function testGarbageRejected(): void
    {
        $codec = new RequestStateCodec('test-secret');
        $this->assertNull($codec->decode('not-a-state'));
        $this->assertNull($codec->decode(''));
        $this->assertNull($codec->decode(base64_encode('{"no":"mac"}')));
    }

    public function testStatesAreUniquePerEncode(): void
    {
        $codec = new RequestStateCodec('test-secret');
        $payload = ['m' => 'tools/call', 'n' => 'ask', 'res' => []];
        $this->assertNotSame($codec->encode($payload), $codec->encode($payload));
    }

    public function testUnavailableSecretPathThrows(): void
    {
        // A secret that can be neither created nor read must FAIL LOUDLY:
        // a silent process-local fallback would make every cross-worker
        // requestState fail verification in undiagnosable ways.
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'mcp-no-such-dir-' . bin2hex(random_bytes(6))
            . DIRECTORY_SEPARATOR . 'secret';

        $this->expectException(\RuntimeException::class);
        RequestStateCodec::withFileSecret($path);
    }

    public function testRaceLoserReadsWinnersSecret(): void
    {
        // Simulates losing the exclusive-create race: the file already
        // exists with the winner's secret; a second initializer must read
        // it rather than overwrite it.
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp-test-secret-' . bin2hex(random_bytes(6));
        try {
            $winner = bin2hex(random_bytes(32));
            file_put_contents($path, $winner);

            $codec = RequestStateCodec::withFileSecret($path);
            $reference = new RequestStateCodec($winner);
            $this->assertIsArray(
                $reference->decode($codec->encode(['m' => 'tools/call', 'n' => 'ask'])),
                'The loser must adopt the pre-existing secret, never replace it'
            );
            $this->assertSame($winner, file_get_contents($path), 'The secret file is never overwritten');
        } finally {
            @unlink($path);
        }
    }

    public function testFileSecretSharedAcrossInstances(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp-test-secret-' . bin2hex(random_bytes(6));
        try {
            $a = RequestStateCodec::withFileSecret($path);
            $b = RequestStateCodec::withFileSecret($path);
            $state = $a->encode(['m' => 'tools/call', 'n' => 'ask']);
            $this->assertIsArray($b->decode($state), 'A second process must verify the first one\'s state');
        } finally {
            @unlink($path);
        }
    }
}
