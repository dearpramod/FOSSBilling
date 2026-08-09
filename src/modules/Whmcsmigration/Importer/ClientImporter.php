<?php

declare(strict_types=1);

/**
 * tblclients + tblusers (owner via tblusers_clients) → client (+ client_balance).
 *
 * Passwords are NEVER migrated (WHMCS bcrypt is incompatible; see design doc).
 * A random hash is set; clients use password reset on first login.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Whmcsmigration\Importer;

use Box\Mod\Whmcsmigration\Support\Normalizer;

class ClientImporter extends AbstractImporter
{
    public function entity(): string
    {
        return 'client';
    }

    protected function countSql(): string
    {
        return 'SELECT COUNT(*) FROM tblclients';
    }

    protected function batchSql(): string
    {
        // LEFT JOINs: WHMCS data quality is unreliable — clients without an
        // owner user row still import (name/email fall back to tblclients).
        return "
            SELECT
                c.id,
                u.id AS user_id,
                COALESCE(NULLIF(u.email, ''), c.email) AS email,
                COALESCE(NULLIF(u.first_name, ''), c.firstname) AS first_name,
                COALESCE(NULLIF(u.last_name, ''), c.lastname) AS last_name,
                u.language AS user_language,
                c.companyname, c.tax_id,
                c.address1, c.address2, c.city, c.state, c.postcode, c.country,
                c.phonenumber, c.credit, c.status, c.groupid, c.notes,
                c.taxexempt, c.language,
                CAST(c.datecreated AS CHAR) AS datecreated,
                c.created_at AS row_created_at,
                cur.code AS currency_code
            FROM tblclients c
            LEFT JOIN tblusers_clients uc ON uc.client_id = c.id AND uc.owner = 1
            LEFT JOIN tblusers u ON u.id = uc.auth_user_id
            LEFT JOIN tblcurrencies cur ON cur.id = c.currency
            ORDER BY c.id
        ";
    }

    protected function sourceIdColumn(): string
    {
        return 'id';
    }

    #[\Override]
    protected function analyzeWarnings(): array
    {
        $warnings = [];

        $orphanServices = $this->source->count(
            'SELECT COUNT(*) FROM tblhosting WHERE userid NOT IN (SELECT id FROM tblclients)'
        );
        if ($orphanServices > 0) {
            $warnings[] = "{$orphanServices} hosting services reference deleted clients (will be skipped in Part C).";
        }

        $noEmail = $this->source->count(
            "SELECT COUNT(*) FROM tblclients c
             LEFT JOIN tblusers_clients uc ON uc.client_id = c.id AND uc.owner = 1
             LEFT JOIN tblusers u ON u.id = uc.auth_user_id
             WHERE COALESCE(NULLIF(u.email, ''), c.email, '') = ''"
        );
        if ($noEmail > 0) {
            $warnings[] = "{$noEmail} clients have no email address and will be skipped.";
        }

        return $warnings;
    }

    /**
     * Client-scoped WHMCS custom field definitions, for the mapper UI.
     */
    public function getClientCustomFields(): array
    {
        return $this->source->fetchAll(
            "SELECT cf.id, cf.fieldname, cf.fieldtype, COUNT(cfv.id) AS value_count
             FROM tblcustomfields cf
             LEFT JOIN tblcustomfieldsvalues cfv ON cfv.fieldid = cf.id AND cfv.value != ''
             WHERE cf.type = 'client'
             GROUP BY cf.id, cf.fieldname, cf.fieldtype
             ORDER BY cf.id"
        );
    }

    protected function transform(array $row): array
    {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['payload' => [], 'issues' => [], 'skip' => 'Missing or invalid email: ' . ($row['email'] ?? '(empty)')];
        }

        // Upmind-style same-email auto-map: existing FOSSBilling client wins.
        $existingId = $this->di['dbal']->fetchOne('SELECT id FROM client WHERE email = ?', [$email]);
        if ($existingId !== false) {
            return ['payload' => [], 'issues' => [], 'skip' => "Client {$email} already exists in FOSSBilling — mapped to existing record", 'map_to' => (int) $existingId];
        }

        $customValues = $this->fetchCustomValues((int) $row['id']);

        return self::mapClientRow($row, $this->getCustomFieldMap(), $customValues, $this->getGroupIdResolver());
    }

    /**
     * Pure mapping of one joined WHMCS row → staged payload. Unit-test target:
     * everything DB-dependent is passed in.
     *
     * @param array     $row            joined source row (see batchSql)
     * @param array     $customFieldMap {whmcs_field_id: 'custom_N'}
     * @param array     $customValues   {whmcs_field_id: value}
     * @param ?callable $resolveGroupId fn(int $whmcsGroupId): ?int
     */
    public static function mapClientRow(array $row, array $customFieldMap, array $customValues, ?callable $resolveGroupId = null): array
    {
        $issues = [];

        $phone = Normalizer::splitPhone($row['phonenumber'] ?? null);

        $groupId = null;
        $whmcsGroupId = (int) ($row['groupid'] ?? 0);
        if ($whmcsGroupId > 0 && $resolveGroupId !== null) {
            $groupId = $resolveGroupId($whmcsGroupId);
            if ($groupId === null) {
                $issues[] = "WHMCS client group #{$whmcsGroupId} is not committed yet — will be re-resolved at commit time";
            }
        }

        $custom = [];
        foreach ($customFieldMap as $fieldId => $target) {
            if (!preg_match('/^custom_(?:[1-9]|1[0-9]|20)$/', (string) $target)) {
                continue;
            }
            $value = Normalizer::emptyToNull($customValues[(int) $fieldId] ?? null);
            if ($value !== null) {
                $custom[$target] = $value;
            }
        }

        $unmapped = array_diff_key($customValues, $customFieldMap);
        foreach ($unmapped as $fieldId => $value) {
            if (Normalizer::emptyToNull((string) $value) !== null) {
                $issues[] = "Custom field #{$fieldId} has a value but no mapping — not imported";
            }
        }

        $createdAt = Normalizer::zeroDateToNull($row['row_created_at'] ?? null)
            ?? Normalizer::zeroDateToNull($row['datecreated'] ?? null)
            ?? date('Y-m-d H:i:s');
        // datecreated is a DATE column — normalize to datetime.
        if (strlen($createdAt) === 10) {
            $createdAt .= ' 00:00:00';
        }

        $payload = [
            'client' => array_merge([
                'aid' => Normalizer::emptyToNull((string) ($row['user_id'] ?? '')),
                'email' => strtolower(trim((string) $row['email'])),
                'first_name' => Normalizer::emptyToNull($row['first_name'] ?? null),
                'last_name' => Normalizer::emptyToNull($row['last_name'] ?? null),
                'company' => Normalizer::emptyToNull($row['companyname'] ?? null),
                'company_vat' => Normalizer::emptyToNull($row['tax_id'] ?? null),
                'address_1' => Normalizer::emptyToNull($row['address1'] ?? null),
                'address_2' => Normalizer::emptyToNull($row['address2'] ?? null),
                'city' => Normalizer::emptyToNull($row['city'] ?? null),
                'state' => Normalizer::emptyToNull($row['state'] ?? null),
                'postcode' => Normalizer::emptyToNull($row['postcode'] ?? null),
                'country' => Normalizer::emptyToNull($row['country'] ?? null),
                'phone_cc' => $phone['cc'],
                'phone' => $phone['number'],
                'currency' => Normalizer::emptyToNull($row['currency_code'] ?? null),
                'status' => Normalizer::clientStatus($row['status'] ?? null),
                'lang' => Normalizer::emptyToNull($row['user_language'] ?? null) ?? Normalizer::emptyToNull($row['language'] ?? null),
                'notes' => Normalizer::emptyToNull($row['notes'] ?? null),
                'tax_exempt' => !empty($row['taxexempt']) && strtolower((string) $row['taxexempt']) !== 'off' ? 1 : 0,
                'client_group_id' => $groupId,
                'created_at' => $createdAt,
            ], $custom),
        ];

        // Kept for commit-time re-resolution when groups commit after clients stage.
        if ($whmcsGroupId > 0 && $groupId === null) {
            $payload['whmcs_group_id'] = $whmcsGroupId;
        }

        $credit = (float) ($row['credit'] ?? 0);
        if ($credit > 0) {
            $payload['balance'] = [
                'amount' => $credit,
                'type' => 'whmcsmigration',
                'rel_id' => (string) $row['id'],
                'description' => 'Credit balance migrated from WHMCS (client #' . $row['id'] . ')',
            ];
        }

        return ['payload' => $payload, 'issues' => $issues];
    }

    protected function persist(array $payload): int
    {
        $c = $payload['client'];

        // Re-resolve the client group now that groups may have been committed.
        if (empty($c['client_group_id']) && !empty($payload['whmcs_group_id'])) {
            $c['client_group_id'] = $this->idMap->get('client_group', (int) $payload['whmcs_group_id']);
        }

        $model = $this->di['db']->dispense('Client');
        foreach ($c as $column => $value) {
            $model->{$column} = $value;
        }
        // Random unusable password — client must use password reset.
        $model->pass = $this->di['password']->hashIt(bin2hex(random_bytes(24)));
        $model->updated_at = date('Y-m-d H:i:s');
        $clientId = (int) $this->di['db']->store($model);

        if (isset($payload['balance'])) {
            $b = $this->di['db']->dispense('ClientBalance');
            $b->client_id = $clientId;
            $b->type = $payload['balance']['type'];
            $b->rel_id = $payload['balance']['rel_id'];
            $b->amount = $payload['balance']['amount'];
            $b->description = $payload['balance']['description'];
            $b->created_at = date('Y-m-d H:i:s');
            $b->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($b);
        }

        return $clientId;
    }

    private function fetchCustomValues(int $whmcsClientId): array
    {
        $rows = $this->source->fetchAll(
            "SELECT cfv.fieldid, cfv.value
             FROM tblcustomfieldsvalues cfv
             JOIN tblcustomfields cf ON cf.id = cfv.fieldid AND cf.type = 'client'
             WHERE cfv.relid = ?",
            [$whmcsClientId]
        );

        $values = [];
        foreach ($rows as $r) {
            $values[(int) $r['fieldid']] = $r['value'];
        }

        return $values;
    }

    private function getCustomFieldMap(): array
    {
        $config = $this->di['mod_service']('extension')->getConfig('mod_whmcsmigration');
        $map = json_decode((string) ($config['custom_field_map'] ?? ''), true);

        return is_array($map) ? $map : [];
    }

    private function getGroupIdResolver(): callable
    {
        return fn (int $whmcsGroupId): ?int => $this->idMap->get('client_group', $whmcsGroupId);
    }
}
