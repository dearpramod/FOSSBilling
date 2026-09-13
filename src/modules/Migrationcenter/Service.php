<?php

declare(strict_types=1);

/**
 * Migration Center — core service.
 *
 * Owns the mod_migrationcenter_request table: creation, the staff status
 * workflow, and the single decryption path (revealSecret). Callers are
 * responsible for permission checks; the API layer enforces them.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter;

use Box\Mod\Migrationcenter\Entity\MigrationRequest;
use Box\Mod\Migrationcenter\Repository\MigrationRequestRepository;
use Box\Mod\Migrationcenter\Support\RequestInput;
use Box\Mod\Migrationcenter\Support\RequestStatus;
use Box\Mod\Migrationcenter\Support\SecretCodec;
use Doctrine\DBAL\ArrayParameterType;
use FOSSBilling\InjectionAwareInterface;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    private ?SecretCodec $codec = null;

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
            'view_migrations' => [
                'type' => 'bool',
                'display_name' => __trans('View migration requests'),
                'description' => __trans('Allows the staff member to see the migration queue and each request\'s details, but not the stored credentials.'),
            ],
            'manage_migrations' => [
                'type' => 'bool',
                'display_name' => __trans('Manage migrations and view credentials'),
                'description' => __trans('Allows the staff member to read the previous host credentials a client submitted, change a request\'s status, assign it, and purge the stored credentials.'),
            ],
        ];
    }

    public function install(): bool
    {
        $this->di['dbal']->executeStatement('
            CREATE TABLE IF NOT EXISTS `mod_migrationcenter_request` (
                `id`                BIGINT       NOT NULL AUTO_INCREMENT,
                `client_id`         BIGINT       NOT NULL,
                `client_order_id`   BIGINT       NULL,
                `status`            VARCHAR(20)  NOT NULL DEFAULT \'submitted\',
                `method`            VARCHAR(20)  NOT NULL DEFAULT \'manual\',
                `panel_type`        VARCHAR(30)  NOT NULL,
                `host`              VARCHAR(255) NOT NULL,
                `port`              INT          NULL,
                `username`          VARCHAR(255) NOT NULL,
                `secret_encrypted`  TEXT         NOT NULL,
                `secret_purged_at`  DATETIME     NULL,
                `client_notes`      TEXT         NULL,
                `staff_notes`       TEXT         NULL,
                `assigned_staff_id` BIGINT       NULL,
                `created_at`        DATETIME     NOT NULL,
                `updated_at`        DATETIME     NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_migrationcenter_client` (`client_id`),
                KEY `idx_migrationcenter_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        return true;
    }

    /**
     * Deliberately keeps the table: uninstalling the module must not destroy
     * a client's migration history.
     */
    public function uninstall(): bool
    {
        return true;
    }

    // ── Client-facing ────────────────────────────────────────────────────

    public function createRequest(int $clientId, array $data): int
    {
        $input = RequestInput::fromClientData($data);

        $orderId = $input->clientOrderId;
        if ($orderId !== null && !$this->clientOwnsOrder($clientId, $orderId)) {
            throw new \FOSSBilling\InformationException('The selected service does not belong to your account.');
        }

        $request = (new MigrationRequest())
            ->setClientId($clientId)
            ->setClientOrderId($orderId)
            ->setStatus(RequestStatus::SUBMITTED)
            ->setMethod(MigrationRequest::METHOD_MANUAL)
            ->setPanelType($input->panelType)
            ->setHost($input->host)
            ->setPort($input->port)
            ->setUsername($input->username)
            ->setClientNotes($input->clientNotes)
            ->setSecretEncrypted($this->codec()->encode([
                'password' => $input->password,
                'ssh_key' => $input->sshKey,
            ]));

        $this->di['em']->persist($request);
        $this->di['em']->flush();

        $id = (int) $request->getId();
        // Never log host, username or the secret — only the identifiers.
        $this->di['logger']->info(sprintf('Migration request %d created by client %d.', $id, $clientId));

        $apiArray = $request->toApiArray();

        $this->sendEmail([
            'to_client' => $clientId,
            'code' => 'mod_migrationcenter_request_received',
            'request' => $apiArray,
        ]);

        $this->sendEmail([
            'to_staff' => true,
            'code' => 'mod_migrationcenter_staff_new_request',
            'request' => $apiArray,
            'client_name' => $this->clientDisplayName($clientId),
        ]);

        return $id;
    }

    public function getClientRequests(int $clientId): array
    {
        $requests = $this->repository()
            ->getSearchQueryBuilder(['client_id' => $clientId])
            ->getQuery()
            ->getResult();

        return array_map(static fn (MigrationRequest $r): array => $r->toApiArray(), $requests);
    }

    public function getRequestForClient(int $id, int $clientId): array
    {
        return $this->loadForClient($id, $clientId)->toApiArray();
    }

    public function cancelRequestForClient(int $id, int $clientId): bool
    {
        $request = $this->loadForClient($id, $clientId);

        if (!RequestStatus::isClientCancellable($request->getStatus())) {
            throw new \FOSSBilling\InformationException('This migration request can no longer be cancelled. Please contact support.');
        }

        $request->setStatus(RequestStatus::CANCELLED);
        $this->di['em']->flush();

        return true;
    }

    // ── Staff-facing ─────────────────────────────────────────────────────

    /**
     * The queue table needs each row's client name, but the entity only
     * knows client_id. Rather than an admin.client_get() lookup per row
     * (an N+1 that also requires the caller to hold the `client.view`
     * permission just to render the list), the distinct client ids are
     * batched into a single query here and merged onto the rows.
     */
    public function getAdminRequestList(array $data): array
    {
        $requests = $this->repository()
            ->getSearchQueryBuilder($data)
            ->getQuery()
            ->getResult();

        $rows = array_map(static fn (MigrationRequest $r): array => $r->toAdminApiArray(), $requests);

        $clientIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['client_id'], $rows)));

        $clients = [];
        if ($clientIds !== []) {
            $clientRows = $this->di['dbal']->fetchAllAssociative(
                'SELECT id, first_name, last_name FROM client WHERE id IN (?)',
                [$clientIds],
                [ArrayParameterType::INTEGER]
            );
            foreach ($clientRows as $clientRow) {
                $clients[(int) $clientRow['id']] = [
                    'first_name' => (string) $clientRow['first_name'],
                    'last_name' => (string) $clientRow['last_name'],
                ];
            }
        }

        foreach ($rows as &$row) {
            $client = $clients[(int) $row['client_id']] ?? null;
            $row['client_first_name'] = $client['first_name'] ?? '';
            $row['client_last_name'] = $client['last_name'] ?? '';
        }
        unset($row);

        return $rows;
    }

    public function getAdminRequest(int $id): array
    {
        return $this->load($id)->toAdminApiArray();
    }

    /**
     * The one and only decryption path. Callers must have already enforced
     * the manage_migrations permission.
     *
     * @return array{password: string, ssh_key: string}
     */
    public function revealSecret(int $id): array
    {
        return $this->codec()->decode($this->load($id)->getSecretEncrypted());
    }

    public function updateStatus(int $id, string $status): bool
    {
        $request = $this->load($id);
        $current = $request->getStatus();

        if ($current === $status) {
            return true;
        }

        if (!RequestStatus::canTransition($current, $status)) {
            throw new \FOSSBilling\InformationException('A migration request cannot move from :from to :to.', [':from' => $current, ':to' => $status]);
        }

        $request->setStatus($status);
        $this->di['em']->flush();

        $this->di['logger']->info(sprintf('Migration request %d moved from %s to %s.', $id, $current, $status));

        $this->sendEmail([
            'to_client' => $request->getClientId(),
            'code' => 'mod_migrationcenter_status_changed',
            'request' => $request->toApiArray(),
            'status_spaced' => str_replace('_', ' ', $status),
        ]);

        return true;
    }

    public function assign(int $id, ?int $staffId): bool
    {
        $this->load($id)->setAssignedStaffId($staffId !== null && $staffId > 0 ? $staffId : null);
        $this->di['em']->flush();

        return true;
    }

    public function updateStaffNotes(int $id, ?string $notes): bool
    {
        $notes = $notes === null ? null : trim($notes);
        $this->load($id)->setStaffNotes($notes === '' ? null : $notes);
        $this->di['em']->flush();

        return true;
    }

    public function purgeSecret(int $id): bool
    {
        $request = $this->load($id);

        if (!RequestStatus::isPurgeable($request->getStatus())) {
            throw new \FOSSBilling\InformationException('Credentials can only be purged once the migration is completed, failed or cancelled.');
        }

        $request->purgeSecret();
        $this->di['em']->flush();

        $this->di['logger']->info(sprintf('Migration request %d credentials purged.', $id));

        return true;
    }

    public function getStatusCounts(): array
    {
        return $this->repository()->countByStatus();
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Email sending must never break the operation that triggered it, and no
     * email ever carries the submitted credentials.
     */
    private function sendEmail(array $payload): void
    {
        try {
            $this->di['mod_service']('email')->sendTemplate($payload);
        } catch (\Throwable $e) {
            $this->di['logger']->setChannel('email')->error('Failed to send migration center email', [
                'code' => $payload['code'] ?? '',
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolves a client's display name for the staff notification email.
     * Must never throw: a lookup failure here (e.g. the client vanished
     * between the request being saved and the email being built) must not
     * break createRequest(), which has already persisted successfully.
     */
    private function clientDisplayName(int $clientId): string
    {
        try {
            $client = $this->di['mod_service']('client')->get(['id' => $clientId]);

            return trim(($client->getFirstName() ?? '') . ' ' . ($client->getLastName() ?? ''));
        } catch (\Throwable $e) {
            $this->di['logger']->setChannel('email')->error('Could not resolve client name for migration notification', [
                'client_id' => $clientId,
                'exception' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function repository(): MigrationRequestRepository
    {
        return $this->di['em']->getRepository(MigrationRequest::class);
    }

    private function codec(): SecretCodec
    {
        return $this->codec ??= new SecretCodec($this->di['crypt']);
    }

    private function load(int $id): MigrationRequest
    {
        $request = $this->repository()->find($id);
        if (!$request instanceof MigrationRequest) {
            throw new \FOSSBilling\InformationException('Migration request not found.');
        }

        return $request;
    }

    private function loadForClient(int $id, int $clientId): MigrationRequest
    {
        $request = $this->repository()->findOneForClient($id, $clientId);
        if (!$request instanceof MigrationRequest) {
            throw new \FOSSBilling\InformationException('Migration request not found.');
        }

        return $request;
    }

    private function clientOwnsOrder(int $clientId, int $orderId): bool
    {
        $count = (int) $this->di['dbal']->fetchOne(
            'SELECT COUNT(*) FROM client_order WHERE id = ? AND client_id = ?',
            [$orderId, $clientId]
        );

        return $count > 0;
    }
}
