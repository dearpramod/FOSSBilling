<?php

declare(strict_types=1);

/**
 * FOSSBilling Server Product Type — Guest API
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Serviceserver\Api;

class Guest extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Return the global option fields (no product/category merge).
     *
     * @deprecated Use product_options_get() with a product_id for the merged result shown on order forms.
     */
    public function options_get(array $data = []): array
    {
        $config = $this->getDi()['mod_config']('Serviceserver');
        $json   = $config['option_fields'] ?? null;

        if (!$json) {
            return $this->getService()->getDefaultOptions();
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : $this->getService()->getDefaultOptions();
    }

    /**
     * Return effective option fields for a specific product, respecting the
     * product → category → global → built-in cascade.
     *
     * @param array{product_id: int} $data
     */
    public function product_options_get(array $data): array
    {
        $productId = (int) ($data['product_id'] ?? 0);

        if ($productId <= 0) {
            throw new \FOSSBilling\InformationException('product_id is required');
        }

        $this->getDi()['rate_limiter']->consumeOrThrow('serviceserver_product_options_ip', (string) $this->getIp());

        return $this->getService()->getOptionsForProductId($productId, true);
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

        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : $this->getService()->getDefaultLocations();
    }
}
