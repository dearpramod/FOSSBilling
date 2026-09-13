<?php

declare(strict_types=1);

/**
 * Migration Center — contract for an automated migration backend.
 *
 * Reserved for v2. Version 1 of this module is entirely manual: staff read
 * the submitted credentials out of the admin queue and migrate by hand, and
 * every request carries method = 'manual'. A future adapter (for example a
 * cPanel-to-cPanel transfer) implements this interface, and requests opt in
 * by setting method = 'automated' — without changing the table, the client
 * form, or the staff queue.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Adapter;

interface MigrationAdapterInterface
{
    /**
     * Authenticate against the previous host.
     *
     * @param array{password: string, ssh_key: string} $credentials
     *
     * @throws \FOSSBilling\Exception on authentication failure
     */
    public function connect(array $credentials): void;

    /**
     * Enumerate what exists on the previous host.
     */
    public function snapshot(): MigrationSnapshot;

    /**
     * Pull the snapshot's contents onto this server.
     */
    public function transfer(MigrationSnapshot $snapshot): void;

    /**
     * Post-transfer sanity check.
     */
    public function verify(): bool;
}
