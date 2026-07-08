<?php

declare(strict_types=1);

/**
 * Partnership Pricing — Client API.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Partnership\Api;

class Client extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Return partner pricing applicable to the logged-in client's group.
     *
     * Used by order pages to render partner price badges without an extra
     * AJAX call — injected directly via Twig as `{{ client.partnership_get_pricing }}`.
     *
     * @return array{product_prices: list<array{product_id: string, period: string|null, price: string}>,
     *               tld_prices: list<array{tld: string, price_registration: string, price_renew: string, price_transfer: string}>}
     */
    public function get_pricing(array $data): array
    {
        return $this->getService()->getPricingForLoggedInClient();
    }
}
