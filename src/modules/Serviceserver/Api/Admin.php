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
        $this->checkPermissions('serviceserver', 'manage_settings');

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
        $this->checkPermissions('serviceserver', 'manage_settings');

        $config = $this->getDi()['mod_config']('Serviceserver');
        $json = $config['option_fields'] ?? null;

        if (!$json) {
            return $this->getService()->getDefaultOptions();
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : $this->getService()->getDefaultOptions();
    }

    /**
     * Validate and persist the option fields configuration to module config.
     *
     * @param array{options_json: string} $data JSON-encoded array of option field definitions
     */
    #[RequiredParams(['options_json' => 'options_json is required'])]
    public function options_save(array $data): bool
    {
        $this->checkPermissions('serviceserver', 'manage_settings');

        $this->decodeAndValidateOptionsJson((string) ($data['options_json'] ?? ''));

        $config = $this->getDi()['mod_config']('Serviceserver');
        $config['ext'] = 'mod_serviceserver';
        $config['option_fields'] = $data['options_json'];
        $this->getDi()['mod_service']('extension')->setConfig($config);

        return true;
    }

    /**
     * @deprecated use options_get() instead
     */
    public function location_get_list(array $data = []): array
    {
        $this->checkPermissions('serviceserver', 'manage_settings');

        $config = $this->getDi()['mod_config']('Serviceserver');
        $json = $config['locations'] ?? null;

        if (!$json) {
            return $this->getService()->getDefaultLocations();
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : $this->getService()->getDefaultLocations();
    }

    /**
     * @deprecated use options_save() instead
     */
    public function location_save(array $data): bool
    {
        $this->checkPermissions('serviceserver', 'manage_settings');

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
     * Persist per-product option field override into product.config['option_fields'].
     * Passing an empty options_json (or "[]") clears the override so the product falls
     * back to the category / global defaults.
     *
     * @param array{product_id: int, options_json: string} $data
     */
    #[RequiredParams(['product_id' => 'product_id is required', 'options_json' => 'options_json is required'])]
    public function product_options_save(array $data): bool
    {
        $this->checkPermissions('serviceserver', 'manage_settings');

        $productId = (int) ($data['product_id'] ?? 0);
        $decoded = $this->decodeAndValidateOptionsJson((string) ($data['options_json'] ?? ''));

        $db = $this->getDi()['db'];
        $product = $db->findOne('product', 'id = ?', [$productId]);

        if (!$product) {
            throw new \FOSSBilling\InformationException('Product #:id not found', [':id' => $productId]);
        }

        if ((string) ($product->type ?? '') !== 'server') {
            throw new \FOSSBilling\InformationException('Product #:id is not a server type product', [':id' => $productId]);
        }

        $config = json_decode((string) ($product->config ?? '{}'), true);
        $config = is_array($config) ? $config : [];

        if (empty($decoded)) {
            unset($config['option_fields']);
        } else {
            $config['option_fields'] = $decoded;
        }

        $product->config = json_encode($config);
        $product->updated_at = date('Y-m-d H:i:s');
        $db->store($product);

        return true;
    }

    /**
     * Return the saved option fields for a specific product category.
     * Returns an empty array when no category-level override has been configured —
     * callers should treat an empty result as "no override; global defaults apply via merge.".
     *
     * @param array{category_id: int} $data
     */
    #[RequiredParams(['category_id' => 'category_id is required'])]
    public function category_options_get(array $data): array
    {
        $this->checkPermissions('serviceserver', 'manage_settings');

        $categoryId = (int) ($data['category_id'] ?? 0);

        if (!$categoryId) {
            throw new \FOSSBilling\InformationException('category_id is required');
        }

        $config = $this->getDi()['mod_config']('Serviceserver');
        $json = $config['option_fields_cat_' . $categoryId] ?? null;

        if (!$json) {
            return [];
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persist option fields for a specific product category.
     *
     * @param array{category_id: int, options_json: string} $data
     */
    #[RequiredParams(['category_id' => 'category_id is required', 'options_json' => 'options_json is required'])]
    public function category_options_save(array $data): bool
    {
        $this->checkPermissions('serviceserver', 'manage_settings');

        $categoryId = (int) ($data['category_id'] ?? 0);

        if (!$categoryId) {
            throw new \FOSSBilling\InformationException('category_id is required');
        }

        $this->decodeAndValidateOptionsJson((string) ($data['options_json'] ?? ''));

        $config = $this->getDi()['mod_config']('Serviceserver');
        $config['ext'] = 'mod_serviceserver';
        $config['option_fields_cat_' . $categoryId] = $data['options_json'];
        $this->getDi()['mod_service']('extension')->setConfig($config);

        return true;
    }

    /**
     * Decode and validate a JSON string containing option field definitions.
     * Throws InformationException for any structural or value violation.
     *
     * @return array<int, array<string, mixed>> Validated decoded fields (empty array when JSON is "[]" or "")
     */
    private function decodeAndValidateOptionsJson(string $json): array
    {
        if ($json === '' || $json === '[]') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new \FOSSBilling\InformationException('Invalid JSON: could not parse option fields data');
        }

        foreach ($decoded as $field) {
            if (!is_array($field) || empty($field['id']) || empty($field['label'])) {
                throw new \FOSSBilling\InformationException('Each option field must have an "id" and a "label"');
            }

            if (!preg_match('/^[a-z0-9_]+$/', (string) $field['id'])) {
                throw new \FOSSBilling\InformationException('Field ID ":id" must use only lowercase letters, numbers, and underscores', [':id' => $field['id']]);
            }

            if (!is_array($field['options'] ?? null)) {
                throw new \FOSSBilling\InformationException('Each option field must have an "options" array');
            }

            foreach ($field['options'] as $opt) {
                if (empty($opt['value']) || empty($opt['label'])) {
                    throw new \FOSSBilling\InformationException('Each option must have a "value" and a "label"');
                }

                $imageUrl = trim((string) ($opt['image_url'] ?? ''));

                if ($imageUrl !== '' && preg_match('#^(https?://|/)#i', $imageUrl) !== 1) {
                    throw new \FOSSBilling\InformationException('Option image sources must use an HTTP(S) URL or a local path beginning with a slash');
                }
            }
        }

        return $decoded;
    }

    /**
     * Update server service details (IP, hostname, credentials, specs, etc.).
     *
     * @param array{order_id: int} $data
     */
    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function update(array $data): bool
    {
        $this->checkPermissions('serviceserver', 'manage_settings');

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
