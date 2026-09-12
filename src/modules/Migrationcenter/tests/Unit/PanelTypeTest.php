<?php

/**
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Box\Mod\Migrationcenter\Support\PanelType;

describe('isValid', function (): void {
    test('accepts every known panel type', function (): void {
        foreach (PanelType::ALL as $type) {
            expect(PanelType::isValid($type))->toBeTrue();
        }
    });

    test('rejects unknown panel types', function (): void {
        expect(PanelType::isValid('whm'))->toBeFalse()
            ->and(PanelType::isValid(''))->toBeFalse();
    });
});

describe('label', function (): void {
    test('returns a human label for known types', function (): void {
        expect(PanelType::label(PanelType::CPANEL_WHM))->toBe('cPanel / WHM')
            ->and(PanelType::label(PanelType::SSH_CUSTOM))->toBe('SSH / Custom');
    });

    test('falls back to the raw value for unknown types', function (): void {
        expect(PanelType::label('mystery'))->toBe('mystery');
    });
});
