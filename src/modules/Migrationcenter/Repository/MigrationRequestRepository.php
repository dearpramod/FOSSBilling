<?php

declare(strict_types=1);

/**
 * Migration Center — migration request queries.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Repository;

use Box\Mod\Migrationcenter\Entity\MigrationRequest;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

class MigrationRequestRepository extends EntityRepository
{
    /**
     * @param array $data filters: 'id', 'client_id', 'status' (string or list),
     *                    'assigned_staff_id', 'panel_type', 'search'
     */
    public function getSearchQueryBuilder(array $data): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r');

        if (!empty($data['id'])) {
            $qb->andWhere('r.id = :id')->setParameter('id', (int) $data['id']);
        }

        // isset/!== null rather than !empty(): an explicitly-passed 0 or ''
        // client_id must scope the query to "no client can match" instead of
        // being silently treated as "no filter", which would return every
        // client's rows. findOneForClient() already fails closed the same way.
        if (isset($data['client_id']) && $data['client_id'] !== '') {
            $qb->andWhere('r.clientId = :clientId')->setParameter('clientId', (int) $data['client_id']);
        }

        if (!empty($data['status'])) {
            // Accept a single status or a list, so the UI can group tabs.
            if (is_array($data['status'])) {
                $qb->andWhere('r.status IN (:statuses)')->setParameter('statuses', $data['status']);
            } else {
                $qb->andWhere('r.status = :status')->setParameter('status', (string) $data['status']);
            }
        }

        if (!empty($data['assigned_staff_id'])) {
            $qb->andWhere('r.assignedStaffId = :staffId')->setParameter('staffId', (int) $data['assigned_staff_id']);
        }

        if (!empty($data['panel_type'])) {
            $qb->andWhere('r.panelType = :panelType')->setParameter('panelType', (string) $data['panel_type']);
        }

        if (!empty($data['search'])) {
            $qb->andWhere('(r.host LIKE :q OR r.username LIKE :q)')
                ->setParameter('q', '%' . $data['search'] . '%');
        }

        $qb->orderBy('r.id', 'DESC');

        return $qb;
    }

    /**
     * Load a request only if it belongs to the given client. Returning null
     * for someone else's request is what keeps the client API scoped.
     */
    public function findOneForClient(int $id, int $clientId): ?MigrationRequest
    {
        return $this->findOneBy(['id' => $id, 'clientId' => $clientId]);
    }

    /**
     * @return array<string, int> status => count
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.status AS status, COUNT(r.id) AS total')
            ->groupBy('r.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }
}
