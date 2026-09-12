<?php

declare(strict_types=1);

/**
 * Migration Center — Admin API.
 *
 * Permission split: view_migrations sees the queue and each request's
 * metadata; manage_migrations is required to read the stored credentials or
 * to change anything. Every method checks explicitly — `can_always_access`
 * on the module would otherwise let any staff account through.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Api;

use Box\Mod\Migrationcenter\Support\RequestStatus;
use FOSSBilling\Validation\Api\RequiredParams;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    /**
     * List migration requests.
     *
     * @optional string status            Filter by status
     * @optional int    client_id         Filter by client
     * @optional int    assigned_staff_id Filter by assignee
     * @optional string search            Match host or username
     */
    public function get_list(array $data): array
    {
        $this->checkPermissions('migrationcenter', 'view_migrations');

        return $this->getService()->getAdminRequestList($data);
    }

    /**
     * Get one migration request's details. Never contains the credentials.
     */
    #[RequiredParams(['id' => 'Migration request ID is required'])]
    public function get(array $data): array
    {
        $this->checkPermissions('migrationcenter', 'view_migrations');

        return $this->getService()->getAdminRequest((int) $data['id']);
    }

    /**
     * Per-status counts for the queue tabs.
     */
    public function status_counts(array $data): array
    {
        $this->checkPermissions('migrationcenter', 'view_migrations');

        return $this->getService()->getStatusCounts();
    }

    /**
     * Decrypt and return the previous host credentials.
     *
     * This is the only endpoint in the entire module that returns plaintext
     * credentials. It is kept separate from get() so the detail page's
     * server-rendered payload carries nothing sensitive for staff who only
     * hold view_migrations.
     */
    #[RequiredParams(['id' => 'Migration request ID is required'])]
    public function reveal_secret(array $data): array
    {
        $this->checkPermissions('migrationcenter', 'manage_migrations');

        return $this->getService()->revealSecret((int) $data['id']);
    }

    /**
     * Move a request through the status workflow.
     */
    #[RequiredParams(['id' => 'Migration request ID is required', 'status' => 'New status is required'])]
    public function update_status(array $data): bool
    {
        $this->checkPermissions('migrationcenter', 'manage_migrations');

        $status = (string) $data['status'];
        if (!RequestStatus::isValid($status)) {
            throw new \FOSSBilling\InformationException('Unknown migration request status.');
        }

        return $this->getService()->updateStatus((int) $data['id'], $status);
    }

    /**
     * Assign a request to a staff member, or clear the assignment.
     *
     * @optional int staff_id Omit or pass 0 to unassign
     */
    #[RequiredParams(['id' => 'Migration request ID is required'])]
    public function assign(array $data): bool
    {
        $this->checkPermissions('migrationcenter', 'manage_migrations');

        return $this->getService()->assign((int) $data['id'], isset($data['staff_id']) ? (int) $data['staff_id'] : null);
    }

    /**
     * Update the internal staff notes on a request.
     *
     * @optional string staff_notes Internal notes; never shown to the client
     */
    #[RequiredParams(['id' => 'Migration request ID is required'])]
    public function update_notes(array $data): bool
    {
        $this->checkPermissions('migrationcenter', 'manage_migrations');

        return $this->getService()->updateStaffNotes((int) $data['id'], isset($data['staff_notes']) ? (string) $data['staff_notes'] : null);
    }

    /**
     * Permanently erase the stored credentials for a finished request.
     */
    #[RequiredParams(['id' => 'Migration request ID is required'])]
    public function purge_secret(array $data): bool
    {
        $this->checkPermissions('migrationcenter', 'manage_migrations');

        return $this->getService()->purgeSecret((int) $data['id']);
    }
}
