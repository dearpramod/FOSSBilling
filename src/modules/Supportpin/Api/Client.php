<?php

declare(strict_types=1);

/**
 * Support PIN — Client API.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Supportpin\Api;

class Client extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Get the logged-in client's own Support PIN data.
     *
     * @return array{pin:string,changed_at:?string,locked:bool}
     */
    public function get(array $data): array
    {
        $client = $this->getIdentity();
        $clientId = (int) $client->id;

        $result = $this->getService()->toApiArray($clientId);

        if ($result['pin'] === null) {
            $this->getService()->generatePin($clientId);
            $result = $this->getService()->toApiArray($clientId);
        }

        return [
            'pin' => (string) $result['pin'],
            'changed_at' => $result['changed_at'],
            'locked' => $result['locked'],
        ];
    }

    /**
     * Regenerate the logged-in client's own Support PIN.
     * Rate limited to 3 regenerations per 24 hours.
     *
     * @return array{pin:string,changed_at:string}
     */
    public function regenerate(array $data): array
    {
        $client = $this->getIdentity();
        $pin = $this->getService()->regeneratePin((int) $client->id);

        return [
            'pin' => $pin,
            'changed_at' => date('Y-m-d H:i:s'),
        ];
    }
}
