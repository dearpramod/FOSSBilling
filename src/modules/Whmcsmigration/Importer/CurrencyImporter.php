<?php

declare(strict_types=1);

/**
 * tblcurrencies → currency.
 *
 * FOSSBilling 0.8.4 currency rows are just {code, is_default, conversion_rate};
 * display names/formats come from symfony/intl at render time.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Whmcsmigration\Importer;

class CurrencyImporter extends AbstractImporter
{
    public function entity(): string
    {
        return 'currency';
    }

    protected function countSql(): string
    {
        return 'SELECT COUNT(*) FROM tblcurrencies';
    }

    protected function batchSql(): string
    {
        return 'SELECT id, code, rate, `default` FROM tblcurrencies ORDER BY id';
    }

    protected function sourceIdColumn(): string
    {
        return 'id';
    }

    protected function analyzeWarnings(): array
    {
        $warnings = [];

        $whmcsDefault = $this->source->fetchAll('SELECT code FROM tblcurrencies WHERE `default` = 1 LIMIT 1');
        $whmcsCode = strtoupper(trim($whmcsDefault[0]['code'] ?? ''));

        $fbCode = (string) $this->di['dbal']->fetchOne('SELECT code FROM currency WHERE is_default = 1 LIMIT 1');

        if ($whmcsCode !== '' && strtoupper($fbCode) !== $whmcsCode) {
            $warnings[] = sprintf(
                'Default currency mismatch: WHMCS uses %s but FOSSBilling uses %s. All WHMCS amounts are stored in %s — set FOSSBilling\'s default currency to %s before committing anything.',
                $whmcsCode,
                $fbCode ?: '(none)',
                $whmcsCode,
                $whmcsCode,
            );
        }

        return $warnings;
    }

    protected function transform(array $row): array
    {
        $code = strtoupper(trim((string) $row['code']));
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            return ['payload' => [], 'issues' => [], 'skip' => "Invalid currency code '{$row['code']}'"];
        }

        $existingId = $this->di['dbal']->fetchOne('SELECT id FROM currency WHERE code = ?', [$code]);
        if ($existingId !== false) {
            return ['payload' => [], 'issues' => [], 'skip' => "Currency {$code} already exists in FOSSBilling", 'map_to' => (int) $existingId];
        }

        return [
            'payload' => [
                'code' => $code,
                'is_default' => (int) $row['default'] === 1 ? 1 : 0,
                'conversion_rate' => (string) $row['rate'],
            ],
            'issues' => [],
        ];
    }

    protected function persist(array $payload): int
    {
        // Currency is Doctrine-managed in 0.8.4 (no RedBean model) — plain insert.
        // Never flip the existing FOSSBilling default from an import.
        $dbal = $this->di['dbal'];
        $dbal->executeStatement(
            'INSERT INTO currency (code, is_default, conversion_rate, created_at, updated_at) VALUES (?, 0, ?, ?, ?)',
            [$payload['code'], $payload['conversion_rate'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
        );

        return (int) $dbal->lastInsertId();
    }
}
