<?php

/**
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Box\Mod\Migrationcenter\Support\PanelType;
use Box\Mod\Migrationcenter\Support\RequestInput;

function validMigrationPayload(array $overrides = []): array
{
    return array_merge([
        'panel_type' => PanelType::CPANEL_WHM,
        'host' => 'old-host.example.com',
        'port' => '2083',
        'username' => 'olduser',
        'password' => 'sup3rsecret',
        'ssh_key' => '',
        'client_notes' => 'WordPress site, please keep the database.',
        'client_order_id' => '7',
    ], $overrides);
}

describe('fromClientData', function (): void {
    test('builds a value object from a complete payload', function (): void {
        $input = RequestInput::fromClientData(validMigrationPayload());

        expect($input->panelType)->toBe(PanelType::CPANEL_WHM)
            ->and($input->host)->toBe('old-host.example.com')
            ->and($input->port)->toBe(2083)
            ->and($input->username)->toBe('olduser')
            ->and($input->password)->toBe('sup3rsecret')
            ->and($input->sshKey)->toBe('')
            ->and($input->clientNotes)->toBe('WordPress site, please keep the database.')
            ->and($input->clientOrderId)->toBe(7);
    });

    test('trims host and username', function (): void {
        $input = RequestInput::fromClientData(validMigrationPayload([
            'host' => '  old-host.example.com  ',
            'username' => "  olduser\n",
        ]));

        expect($input->host)->toBe('old-host.example.com')
            ->and($input->username)->toBe('olduser');
    });

    test('omitted port, notes and order become null', function (): void {
        $input = RequestInput::fromClientData(validMigrationPayload([
            'port' => '',
            'client_notes' => '   ',
            'client_order_id' => '',
        ]));

        expect($input->port)->toBeNull()
            ->and($input->clientNotes)->toBeNull()
            ->and($input->clientOrderId)->toBeNull();
    });

    test('an SSH key alone satisfies the credential requirement', function (): void {
        $input = RequestInput::fromClientData(validMigrationPayload([
            'password' => '',
            'ssh_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
        ]));

        expect($input->password)->toBe('')
            ->and($input->sshKey)->toBe('-----BEGIN OPENSSH PRIVATE KEY-----');
    });

    test('rejects an unknown panel type', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['panel_type' => 'cpanel']));
    })->throws(FOSSBilling\InformationException::class, 'Please choose a valid control panel type.');

    test('rejects an empty host', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['host' => '   ']));
    })->throws(FOSSBilling\InformationException::class, 'The previous host address is required.');

    test('rejects a host longer than 255 characters', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['host' => str_repeat('a', 256)]));
    })->throws(FOSSBilling\InformationException::class, 'The previous host address is too long.');

    test('rejects an empty username', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['username' => '']));
    })->throws(FOSSBilling\InformationException::class, 'The username at your previous host is required.');

    test('rejects a port outside the valid range', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['port' => '70000']));
    })->throws(FOSSBilling\InformationException::class, 'The port must be a number between 1 and 65535.');

    test('rejects a non-numeric port', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['port' => 'ssh']));
    })->throws(FOSSBilling\InformationException::class, 'The port must be a number between 1 and 65535.');

    test('rejects a payload with neither password nor SSH key', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['password' => '', 'ssh_key' => '']));
    })->throws(FOSSBilling\InformationException::class, 'Provide either a password or an SSH key for your previous host.');

    test('rejects port 0', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['port' => '0']));
    })->throws(FOSSBilling\InformationException::class, 'The port must be a number between 1 and 65535.');

    test('order id of 0 becomes null', function (): void {
        $input = RequestInput::fromClientData(validMigrationPayload(['client_order_id' => '0']));

        expect($input->clientOrderId)->toBeNull();
    });

    test('rejects a non-numeric client order id', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['client_order_id' => 'abc']));
    })->throws(FOSSBilling\InformationException::class, 'The selected service is not valid.');

    test('rejects a partially numeric client order id', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['client_order_id' => '7abc']));
    })->throws(FOSSBilling\InformationException::class, 'The selected service is not valid.');
});
