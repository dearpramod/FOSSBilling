<?php

declare(strict_types=1);

/**
 * Migration Center — control panel vocabulary for the previous host.
 *
 * Pure domain logic: no DI, no database, no side effects.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Support;

final class PanelType
{
    public const string CPANEL_WHM = 'cpanel_whm';

    public const string PLESK = 'plesk';

    public const string DIRECTADMIN = 'directadmin';

    public const string SSH_CUSTOM = 'ssh_custom';

    public const string OTHER = 'other';

    public const array ALL = [
        self::CPANEL_WHM,
        self::PLESK,
        self::DIRECTADMIN,
        self::SSH_CUSTOM,
        self::OTHER,
    ];

    private const array LABELS = [
        self::CPANEL_WHM => 'cPanel / WHM',
        self::PLESK => 'Plesk',
        self::DIRECTADMIN => 'DirectAdmin',
        self::SSH_CUSTOM => 'SSH / Custom',
        self::OTHER => 'Other',
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }
}
