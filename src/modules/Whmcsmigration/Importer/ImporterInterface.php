<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Whmcsmigration\Importer;

use Box\Mod\Whmcsmigration\Support\IdMap;
use Box\Mod\Whmcsmigration\Support\SourceDb;

interface ImporterInterface
{
    public function setDi(\Pimple\Container $di): void;

    public function setSourceDb(SourceDb $sourceDb): void;

    public function setIdMap(IdMap $idMap): void;

    /** Entity key used in run/staged/idmap tables ('client', 'currency', ...). */
    public function entity(): string;

    /**
     * Count source rows and surface blocking warnings before anything runs.
     *
     * @return array{total: int, warnings: string[]}
     */
    public function analyze(): array;

    /**
     * Transform the next batch of source rows into staged payloads.
     *
     * @return array{staged: int, needs_mapping: int, skipped: int, failed: int, done: bool}
     */
    public function stageBatch(int $limit): array;

    /**
     * Insert the next batch of staged payloads into the live tables.
     *
     * @return array{committed: int, failed: int, done: bool}
     */
    public function commitBatch(int $limit): array;
}
