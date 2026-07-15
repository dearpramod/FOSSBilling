<?php

declare(strict_types=1);

/**
 * WHMCS Migration — admin API.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Whmcsmigration\Api;

use Box\Mod\Whmcsmigration\Service;
use FOSSBilling\Validation\Api\RequiredParams;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Test the configured WHMCS database connection.
     */
    public function connection_test(): array
    {
        $this->checkPermissions('whmcsmigration', 'can_migrate');

        return $this->getService()->getSourceDb()->testConnection();
    }

    /**
     * Analyze the entities of one part: source row counts + warnings.
     * For Part A, also returns the client-scoped custom field definitions
     * and the currently saved mapping for the mapper UI.
     */
    #[RequiredParams(['part' => 'Part key was not passed'])]
    public function analyze($data): array
    {
        $this->checkPermissions('whmcsmigration', 'can_migrate');

        $part = strtoupper((string) $data['part']);
        $partDef = Service::PARTS[$part] ?? null;
        if ($partDef === null) {
            throw new \FOSSBilling\InformationException('Unknown part :part', [':part' => $part]);
        }

        $service = $this->getService();
        $result = ['part' => $part, 'entities' => [], 'warnings' => []];

        foreach ($partDef['entities'] as $entity) {
            $importer = $service->getImporter($entity);
            $a = $importer->analyze();
            $result['entities'][$entity] = ['total' => $a['total']];
            foreach ($a['warnings'] as $w) {
                $result['warnings'][] = $w;
            }
        }

        if ($part === 'A') {
            /** @var \Box\Mod\Whmcsmigration\Importer\ClientImporter $clientImporter */
            $clientImporter = $service->getImporter('client');
            $config = $this->getDi()['mod_service']('extension')->getConfig('mod_whmcsmigration');
            $result['custom_fields'] = $clientImporter->getClientCustomFields();
            $result['custom_field_map'] = json_decode((string) ($config['custom_field_map'] ?? ''), true) ?: new \stdClass();
        }

        return $result;
    }

    /**
     * Save the WHMCS-custom-field → client.custom_N mapping.
     */
    #[RequiredParams(['map' => 'Mapping was not passed'])]
    public function save_custom_field_map($data): bool
    {
        $this->checkPermissions('whmcsmigration', 'can_migrate');

        $map = is_array($data['map']) ? $data['map'] : json_decode((string) $data['map'], true);
        if (!is_array($map)) {
            throw new \FOSSBilling\InformationException('Mapping must be a JSON object of {whmcs_field_id: "custom_N"}');
        }

        $clean = [];
        foreach ($map as $fieldId => $target) {
            if (preg_match('/^custom_(?:[1-9]|1[0-9]|20)$/', (string) $target)) {
                $clean[(int) $fieldId] = (string) $target;
            }
        }

        $extensionService = $this->getDi()['mod_service']('extension');
        $config = $extensionService->getConfig('mod_whmcsmigration');
        $config['ext'] = 'mod_whmcsmigration';
        $config['custom_field_map'] = json_encode($clean);
        $extensionService->setConfig($config);

        return true;
    }

    /**
     * Stage the next batch of an entity (transform into the staging table).
     */
    #[RequiredParams(['entity' => 'Entity was not passed'])]
    public function stage_batch($data): array
    {
        $this->checkPermissions('whmcsmigration', 'can_migrate');

        $limit = min(500, max(10, (int) ($data['limit'] ?? 250)));

        return $this->getService()->getImporter((string) $data['entity'])->stageBatch($limit);
    }

    /**
     * Commit the next batch of staged rows into the live tables.
     */
    #[RequiredParams(['entity' => 'Entity was not passed'])]
    public function commit_batch($data): array
    {
        $this->checkPermissions('whmcsmigration', 'can_migrate');

        $limit = min(500, max(10, (int) ($data['limit'] ?? 250)));

        return $this->getService()->getImporter((string) $data['entity'])->commitBatch($limit);
    }

    /**
     * Paginated staged-row preview for the review table.
     */
    #[RequiredParams(['entity' => 'Entity was not passed'])]
    public function staged_list($data): array
    {
        $this->checkPermissions('whmcsmigration', 'can_migrate');

        $entity = (string) $data['entity'];
        $perPage = min(100, max(5, (int) ($data['per_page'] ?? 25)));
        $page = max(1, (int) ($data['page'] ?? 1));
        $status = (string) ($data['status'] ?? '');

        $dbal = $this->getDi()['dbal'];
        $where = 'entity = ?';
        $params = [$entity];
        if (in_array($status, ['staged', 'needs_mapping', 'committed', 'rejected'], true)) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }

        $total = (int) $dbal->fetchOne("SELECT COUNT(*) FROM mod_whmcsmigration_staged WHERE {$where}", $params);
        $rows = $dbal->fetchAllAssociative(
            "SELECT id, whmcs_id, payload, issues, status, created_at FROM mod_whmcsmigration_staged WHERE {$where} ORDER BY whmcs_id"
            . sprintf(' LIMIT %d OFFSET %d', $perPage, ($page - 1) * $perPage),
            $params
        );

        foreach ($rows as &$row) {
            $row['payload'] = json_decode((string) $row['payload'], true);
            $row['issues'] = !empty($row['issues']) ? json_decode((string) $row['issues'], true) : [];
        }

        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) ceil($total / $perPage),
            'list' => $rows,
        ];
    }

    /**
     * Discard staged rows and reset the run for an entity. Committed data and
     * the id-map are untouched.
     */
    #[RequiredParams(['entity' => 'Entity was not passed'])]
    public function discard($data): bool
    {
        $this->checkPermissions('whmcsmigration', 'can_migrate');

        $this->getService()->resetEntity((string) $data['entity']);

        return true;
    }

    /**
     * Full run state for the UI (all parts, lock/done flags, counters).
     */
    public function status(): array
    {
        $this->checkPermissions('whmcsmigration', 'can_migrate');

        return $this->getService()->getRunStatus();
    }
}
