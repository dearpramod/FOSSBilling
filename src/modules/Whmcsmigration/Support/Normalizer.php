<?php

declare(strict_types=1);

/**
 * Pure transform helpers shared by all importers. Stateless by design so
 * every rule is unit-testable in isolation.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Whmcsmigration\Support;

use Symfony\Component\Intl\Currencies;

class Normalizer
{
    /**
     * WHMCS legacy zero-dates ('0000-00-00', '0000-00-00 00:00:00') → NULL.
     */
    public static function zeroDateToNull(?string $date): ?string
    {
        if ($date === null) {
            return null;
        }
        $date = trim($date);
        if ($date === '' || str_starts_with($date, '0000-00-00')) {
            return null;
        }

        return $date;
    }

    /**
     * WHMCS stores optional text columns as NOT NULL with '' defaults.
     */
    public static function emptyToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * WHMCS phone format is '+CC.number' (e.g. '+977.9812345678').
     * Falls back to the raw digits with no CC when the prefix is absent.
     *
     * @return array{cc: ?int, number: ?string}
     */
    public static function splitPhone(?string $phone): array
    {
        $phone = self::emptyToNull($phone);
        if ($phone === null) {
            return ['cc' => null, 'number' => null];
        }

        if (preg_match('/^\+(\d{1,4})\.(.+)$/', $phone, $m)) {
            return ['cc' => (int) $m[1], 'number' => preg_replace('/\D+/', '', $m[2]) ?: null];
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return ['cc' => null, 'number' => $digits !== '' ? $digits : null];
    }

    /**
     * WHMCS client status → FOSSBilling client status.
     */
    public static function clientStatus(?string $status): string
    {
        return match (strtolower(trim((string) $status))) {
            'inactive' => 'suspended',
            'closed' => 'canceled',
            default => 'active',
        };
    }

    /**
     * ISO currency code → human title ('NPR' → 'Nepalese Rupee').
     */
    public static function currencyTitle(string $code): string
    {
        $code = strtoupper(trim($code));

        try {
            return Currencies::getName($code);
        } catch (\Exception) {
            return $code;
        }
    }
}
