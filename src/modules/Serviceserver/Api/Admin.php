<?php

declare(strict_types=1);

/**
 * FOSSBilling Server Product Type — Admin API
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Serviceserver\Api;

use FOSSBilling\Validation\Api\RequiredParams;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Get server service details for an order.
     *
     * @param array{order_id: int} $data
     */
    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function get(array $data): array
    {
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

        return $this->getService()->toApiArray($model);
    }

    /**
     * Update server service details (IP, hostname, credentials, specs, etc.).
     *
     * @param array{order_id: int} $data
     */
    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function update(array $data): bool
    {
        $model = $this->getService()->getServiceByOrderId((int) $data['order_id']);

        if ($model === null) {
            $order = $this->di['db']->getExistingModelById('ClientOrder', (int) $data['order_id'], 'Order #:id not found');
            $model = $this->di['db']->dispense('service_server');
            $model->order_id = $order->id;
            $model->client_id = $order->client_id;
            $model->status = 'pending';
            $model->created_at = date('Y-m-d H:i:s');
            $model->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($model);
        }

        return $this->getService()->update($model, $data);
    }
}
