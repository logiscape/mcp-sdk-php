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

    public function testCrashedWriterStubIsReclaimed(): void
    {
        // Regression (WS3 post-commit review): a writer that died between
        // creating the file and writing the secret used to leave a stub
        // that PERMANENTLY blocked initialization — the exclusive create
        // failed (the file exists) and the read loop never saw a complete
        // secret. The stub must instead be reclaimed under the lock.
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp-test-secret-' . bin2hex(random_bytes(6));
        try {
            file_put_contents($path, ''); // zero-byte crash remnant

            $a = RequestStateCodec::withFileSecret($path);
            $b = RequestStateCodec::withFileSecret($path);
            $this->assertIsArray(
                $b->decode($a->encode(['m' => 'tools/call', 'n' => 'ask'])),
                'A reclaimed secret must be shared across instances like any other'
            );
            $this->assertGreaterThanOrEqual(
                32,
                strlen((string) file_get_contents($path)),
                'Reclaiming must leave a complete secret on disk'
            );
        } finally {
            @unlink($path);
        }
    }

    public function testUndersizedStubIsReclaimed(): void
    {
        // Same as above for a PARTIAL crash remnant: anything shorter than
        // a complete secret is a stub, never adopted as a (weak) secret.
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp-test-secret-' . bin2hex(random_bytes(6));
        try {
            file_put_contents($path, 'too-short');

            $codec = RequestStateCodec::withFileSecret($path);
            $this->assertIsArray($codec->decode($codec->encode(['m' => 'x', 'n' => 'y'])));

            $stored = (string) file_get_contents($path);
            $this->assertGreaterThanOrEqual(32, strlen($stored));
            $this->assertStringNotContainsString('too-short', $stored, 'The stub must be fully replaced');
        } finally {
            @unlink($path);
        }
    }

    public function testExplicitPathSkipsHardeningByDefault(): void
    {
        // The operator chose the location: a pre-existing secret at an
        // explicit path is adopted without the multi-tenant ownership and
        // permission checks (those default on only for the predictable
        // shared-temp path).
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp-test-secret-' . bin2hex(random_bytes(6));
        try {
            $winner = bin2hex(random_bytes(32));
            file_put_contents($path, $winner);
            @chmod($path, 0644);

            $codec = RequestStateCodec::withFileSecret($path);
            $this->assertIsArray(
                (new RequestStateCodec($winner))->decode($codec->encode(['m' => 'x', 'n' => 'y']))
            );
        } finally {
            @unlink($path);
        }
    }

    public function testHardenedPathRejectsFileReadableByOthers(): void
    {
        // Multi-tenant hardening (WS3 post-commit review): the default
        // shared-temp secret path is predictable, so a pre-existing file
        // readable by other users must be refused loudly — adopting a
        // secret a co-tenant can read (or planted) would let them forge
        // requestState. POSIX-only because Windows does not expose the same
        // permission-bit contract.
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission bits are not available on Windows');
        }

        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp-test-secret-' . bin2hex(random_bytes(6));
        try {
            file_put_contents($path, bin2hex(random_bytes(32)));
            chmod($path, 0644);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('allow other users');
            RequestStateCodec::withFileSecret($path, 300, verifyOwnership: true);
        } finally {
            @unlink($path);
        }
    }

    public function testHardenedPathRejectsSymlink(): void
    {
        // A co-tenant aiming a symlink at the predictable default path
        // could redirect the secret read/write somewhere hostile; the
        // hardened path refuses symlinks outright.
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Requires a POSIX host');
        }

        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp-test-secret-' . bin2hex(random_bytes(6));
        $target = $base . '-target';
        $link = $base . '-link';
        try {
            file_put_contents($target, bin2hex(random_bytes(32)));
            chmod($target, 0600);
            if (!@symlink($target, $link)) {
                $this->markTestSkipped('Cannot create symlinks in this environment');
            }

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('symlink');
            RequestStateCodec::withFileSecret($link, 300, verifyOwnership: true);
        } finally {
            @unlink($link);
            @unlink($target);
        }
    }

    public function testHardenedCreationLeavesOwnerOnlyPermissions(): void
    {
        // The writer locks the file to 0600 BEFORE the secret is written,
        // so the secret is never readable by other users even momentarily;
        // verify the end state.
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('File permission bits are not meaningful on this platform');
        }

        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp-test-secret-' . bin2hex(random_bytes(6));
        try {
            RequestStateCodec::withFileSecret($path, 300, verifyOwnership: true);
            clearstatcache(true, $path);
            $this->assertSame(0600, fileperms($path) & 0777);
        } finally {
            @unlink($path);
        }
    }
}
