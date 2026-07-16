<?php

/**
 * Tests for the pure client transform (ClientImporter::mapClientRow).
 * Every target field is asserted against its exact source to catch
 * copy-paste mapping bugs (the legacy importer wrote buyer_address = last_name).
 *
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Box\Mod\Whmcsmigration\Importer\ClientImporter;

function whmcsClientRow(array $overrides = []): array
{
    return array_merge([
        'id' => 42,
        'user_id' => 900,
        'email' => 'Jane.Doe@Example.COM',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'user_language' => 'english',
        'companyname' => 'Acme Pvt Ltd',
        'tax_id' => 'VAT-123',
        'address1' => '12 Hill Road',
        'address2' => '',
        'city' => 'Kathmandu',
        'state' => 'Bagmati',
        'postcode' => '44600',
        'country' => 'NP',
        'phonenumber' => '+977.9812345678',
        'credit' => '150.50',
        'status' => 'Active',
        'groupid' => 3,
        'notes' => 'VIP customer',
        'taxexempt' => '',
        'language' => '',
        'datecreated' => '2019-05-20',
        'row_created_at' => '2019-05-20 08:30:00',
        'currency_code' => 'NPR',
    ], $overrides);
}

test('maps every client field to its exact source', function (): void {
    $result = ClientImporter::mapClientRow(whmcsClientRow(), [], [], fn (int $gid): ?int => 7);

    $c = $result['payload']['client'];
    expect($c['aid'])->toBe('900')
        ->and($c['email'])->toBe('jane.doe@example.com')
        ->and($c['first_name'])->toBe('Jane')
        ->and($c['last_name'])->toBe('Doe')
        ->and($c['company'])->toBe('Acme Pvt Ltd')
        ->and($c['company_vat'])->toBe('VAT-123')
        ->and($c['address_1'])->toBe('12 Hill Road')
        ->and($c['address_2'])->toBeNull()
        ->and($c['city'])->toBe('Kathmandu')
        ->and($c['state'])->toBe('Bagmati')
        ->and($c['postcode'])->toBe('44600')
        ->and($c['country'])->toBe('NP')
        ->and($c['phone_cc'])->toBe(977)
        ->and($c['phone'])->toBe('9812345678')
        ->and($c['currency'])->toBe('NPR')
        ->and($c['status'])->toBe('active')
        ->and($c['lang'])->toBe('english')
        ->and($c['notes'])->toBe('VIP customer')
        ->and($c['tax_exempt'])->toBe(0)
        ->and($c['client_group_id'])->toBe(7)
        ->and($c['created_at'])->toBe('2019-05-20 08:30:00');
});

test('credit creates a balance ledger entry', function (): void {
    $result = ClientImporter::mapClientRow(whmcsClientRow(), [], []);

    expect($result['payload']['balance'])->toBe([
        'amount' => 150.50,
        'type' => 'whmcsmigration',
        'rel_id' => '42',
        'description' => 'Credit balance migrated from WHMCS (client #42)',
    ]);
});

test('zero credit produces no balance entry', function (): void {
    $result = ClientImporter::mapClientRow(whmcsClientRow(['credit' => '0.00']), [], []);

    expect($result['payload'])->not->toHaveKey('balance');
});

test('status and tax exemption map correctly', function (): void {
    $closed = ClientImporter::mapClientRow(whmcsClientRow(['status' => 'Closed', 'taxexempt' => 'on']), [], []);

    expect($closed['payload']['client']['status'])->toBe('canceled')
        ->and($closed['payload']['client']['tax_exempt'])->toBe(1);
});

test('mapped custom fields land in custom_N; unmapped values raise an issue', function (): void {
    $map = [5 => 'custom_1', 9 => 'custom_3'];
    $values = [5 => 'PAN-998877', 9 => 'Reseller', 11 => 'orphan-value'];

    $result = ClientImporter::mapClientRow(whmcsClientRow(), $map, $values);

    expect($result['payload']['client']['custom_1'])->toBe('PAN-998877')
        ->and($result['payload']['client']['custom_3'])->toBe('Reseller')
        ->and($result['issues'])->toContain('Custom field #11 has a value but no mapping — not imported');
});

test('invalid custom target columns are ignored', function (): void {
    $result = ClientImporter::mapClientRow(whmcsClientRow(), [5 => 'custom_99; DROP TABLE client'], [5 => 'x']);

    expect($result['payload']['client'])->not->toHaveKey('custom_99; DROP TABLE client');
});

test('unresolved client group keeps whmcs_group_id for commit-time re-resolution', function (): void {
    $result = ClientImporter::mapClientRow(whmcsClientRow(), [], [], fn (int $gid): ?int => null);

    expect($result['payload']['client']['client_group_id'])->toBeNull()
        ->and($result['payload']['whmcs_group_id'])->toBe(3)
        ->and($result['issues'])->toContain('WHMCS client group #3 is not committed yet — will be re-resolved at commit time');
});

test('resolved client group does not keep whmcs_group_id', function (): void {
    $result = ClientImporter::mapClientRow(whmcsClientRow(), [], [], fn (int $gid): ?int => 7);

    expect($result['payload']['client']['client_group_id'])->toBe(7)
        ->and($result['payload'])->not->toHaveKey('whmcs_group_id');
});

test('created_at falls back from row_created_at to datecreated (date-only normalized)', function (): void {
    $result = ClientImporter::mapClientRow(whmcsClientRow(['row_created_at' => '0000-00-00 00:00:00']), [], []);

    expect($result['payload']['client']['created_at'])->toBe('2019-05-20 00:00:00');
});

test('lang prefers tblusers language over tblclients', function (): void {
    $result = ClientImporter::mapClientRow(whmcsClientRow(['user_language' => '', 'language' => 'nepali']), [], []);

    expect($result['payload']['client']['lang'])->toBe('nepali');
});

test('payload never contains a password field', function (): void {
    $result = ClientImporter::mapClientRow(whmcsClientRow(), [], []);

    expect($result['payload']['client'])->not->toHaveKey('pass')
        ->and($result['payload']['client'])->not->toHaveKey('password');
});
