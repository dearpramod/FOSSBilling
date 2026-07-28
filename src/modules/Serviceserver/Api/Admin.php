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

        return $this->getService()->toApiArray($model, true);
    }

    /**
     * Return the configured option fields (or built-in defaults).
     */
    public function options_get(array $data = []): array
    {
        $config = $this->getDi()['mod_config']('Serviceserver');
        $json = $config['option_fields'] ?? null;

        if (!$json) {
            return $this->getService()->getDefaultOptions();
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : $this->getService()->getDefaultOptions();
    }

    /**
     * Validate and persist the option fields configuration to module config.
     *
     * @param array{options_json: string} $data JSON-encoded array of option field definitions
     */
    public function options_save(array $data): bool
    {
        if (empty($data['options_json'])) {
            throw new \FOSSBilling\InformationException('options_json is required');
        }

        $decoded = json_decode((string) $data['options_json'], true);

        if (!is_array($decoded)) {
            throw new \FOSSBilling\InformationException('Invalid JSON: could not parse option fields data');
        }

        foreach ($decoded as $field) {
            if (!is_array($field) || empty($field['id']) || empty($field['label'])) {
                throw new \FOSSBilling\InformationException('Each option field must have an "id" and a "label"');
            }
            if (!is_array($field['options'] ?? null)) {
                throw new \FOSSBilling\InformationException('Each option field must have an "options" array');
            }
            foreach ($field['options'] as $opt) {
                if (empty($opt['value']) || empty($opt['label'])) {
                    throw new \FOSSBilling\InformationException('Each option must have a "value" and a "label"');
                }
            }
        }

        $config = $this->getDi()['mod_config']('Serviceserver');
        $config['ext'] = 'mod_serviceserver';
        $config['option_fields'] = (string) $data['options_json'];
        $this->getDi()['mod_service']('extension')->setConfig($config);

        return true;
    }

    /**
     * @deprecated Use options_get() instead.
     */
    public function location_get_list(array $data = []): array
    {
        $config = $this->getDi()['mod_config']('Serviceserver');
        $json = $config['locations'] ?? null;

        if (!$json) {
            return $this->getService()->getDefaultLocations();
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : $this->getService()->getDefaultLocations();
    }

    /**
     * @deprecated Use options_save() instead.
     */
    public function location_save(array $data): bool
    {
        if (empty($data['locations_json'])) {
            throw new \FOSSBilling\InformationException('locations_json is required');
        }

        $decoded = json_decode((string) $data['locations_json'], true);

        if (!is_array($decoded)) {
            throw new \FOSSBilling\InformationException('Invalid JSON: could not parse locations data');
        }

        foreach ($decoded as $region) {
            if (!is_array($region) || empty($region['region']) || !is_array($region['options'] ?? null)) {
                throw new \FOSSBilling\InformationException('Each region must have a "region" name and an "options" list');
            }
            foreach ($region['options'] as $opt) {
                if (empty($opt['id']) || empty($opt['label'])) {
                    throw new \FOSSBilling\InformationException('Each location must have an "id" and a "label"');
                }
            }
        }

        $config = $this->getDi()['mod_config']('Serviceserver');
        $config['ext'] = 'mod_serviceserver';
        $config['locations'] = (string) $data['locations_json'];
        $this->getDi()['mod_service']('extension')->setConfig($config);

        return true;
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
