<?php

declare(strict_types=1);

/**
 * Migration Center — request status vocabulary and transition rules.
 *
 * Pure domain logic: no DI, no database, no side effects.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Support;

final class RequestStatus
{
    public const string SUBMITTED = 'submitted';

    public const string IN_PROGRESS = 'in_progress';

    public const string COMPLETED = 'completed';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';

    public const array ALL = [
        self::SUBMITTED,
        self::IN_PROGRESS,
        self::COMPLETED,
        self::FAILED,
        self::CANCELLED,
    ];

    /**
     * Statuses in which the migration is finished for good and the stored
     * credentials are no longer needed, so staff may purge them.
     */
    private const array PURGEABLE = [self::COMPLETED, self::FAILED, self::CANCELLED];

    /**
     * Allowed staff-driven status moves. Completed and cancelled are terminal;
     * failed may be retried by moving back to in_progress.
     */
    private const array TRANSITIONS = [
        self::SUBMITTED => [self::IN_PROGRESS, self::COMPLETED, self::FAILED, self::CANCELLED],
        self::IN_PROGRESS => [self::COMPLETED, self::FAILED, self::CANCELLED],
        self::COMPLETED => [],
        self::FAILED => [self::IN_PROGRESS, self::CANCELLED],
        self::CANCELLED => [],
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        if (!self::isValid($from) || !self::isValid($to)) {
            return false;
        }

        return in_array($to, self::TRANSITIONS[$from], true);
    }

    public static function isPurgeable(string $status): bool
    {
        return in_array($status, self::PURGEABLE, true);
    }

    /**
     * A client may withdraw their own request only while no staff member has
     * started working on it.
     */
    public static function isClientCancellable(string $status): bool
    {
        return $status === self::SUBMITTED;
    }
}
