<?php

declare(strict_types=1);

/**
 * tblclientgroups → client_group.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Whmcsmigration\Importer;

class ClientGroupImporter extends AbstractImporter
{
    public function entity(): string
    {
        return 'client_group';
    }

    protected function countSql(): string
    {
        return 'SELECT COUNT(*) FROM tblclientgroups';
    }

    protected function batchSql(): string
    {
        return 'SELECT id, groupname FROM tblclientgroups ORDER BY id';
    }

    protected function sourceIdColumn(): string
    {
        return 'id';
    }

    protected function transform(array $row): array
    {
        $title = trim((string) $row['groupname']);
        if ($title === '') {
            return ['payload' => [], 'issues' => [], 'skip' => 'Empty group name'];
        }

        $existingId = $this->di['dbal']->fetchOne('SELECT id FROM client_group WHERE title = ?', [$title]);
        if ($existingId !== false) {
            return ['payload' => [], 'issues' => [], 'skip' => "Client group '{$title}' already exists", 'map_to' => (int) $existingId];
        }

        return ['payload' => ['title' => $title], 'issues' => []];
    }

    protected function persist(array $payload): int
    {
        $model = $this->di['db']->dispense('ClientGroup');
        $model->title = $payload['title'];
        $model->created_at = date('Y-m-d H:i:s');
        $model->updated_at = date('Y-m-d H:i:s');

        return (int) $this->di['db']->store($model);
    }
}
