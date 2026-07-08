<?php

declare(strict_types=1);

/**
 * Support PIN — Admin API.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Supportpin\Api;

use FOSSBilling\Validation\Api\RequiredParams;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Get Support PIN data for a client.
     *
     * @return array{client_id:int,pin:?string,changed_at:?string,fails:int,locked:bool,locked_until:?string}
     */
    #[RequiredParams(['client_id' => 'Client ID is required'])]
    public function get(array $data): array
    {
        $clientId = (int) $data['client_id'];
        $this->di['db']->getExistingModelById('Client', $clientId, 'Client not found');

        return $this->getService()->toApiArray($clientId);
    }

    /**
     * Regenerate (force new) Support PIN for a client.
     *
     * @return array{pin:string}
     */
    #[RequiredParams(['client_id' => 'Client ID is required'])]
    public function regenerate(array $data): array
    {
        $clientId = (int) $data['client_id'];
        $this->di['db']->getExistingModelById('Client', $clientId, 'Client not found');

        $pin = $this->getService()->generatePin($clientId);

        $this->di['logger']->info(sprintf('Admin regenerated Support PIN for client %d.', $clientId));

        return ['pin' => $pin];
    }

    /**
     * Lookup client by Support PIN.
     * Returns client summary or throws if not found.
     *
     * @return array{client_id:int,first_name:string,last_name:string,email:string,status:string}
     */
    #[RequiredParams(['pin' => 'PIN is required'])]
    public function lookup(array $data): array
    {
        $pin = trim((string) $data['pin']);

        if (!preg_match('/^\d{6}$/', $pin)) {
            throw new \FOSSBilling\InformationException('PIN must be a 6-digit number.');
        }

        $clientId = $this->getService()->findClientByPin($pin);

        if ($clientId === null) {
            throw new \FOSSBilling\InformationException('No client found for this PIN.');
        }

        $client = $this->di['db']->getExistingModelById('Client', $clientId, 'Client not found');

        return [
            'client_id' => $clientId,
            'first_name' => (string) $client->first_name,
            'last_name' => (string) $client->last_name,
            'email' => (string) $client->email,
            'status' => (string) $client->status,
        ];
    }

    /**
     * Reset fail counter and lockout for a client.
     */
    #[RequiredParams(['client_id' => 'Client ID is required'])]
    public function reset_lock(array $data): bool
    {
        $clientId = (int) $data['client_id'];
        $this->di['db']->getExistingModelById('Client', $clientId, 'Client not found');

        return $this->getService()->resetLock($clientId);
    }
}
