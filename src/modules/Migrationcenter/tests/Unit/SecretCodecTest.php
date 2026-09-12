<?php

/**
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Box\Mod\Migrationcenter\Support\SecretCodec;

function migrationCodec(): SecretCodec
{
    return new SecretCodec(new Box_Crypt(), 'test-passphrase-for-migrationcenter');
}

describe('encode', function (): void {
    test('the ciphertext never contains the plaintext', function (): void {
        $encoded = migrationCodec()->encode([
            'password' => 'sup3rsecret',
            'ssh_key' => 'BEGIN-KEY-MATERIAL',
        ]);

        expect($encoded)->not->toContain('sup3rsecret')
            ->and($encoded)->not->toContain('BEGIN-KEY-MATERIAL')
            ->and($encoded)->not->toBe('');
    });

    test('two encodings of the same secret differ (random IV)', function (): void {
        $codec = migrationCodec();
        $secret = ['password' => 'sup3rsecret', 'ssh_key' => ''];

        expect($codec->encode($secret))->not->toBe($codec->encode($secret));
    });
});

describe('decode', function (): void {
    test('round-trips a password and an SSH key', function (): void {
        $codec = migrationCodec();
        $encoded = $codec->encode(['password' => 'sup3rsecret', 'ssh_key' => 'BEGIN-KEY-MATERIAL']);

        expect($codec->decode($encoded))->toBe([
            'password' => 'sup3rsecret',
            'ssh_key' => 'BEGIN-KEY-MATERIAL',
        ]);
    });

    test('missing fields normalise to empty strings', function (): void {
        $codec = migrationCodec();
        $encoded = $codec->encode(['password' => 'only-a-password']);

        expect($codec->decode($encoded))->toBe([
            'password' => 'only-a-password',
            'ssh_key' => '',
        ]);
    });

    test('a purged (empty) secret decodes to empty strings without throwing', function (): void {
        expect(migrationCodec()->decode(''))->toBe(['password' => '', 'ssh_key' => '']);
    });

    test('unreadable ciphertext throws a generic error that leaks nothing', function (): void {
        migrationCodec()->decode('this-is-not-valid-ciphertext');
    })->throws(FOSSBilling\Exception::class, 'Stored migration credentials could not be read.');

    test('ciphertext from a different key is not readable', function (): void {
        $encoded = migrationCodec()->encode(['password' => 'sup3rsecret']);
        $otherCodec = new SecretCodec(new Box_Crypt(), 'a-completely-different-passphrase');

        $otherCodec->decode($encoded);
    })->throws(FOSSBilling\Exception::class, 'Stored migration credentials could not be read.');
});
