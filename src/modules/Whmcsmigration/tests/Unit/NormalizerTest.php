<?php

/**
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Box\Mod\Whmcsmigration\Support\Normalizer;

describe('zeroDateToNull', function (): void {
    test('nulls WHMCS zero dates', function (): void {
        expect(Normalizer::zeroDateToNull('0000-00-00'))->toBeNull()
            ->and(Normalizer::zeroDateToNull('0000-00-00 00:00:00'))->toBeNull()
            ->and(Normalizer::zeroDateToNull(''))->toBeNull()
            ->and(Normalizer::zeroDateToNull(null))->toBeNull();
    });

    test('passes real dates through', function (): void {
        expect(Normalizer::zeroDateToNull('2024-03-01'))->toBe('2024-03-01')
            ->and(Normalizer::zeroDateToNull('2024-03-01 10:20:30'))->toBe('2024-03-01 10:20:30');
    });
});

describe('emptyToNull', function (): void {
    test('empty and whitespace become null', function (): void {
        expect(Normalizer::emptyToNull(''))->toBeNull()
            ->and(Normalizer::emptyToNull('   '))->toBeNull()
            ->and(Normalizer::emptyToNull(null))->toBeNull();
    });

    test('values are trimmed and kept', function (): void {
        expect(Normalizer::emptyToNull('  Kathmandu '))->toBe('Kathmandu');
    });
});

describe('splitPhone', function (): void {
    test('WHMCS +cc.number format', function (): void {
        expect(Normalizer::splitPhone('+977.9812345678'))->toBe(['cc' => 977, 'number' => '9812345678']);
    });

    test('number with formatting characters', function (): void {
        expect(Normalizer::splitPhone('+1.555-123 4567'))->toBe(['cc' => 1, 'number' => '5551234567']);
    });

    test('bare number without country prefix', function (): void {
        expect(Normalizer::splitPhone('9812345678'))->toBe(['cc' => null, 'number' => '9812345678']);
    });

    test('empty phone', function (): void {
        expect(Normalizer::splitPhone(''))->toBe(['cc' => null, 'number' => null])
            ->and(Normalizer::splitPhone(null))->toBe(['cc' => null, 'number' => null]);
    });
});

describe('clientStatus', function (): void {
    test('maps WHMCS statuses', function (): void {
        expect(Normalizer::clientStatus('Active'))->toBe('active')
            ->and(Normalizer::clientStatus('Inactive'))->toBe('suspended')
            ->and(Normalizer::clientStatus('Closed'))->toBe('canceled')
            ->and(Normalizer::clientStatus('anything-else'))->toBe('active')
            ->and(Normalizer::clientStatus(null))->toBe('active');
    });
});

describe('currencyTitle', function (): void {
    test('known code resolves to a name', function (): void {
        expect(Normalizer::currencyTitle('NPR'))->toBe('Nepalese Rupee')
            ->and(Normalizer::currencyTitle('usd'))->toBe('US Dollar');
    });

    test('unknown code falls back to the code', function (): void {
        expect(Normalizer::currencyTitle('XXZ'))->toBe('XXZ');
    });
});
