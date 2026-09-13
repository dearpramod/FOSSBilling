<?php

/**
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Box\Mod\Migrationcenter\Entity\MigrationRequest;
use Box\Mod\Migrationcenter\Support\PanelType;
use Box\Mod\Migrationcenter\Support\RequestStatus;

function migrationRequestFixture(): MigrationRequest
{
    return (new MigrationRequest())
        ->setClientId(2)
        ->setClientOrderId(7)
        ->setPanelType(PanelType::CPANEL_WHM)
        ->setHost('old-host.example.com')
        ->setPort(2083)
        ->setUsername('olduser')
        ->setSecretEncrypted('v2:ciphertext-goes-here')
        ->setClientNotes('WordPress site')
        ->setStaffNotes('Waiting on DNS TTL');
}

describe('toApiArray', function (): void {
    test('never exposes the encrypted secret or the staff notes', function (): void {
        $array = migrationRequestFixture()->toApiArray();

        expect($array)->not->toHaveKey('secret_encrypted')
            ->and($array)->not->toHaveKey('staff_notes')
            ->and(json_encode($array))->not->toContain('ciphertext-goes-here');
    });

    test('reports whether a secret is still stored', function (): void {
        $request = migrationRequestFixture();
        expect($request->toApiArray()['has_secret'])->toBeTrue();

        $request->purgeSecret();
        expect($request->toApiArray()['has_secret'])->toBeFalse();
    });

    test('carries the safe metadata a client needs to confirm what they sent', function (): void {
        $array = migrationRequestFixture()->toApiArray();

        expect($array['client_id'])->toBe(2)
            ->and($array['client_order_id'])->toBe(7)
            ->and($array['panel_type'])->toBe(PanelType::CPANEL_WHM)
            ->and($array['panel_type_label'])->toBe('cPanel / WHM')
            ->and($array['host'])->toBe('old-host.example.com')
            ->and($array['port'])->toBe(2083)
            ->and($array['username'])->toBe('olduser')
            ->and($array['status'])->toBe(RequestStatus::SUBMITTED)
            ->and($array['method'])->toBe('manual');
    });

    test('never exposes the internal assigned staff id', function (): void {
        $array = migrationRequestFixture()->setAssignedStaffId(5)->toApiArray();

        expect($array)->not->toHaveKey('assigned_staff_id');
    });
});

describe('toAdminApiArray', function (): void {
    test('adds the internal staff notes but still hides the secret', function (): void {
        $array = migrationRequestFixture()->toAdminApiArray();

        expect($array['staff_notes'])->toBe('Waiting on DNS TTL')
            ->and($array)->not->toHaveKey('secret_encrypted')
            ->and(json_encode($array))->not->toContain('ciphertext-goes-here');
    });

    test('adds the assigned staff id for staff use only', function (): void {
        $array = migrationRequestFixture()->setAssignedStaffId(5)->toAdminApiArray();

        expect($array['assigned_staff_id'])->toBe(5);
    });
});

describe('setStatus', function (): void {
    test('accepts a known status', function (): void {
        $request = migrationRequestFixture()->setStatus(RequestStatus::IN_PROGRESS);

        expect($request->getStatus())->toBe(RequestStatus::IN_PROGRESS);
    });

    test('rejects an unknown status', function (): void {
        migrationRequestFixture()->setStatus('pending');
    })->throws(FOSSBilling\InformationException::class, 'Unknown migration request status.');
});

describe('purgeSecret', function (): void {
    test('clears the ciphertext and records when it happened', function (): void {
        $request = migrationRequestFixture();
        expect($request->getSecretPurgedAt())->toBeNull();

        $request->purgeSecret();

        expect($request->getSecretEncrypted())->toBe('')
            ->and($request->getSecretPurgedAt())->toBeInstanceOf(DateTime::class);
    });
});
