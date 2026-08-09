<?php

declare(strict_types=1);

/**
 * Persistent WHMCS-id → FOSSBilling-id map. Rows are written at commit time
 * only; the map is what makes stages/parts idempotent and re-runnable.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Whmcsmigration\Support;

use Doctrine\DBAL\Connection;

class IdMap
{
    public function __construct(private readonly Connection $dbal)
    {
    }

    public function has(string $entity, int $whmcsId): bool
    {
        return $this->get($entity, $whmcsId) !== null;
    }

    public function get(string $entity, int $whmcsId): ?int
    {
        $id = $this->dbal->fetchOne(
            'SELECT fb_id FROM mod_whmcsmigration_idmap WHERE entity = ? AND whmcs_id = ?',
            [$entity, $whmcsId]
        );

        return $id === false ? null : (int) $id;
    }

    public function set(string $entity, int $whmcsId, int $fbId): void
    {
        $this->dbal->executeStatement(
            'INSERT IGNORE INTO mod_whmcsmigration_idmap (entity, whmcs_id, fb_id, created_at) VALUES (?, ?, ?, ?)',
            [$entity, $whmcsId, $fbId, date('Y-m-d H:i:s')]
        );
    }

    public function countFor(string $entity): int
    {
        return (int) $this->dbal->fetchOne(
            'SELECT COUNT(*) FROM mod_whmcsmigration_idmap WHERE entity = ?',
            [$entity]
        );
    }
}
