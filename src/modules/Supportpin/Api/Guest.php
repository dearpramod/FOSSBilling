<?php

declare(strict_types=1);

/**
 * Support PIN — Guest API (3rd-party verification endpoint).
 *
 * Requires PIN + email to prevent enumeration.
 * Rate limited by fail counter + lockout per client.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Supportpin\Api;

use FOSSBilling\Validation\Api\RequiredParams;

class Guest extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Verify a PIN + email combination and return client identity on success.
     *
     * Requires both PIN and email — PIN alone is not sufficient.
     * After 5 failed attempts the client's PIN is locked for 30 minutes.
     *
     * @return array{client_id:int,first_name:string,last_name:string}
     *
     * @throws \FOSSBilling\InformationException on invalid credentials or lockout
     */
    #[RequiredParams([
        'pin' => 'PIN is required',
        'email' => 'Email is required',
    ])]
    public function verify(array $data): array
    {
        $pin = trim((string) $data['pin']);
        $email = strtolower(trim((string) $data['email']));

        if (!preg_match('/^\d{6}$/', $pin)) {
            throw new \FOSSBilling\InformationException('Invalid credentials.');
        }

        $svc = $this->getService();
        $clientId = $svc->findClientByPin($pin);

        if ($clientId === null) {
            throw new \FOSSBilling\InformationException('Invalid credentials.');
        }

        $valid = $svc->verifyPin($clientId, $pin, $email);

        if (!$valid) {
            throw new \FOSSBilling\InformationException('Invalid credentials.');
        }

        $client = $this->di['db']->load('Client', $clientId);

        return [
            'client_id' => $clientId,
            'first_name' => (string) $client->first_name,
            'last_name' => (string) $client->last_name,
        ];
    }
}
