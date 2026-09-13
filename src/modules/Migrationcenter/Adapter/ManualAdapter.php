<?php

declare(strict_types=1);

/**
 * Migration Center — the only adapter shipped in v1.
 *
 * Every method throws. Migration Center v1 is staff-operated: nothing in the
 * module calls an adapter, and this class exists so the v2 interface has a
 * concrete implementation without pretending that automation works.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Adapter;

final class ManualAdapter implements MigrationAdapterInterface
{
    private const string MESSAGE = 'Migrations are performed manually by support staff. Automated transfer is not implemented.';

    public function connect(array $credentials): void
    {
        throw new \FOSSBilling\InformationException(self::MESSAGE);
    }

    public function snapshot(): MigrationSnapshot
    {
        throw new \FOSSBilling\InformationException(self::MESSAGE);
    }

    public function transfer(MigrationSnapshot $snapshot): void
    {
        throw new \FOSSBilling\InformationException(self::MESSAGE);
    }

    public function verify(): bool
    {
        throw new \FOSSBilling\InformationException(self::MESSAGE);
    }
}
