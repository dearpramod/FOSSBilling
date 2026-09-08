<?php

declare(strict_types=1);

/**
 * SMS Notifications — Admin API.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Sms\Api;

use FOSSBilling\Validation\Api\RequiredParams;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Get SMS message log (paginated).
     *
     * @param array{per_page?: int, page?: int} $data
     */
    public function log_get_list(array $data): array
    {
        $this->checkPermissions('sms', 'manage_settings');

        return $this->getService()->getMessageLog($data);
    }

    /**
     * Delete a single log entry.
     *
     * @param array{id: int} $data
     */
    #[RequiredParams(['id' => 'Log entry ID is required'])]
    public function log_delete(array $data): bool
    {
        $this->checkPermissions('sms', 'manage_settings');

        return $this->getService()->deleteLogEntry((int) $data['id']);
    }

    /**
     * Send a test SMS to verify configuration.
     *
     * @param array{phone: string} $data
     */
    #[RequiredParams(['phone' => 'Phone number is required'])]
    public function send_test(array $data): array
    {
        $this->checkPermissions('sms', 'manage_settings');

        return $this->getService()->sendTest($data['phone']);
    }

    /**
     * Send a custom SMS message to any number.
     *
     * @param array{phone: string, message: string} $data
     */
    #[RequiredParams([
        'phone' => 'Phone number is required',
        'message' => 'Message text is required',
    ])]
    public function send(array $data): array
    {
        $this->checkPermissions('sms', 'manage_settings');

        return $this->getService()->sendMessage($data['phone'], $data['message']);
    }
}
