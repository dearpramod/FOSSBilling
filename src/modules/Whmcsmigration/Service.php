<?php

declare(strict_types=1);

/**
 * WHMCS Migration Module for FOSSBilling.
 *
 * Imports a WHMCS installation part-by-part via a stage → review → commit
 * pipeline. Reads the WHMCS database directly (read-only PDO); a persistent
 * id-map makes every part idempotent and re-runnable.
 *
 * Design reference: meropanel/whmcs-migration-module.md
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Whmcsmigration;

use Box\Mod\Whmcsmigration\Importer\ClientGroupImporter;
use Box\Mod\Whmcsmigration\Importer\ClientImporter;
use Box\Mod\Whmcsmigration\Importer\CurrencyImporter;
use Box\Mod\Whmcsmigration\Importer\ImporterInterface;
use Box\Mod\Whmcsmigration\Support\IdMap;
use Box\Mod\Whmcsmigration\Support\SourceDb;

class Service implements \FOSSBilling\InjectionAwareInterface
{
    /**
     * Parts and their entities, in import order. Parts B-F are placeholders
     * until their importers ship; the UI renders them locked.
     */
    public const PARTS = [
        'A' => ['label' => 'Clients', 'entities' => ['currency', 'client_group', 'client'], 'depends' => []],
        'B' => ['label' => 'Catalogue', 'entities' => ['product_category', 'product'], 'depends' => []],
        'C' => ['label' => 'Services', 'entities' => ['server', 'hosting_service', 'domain'], 'depends' => ['A', 'B']],
        'D' => ['label' => 'Invoices', 'entities' => ['invoice', 'invoice_item', 'transaction'], 'depends' => ['A', 'C']],
        'E' => ['label' => 'Support', 'entities' => ['helpdesk', 'ticket'], 'depends' => ['A']],
        'F' => ['label' => 'Wrap-up', 'entities' => [], 'depends' => ['A', 'B', 'C', 'D', 'E']],
    ];

    protected ?\Pimple\Container $di = null;

    private ?SourceDb $sourceDb = null;
    private ?IdMap $idMap = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return [
            'can_migrate' => [
                'type' => 'bool',
                'display_name' => __trans('Run WHMCS migration'),
                'description' => __trans('Allows the staff member to connect to a WHMCS database and stage, review, commit or discard migration data.'),
            ],
            'manage_settings' => [],
        ];
    }

    public function install(): bool
    {
        $dbal = $this->di['dbal'];

        $dbal->executeStatement('
            CREATE TABLE IF NOT EXISTS `mod_whmcsmigration_idmap` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entity`     VARCHAR(50)  NOT NULL,
                `whmcs_id`   BIGINT       NOT NULL,
                `fb_id`      BIGINT       NOT NULL,
                `created_at` DATETIME     NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_entity_whmcs` (`entity`, `whmcs_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        $dbal->executeStatement("
            CREATE TABLE IF NOT EXISTS `mod_whmcsmigration_run` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entity`      VARCHAR(50)  NOT NULL,
                `last_offset` INT          NOT NULL DEFAULT 0,
                `total`       INT          NOT NULL DEFAULT 0,
                `staged`      INT          NOT NULL DEFAULT 0,
                `committed`   INT          NOT NULL DEFAULT 0,
                `skipped`     INT          NOT NULL DEFAULT 0,
                `failed`      INT          NOT NULL DEFAULT 0,
                `status`      VARCHAR(20)  NOT NULL DEFAULT 'pending',
                `errors`      MEDIUMTEXT   NULL,
                `updated_at`  DATETIME     NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_entity` (`entity`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $dbal->executeStatement("
            CREATE TABLE IF NOT EXISTS `mod_whmcsmigration_staged` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entity`     VARCHAR(50)  NOT NULL,
                `whmcs_id`   BIGINT       NOT NULL,
                `payload`    MEDIUMTEXT   NOT NULL,
                `issues`     TEXT         NULL,
                `status`     VARCHAR(20)  NOT NULL DEFAULT 'staged',
                `created_at` DATETIME     NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_entity_whmcs` (`entity`, `whmcs_id`),
                KEY `idx_entity_status` (`entity`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        return true;
    }

    public function uninstall(): bool
    {
        $dbal = $this->di['dbal'];
        $dbal->executeStatement('DROP TABLE IF EXISTS `mod_whmcsmigration_staged`');
        $dbal->executeStatement('DROP TABLE IF EXISTS `mod_whmcsmigration_run`');
        $dbal->executeStatement('DROP TABLE IF EXISTS `mod_whmcsmigration_idmap`');

        return true;
    }

    /* ================================================================
     *  Component accessors
     * ================================================================ */

    public function getSourceDb(): SourceDb
    {
        if ($this->sourceDb === null) {
            $config = $this->di['mod_service']('extension')->getConfig('mod_whmcsmigration');
            $this->sourceDb = new SourceDb($config);
        }

        return $this->sourceDb;
    }

    public function getIdMap(): IdMap
    {
        return $this->idMap ??= new IdMap($this->di['dbal']);
    }

    public function getImporter(string $entity): ImporterInterface
    {
        $importer = match ($entity) {
            'currency' => new CurrencyImporter(),
            'client_group' => new ClientGroupImporter(),
            'client' => new ClientImporter(),
            default => throw new \FOSSBilling\InformationException('Importer for :entity is not available yet', [':entity' => $entity]),
        };
        $importer->setDi($this->di);
        $importer->setSourceDb($this->getSourceDb());
        $importer->setIdMap($this->getIdMap());

        return $importer;
    }

    /* ================================================================
     *  Run state
     * ================================================================ */

    /**
     * Full state for the admin UI: per-entity run rows plus a per-part rollup
     * with lock/unlock state derived from dependencies.
     */
    public function getRunStatus(): array
    {
        $rows = $this->di['dbal']->fetchAllAssociative('SELECT * FROM mod_whmcsmigration_run');
        $byEntity = [];
        foreach ($rows as $row) {
            $row['errors'] = !empty($row['errors']) ? json_decode($row['errors'], true) : [];
            $byEntity[$row['entity']] = $row;
        }

        $parts = [];
        foreach (self::PARTS as $key => $part) {
            $entities = [];
            $allDone = $part['entities'] !== [];
            foreach ($part['entities'] as $entity) {
                $run = $byEntity[$entity] ?? ['entity' => $entity, 'status' => 'pending', 'total' => 0, 'staged' => 0, 'committed' => 0, 'skipped' => 0, 'failed' => 0, 'last_offset' => 0, 'errors' => []];
                $entities[] = $run;
                if ($run['status'] !== 'done') {
                    $allDone = false;
                }
            }

            $implemented = in_array($key, ['A'], true);
            $depsMet = true;
            foreach ($part['depends'] as $dep) {
                if (!($parts[$dep]['done'] ?? false)) {
                    $depsMet = false;
                }
            }

            $parts[$key] = [
                'label' => $part['label'],
                'entities' => $entities,
                'implemented' => $implemented,
                'locked' => !$implemented || !$depsMet,
                'done' => $allDone,
            ];
        }

        return ['parts' => $parts];
    }

    public function getRunRow(string $entity): array
    {
        $row = $this->di['dbal']->fetchAssociative('SELECT * FROM mod_whmcsmigration_run WHERE entity = ?', [$entity]);
        if (!$row) {
            $this->di['dbal']->executeStatement(
                'INSERT INTO mod_whmcsmigration_run (entity, status, updated_at) VALUES (?, ?, ?)',
                [$entity, 'pending', date('Y-m-d H:i:s')]
            );
            $row = $this->di['dbal']->fetchAssociative('SELECT * FROM mod_whmcsmigration_run WHERE entity = ?', [$entity]);
        }

        return $row;
    }

    public function updateRunRow(string $entity, array $fields): void
    {
        $sets = [];
        $params = [];
        foreach ($fields as $col => $value) {
            $sets[] = "`{$col}` = ?";
            $params[] = $value;
        }
        $sets[] = '`updated_at` = ?';
        $params[] = date('Y-m-d H:i:s');
        $params[] = $entity;

        $this->di['dbal']->executeStatement(
            'UPDATE mod_whmcsmigration_run SET ' . implode(', ', $sets) . ' WHERE entity = ?',
            $params
        );
    }

    /**
     * Discard staged rows and reset the run cursor for an entity.
     * Never touches the id-map: committed rows stay committed.
     */
    public function resetEntity(string $entity): void
    {
        $this->di['dbal']->executeStatement(
            "DELETE FROM mod_whmcsmigration_staged WHERE entity = ? AND status != 'committed'",
            [$entity]
        );
        $this->di['dbal']->executeStatement(
            "UPDATE mod_whmcsmigration_run SET last_offset = 0, staged = 0, skipped = 0, failed = 0, status = 'pending', errors = NULL, updated_at = ? WHERE entity = ?",
            [date('Y-m-d H:i:s'), $entity]
        );
    }
}
