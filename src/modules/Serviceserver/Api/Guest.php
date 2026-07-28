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
     * Return the configured option fields shown on the order form.
     * Falls back to the built-in defaults when no admin config is saved.
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
}
