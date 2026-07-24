<?php

declare(strict_types=1);

/**
 * FOSSBilling Server Product Type — Client API
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Serviceserver\Api;

use FOSSBilling\Validation\Api\RequiredParams;

class Client extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Get server service details for an order (client-safe view — no password).
     *
     * @param array{order_id: int} $data
     */
    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function get(array $data): array
    {
        $identity = $this->getIdentity();
        $order = $this->di['db']->getExistingModelById('ClientOrder', (int) $data['order_id'], 'Order not found');

        if ((int) $order->client_id !== (int) $identity->id) {
            throw new \FOSSBilling\Exception('Access denied', null, 403);
        }

        $model = $this->getService()->getServiceByOrderId((int) $data['order_id']);

        if ($model === null) {
            return [
                'order_id' => (int) $data['order_id'],
                'status' => 'pending',
                'hostname' => null,
                'ip' => null,
                'os' => null,
                'cpu_cores' => null,
                'ram_mb' => null,
                'disk_gb' => null,
                'bandwidth_gb' => null,
                'location' => null,
                'notes' => null,
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        $result = $this->getService()->toApiArray($model);
        unset($result['root_password'], $result['client_id']);

        return $result;
    }
}
