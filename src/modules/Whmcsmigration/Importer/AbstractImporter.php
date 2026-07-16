<?php

declare(strict_types=1);

/**
 * Shared stage/commit machinery for all entity importers.
 *
 * Hard rules (see design doc §3):
 *  - stageBatch never writes to live FOSSBilling tables;
 *  - commitBatch is the only phase writing live rows, inside a transaction,
 *    and never fires events or service-layer activation (no provisioning).
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Whmcsmigration\Importer;

use Box\Mod\Whmcsmigration\Support\IdMap;
use Box\Mod\Whmcsmigration\Support\SourceDb;

abstract class AbstractImporter implements ImporterInterface
{
    /** Abort the stage when more than this share of rows fail (after MIN_ROWS_FOR_ABORT). */
    private const FAILURE_RATE_ABORT = 0.05;
    private const MIN_ROWS_FOR_ABORT = 100;

    protected ?\Pimple\Container $di = null;
    protected SourceDb $source;
    protected IdMap $idMap;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function setSourceDb(SourceDb $sourceDb): void
    {
        $this->source = $sourceDb;
    }

    public function setIdMap(IdMap $idMap): void
    {
        $this->idMap = $idMap;
    }

    /** COUNT(*) SQL for the source rows. */
    abstract protected function countSql(): string;

    /** Batch SELECT — must be deterministically ordered by the source PK. */
    abstract protected function batchSql(): string;

    /** Source PK column name inside the fetched row. */
    abstract protected function sourceIdColumn(): string;

    /**
     * Pure transform of one source row.
     *
     * @return array{payload: array, issues: string[], needs_mapping?: bool, skip?: string}
     *                                                                                      skip: reason string — row is counted skipped, not staged
     */
    abstract protected function transform(array $row): array;

    /**
     * Insert one staged payload into the live tables; returns the FOSSBilling id.
     */
    abstract protected function persist(array $payload): int;

    public function analyze(): array
    {
        $this->source->assertSupportedSchema();
        $total = $this->source->count($this->countSql());

        $service = $this->di['mod_service']('whmcsmigration');
        $service->getRunRow($this->entity());
        $service->updateRunRow($this->entity(), ['total' => $total]);

        return ['total' => $total, 'warnings' => $this->analyzeWarnings()];
    }

    /** Override for entity-specific precondition warnings. */
    protected function analyzeWarnings(): array
    {
        return [];
    }

    public function stageBatch(int $limit): array
    {
        $service = $this->di['mod_service']('whmcsmigration');
        $run = $service->getRunRow($this->entity());
        $dbal = $this->di['dbal'];

        $rows = $this->source->fetchBatch($this->batchSql(), [], (int) $run['last_offset'], $limit);

        $result = ['staged' => 0, 'needs_mapping' => 0, 'skipped' => 0, 'failed' => 0, 'done' => false];
        $errors = $run['errors'] ? json_decode((string) $run['errors'], true) : [];

        foreach ($rows as $row) {
            $whmcsId = (int) $row[$this->sourceIdColumn()];

            try {
                if ($this->idMap->has($this->entity(), $whmcsId) || $this->isStaged($whmcsId)) {
                    ++$result['skipped'];

                    continue;
                }

                $t = $this->transform($row);

                if (isset($t['skip'])) {
                    ++$result['skipped'];
                    $errors[] = ['whmcs_id' => $whmcsId, 'level' => 'skip', 'message' => $t['skip']];
                    // A skip may still map to an existing FB row (e.g. duplicate email).
                    if (isset($t['map_to'])) {
                        $this->idMap->set($this->entity(), $whmcsId, (int) $t['map_to']);
                    }

                    continue;
                }

                $status = !empty($t['needs_mapping']) ? 'needs_mapping' : 'staged';
                $dbal->executeStatement(
                    'INSERT INTO mod_whmcsmigration_staged (entity, whmcs_id, payload, issues, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        $this->entity(),
                        $whmcsId,
                        json_encode($t['payload'], JSON_INVALID_UTF8_SUBSTITUTE),
                        $t['issues'] ? json_encode($t['issues'], JSON_INVALID_UTF8_SUBSTITUTE) : null,
                        $status,
                        date('Y-m-d H:i:s'),
                    ]
                );
                $status === 'staged' ? ++$result['staged'] : ++$result['needs_mapping'];
            } catch (\Throwable $e) {
                ++$result['failed'];
                $errors[] = ['whmcs_id' => $whmcsId, 'level' => 'error', 'message' => $e->getMessage()];
            }
        }

        $newOffset = (int) $run['last_offset'] + count($rows);
        $processed = $newOffset;
        $totalFailed = (int) $run['failed'] + $result['failed'];
        $done = count($rows) < $limit;

        $status = 'staging';
        if ($processed >= self::MIN_ROWS_FOR_ABORT && $totalFailed / max(1, $processed) > self::FAILURE_RATE_ABORT) {
            $status = 'error';
            $done = true;
        } elseif ($done) {
            $status = 'review';
        }

        $service->updateRunRow($this->entity(), [
            'last_offset' => $newOffset,
            'staged' => (int) $run['staged'] + $result['staged'] + $result['needs_mapping'],
            'skipped' => (int) $run['skipped'] + $result['skipped'],
            'failed' => $totalFailed,
            'status' => $status,
            'errors' => $errors ? json_encode(array_slice($errors, -500)) : null,
        ]);

        $result['done'] = $done;

        return $result;
    }

    public function commitBatch(int $limit): array
    {
        $service = $this->di['mod_service']('whmcsmigration');
        $run = $service->getRunRow($this->entity());
        $dbal = $this->di['dbal'];

        $staged = $dbal->fetchAllAssociative(
            "SELECT * FROM mod_whmcsmigration_staged WHERE entity = ? AND status = 'staged' ORDER BY id LIMIT " . max(1, $limit),
            [$this->entity()]
        );

        $result = ['committed' => 0, 'failed' => 0, 'done' => false];
        $errors = $run['errors'] ? json_decode((string) $run['errors'], true) : [];

        foreach ($staged as $row) {
            $dbal->beginTransaction();

            try {
                $payload = json_decode((string) $row['payload'], true);
                $fbId = $this->persist($payload);
                $this->idMap->set($this->entity(), (int) $row['whmcs_id'], $fbId);
                $dbal->executeStatement(
                    "UPDATE mod_whmcsmigration_staged SET status = 'committed' WHERE id = ?",
                    [$row['id']]
                );
                $dbal->commit();
                ++$result['committed'];
            } catch (\Throwable $e) {
                $dbal->rollBack();
                ++$result['failed'];
                $errors[] = ['whmcs_id' => (int) $row['whmcs_id'], 'level' => 'commit_error', 'message' => $e->getMessage()];
                // Leave the staged row untouched for inspection; mark run.
                $dbal->executeStatement(
                    "UPDATE mod_whmcsmigration_staged SET status = 'rejected' WHERE id = ?",
                    [$row['id']]
                );
            }
        }

        $remaining = (int) $dbal->fetchOne(
            "SELECT COUNT(*) FROM mod_whmcsmigration_staged WHERE entity = ? AND status = 'staged'",
            [$this->entity()]
        );
        $result['done'] = $remaining === 0;

        $service->updateRunRow($this->entity(), [
            'committed' => (int) $run['committed'] + $result['committed'],
            'failed' => (int) $run['failed'] + $result['failed'],
            'status' => $result['done'] ? 'done' : 'committing',
            'errors' => $errors ? json_encode(array_slice($errors, -500)) : null,
        ]);

        return $result;
    }

    private function isStaged(int $whmcsId): bool
    {
        return (bool) $this->di['dbal']->fetchOne(
            'SELECT 1 FROM mod_whmcsmigration_staged WHERE entity = ? AND whmcs_id = ?',
            [$this->entity(), $whmcsId]
        );
    }
}
