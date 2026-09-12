<?php

/**
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Box\Mod\Migrationcenter\Support\RequestStatus;

describe('isValid', function (): void {
    test('accepts every known status', function (): void {
        foreach (RequestStatus::ALL as $status) {
            expect(RequestStatus::isValid($status))->toBeTrue();
        }
    });

    test('rejects unknown statuses', function (): void {
        expect(RequestStatus::isValid('pending'))->toBeFalse()
            ->and(RequestStatus::isValid(''))->toBeFalse()
            ->and(RequestStatus::isValid('SUBMITTED'))->toBeFalse();
    });
});

describe('canTransition', function (): void {
    test('submitted can move to any working or terminal state', function (): void {
        expect(RequestStatus::canTransition(RequestStatus::SUBMITTED, RequestStatus::IN_PROGRESS))->toBeTrue()
            ->and(RequestStatus::canTransition(RequestStatus::SUBMITTED, RequestStatus::COMPLETED))->toBeTrue()
            ->and(RequestStatus::canTransition(RequestStatus::SUBMITTED, RequestStatus::FAILED))->toBeTrue()
            ->and(RequestStatus::canTransition(RequestStatus::SUBMITTED, RequestStatus::CANCELLED))->toBeTrue();
    });

    test('failed can be retried but completed and cancelled are terminal', function (): void {
        expect(RequestStatus::canTransition(RequestStatus::FAILED, RequestStatus::IN_PROGRESS))->toBeTrue()
            ->and(RequestStatus::canTransition(RequestStatus::COMPLETED, RequestStatus::IN_PROGRESS))->toBeFalse()
            ->and(RequestStatus::canTransition(RequestStatus::CANCELLED, RequestStatus::IN_PROGRESS))->toBeFalse();
    });

    test('a status never transitions to itself', function (): void {
        foreach (RequestStatus::ALL as $status) {
            expect(RequestStatus::canTransition($status, $status))->toBeFalse();
        }
    });

    test('unknown statuses never transition', function (): void {
        expect(RequestStatus::canTransition('bogus', RequestStatus::COMPLETED))->toBeFalse()
            ->and(RequestStatus::canTransition(RequestStatus::SUBMITTED, 'bogus'))->toBeFalse();
    });

    test('in_progress can move to all terminal states and failed can be cancelled', function (): void {
        expect(RequestStatus::canTransition(RequestStatus::IN_PROGRESS, RequestStatus::COMPLETED))->toBeTrue()
            ->and(RequestStatus::canTransition(RequestStatus::IN_PROGRESS, RequestStatus::FAILED))->toBeTrue()
            ->and(RequestStatus::canTransition(RequestStatus::IN_PROGRESS, RequestStatus::CANCELLED))->toBeTrue()
            ->and(RequestStatus::canTransition(RequestStatus::FAILED, RequestStatus::CANCELLED))->toBeTrue();
    });
});

describe('isPurgeable', function (): void {
    test('only finished requests may have their secret purged', function (): void {
        expect(RequestStatus::isPurgeable(RequestStatus::COMPLETED))->toBeTrue()
            ->and(RequestStatus::isPurgeable(RequestStatus::FAILED))->toBeTrue()
            ->and(RequestStatus::isPurgeable(RequestStatus::CANCELLED))->toBeTrue()
            ->and(RequestStatus::isPurgeable(RequestStatus::SUBMITTED))->toBeFalse()
            ->and(RequestStatus::isPurgeable(RequestStatus::IN_PROGRESS))->toBeFalse();
    });
});

describe('isClientCancellable', function (): void {
    test('a client may only cancel before staff pick the request up', function (): void {
        expect(RequestStatus::isClientCancellable(RequestStatus::SUBMITTED))->toBeTrue()
            ->and(RequestStatus::isClientCancellable(RequestStatus::IN_PROGRESS))->toBeFalse()
            ->and(RequestStatus::isClientCancellable(RequestStatus::COMPLETED))->toBeFalse()
            ->and(RequestStatus::isClientCancellable(RequestStatus::FAILED))->toBeFalse()
            ->and(RequestStatus::isClientCancellable(RequestStatus::CANCELLED))->toBeFalse();
    });
});
