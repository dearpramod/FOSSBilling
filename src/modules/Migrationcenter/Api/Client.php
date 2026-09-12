<?php

declare(strict_types=1);

/**
 * Migration Center — Client API.
 *
 * The secret a client submits is write-only: no endpoint here ever returns
 * it, encrypted or otherwise. Every read is scoped to the logged-in client.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Api;

use FOSSBilling\Validation\Api\RequiredParams;

class Client extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Submit a migration request for the logged-in client.
     *
     * @optional int    port            Control panel / SSH port
     * @optional string password        Password at the previous host
     * @optional string ssh_key         SSH private key at the previous host
     * @optional string client_notes    Anything staff should know
     * @optional int    client_order_id The service being migrated onto
     */
    #[RequiredParams([
        'panel_type' => 'Please choose a valid control panel type.',
        'host' => 'The previous host address is required.',
        'username' => 'The username at your previous host is required.',
    ])]
    public function create(array $data): int
    {
        $client = $this->getIdentity();

        $this->getDi()['rate_limiter']->consumeOrThrow('migrationcenter_request_ip', (string) $this->getIp());
        $this->getDi()['rate_limiter']->consumeOrThrow('migrationcenter_request_client', (string) $client->id);

        return $this->getService()->createRequest((int) $client->id, $data);
    }

    /**
     * List the logged-in client's own migration requests.
     */
    public function get_list(array $data): array
    {
        return $this->getService()->getClientRequests((int) $this->getIdentity()->id);
    }

    /**
     * Get one of the logged-in client's own migration requests.
     */
    #[RequiredParams(['id' => 'Migration request ID is required'])]
    public function get(array $data): array
    {
        return $this->getService()->getRequestForClient((int) $data['id'], (int) $this->getIdentity()->id);
    }

    /**
     * Withdraw a migration request that staff have not started yet.
     */
    #[RequiredParams(['id' => 'Migration request ID is required'])]
    public function cancel(array $data): bool
    {
        return $this->getService()->cancelRequestForClient((int) $data['id'], (int) $this->getIdentity()->id);
    }
}
