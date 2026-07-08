<?php

declare(strict_types=1);

/**
 * Partnership Pricing — Admin API.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Partnership\Api;

use FOSSBilling\Validation\Api\RequiredParams;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    private const ALLOWED_PERIODS = ['1W', '1M', '3M', '6M', '1Y', '2Y', '3Y'];

    /* ================================================================
     *  Product Pricing
     * ================================================================ */

    /**
     * List partner product pricing rules.
     *
     * @optional int client_group_id Filter by client group.
     * @optional int product_id      Filter by product.
     */
    public function product_pricing_get_list(array $data): array
    {
        $this->_checkManagePermission();

        return $this->getService()->getProductPricingList($data);
    }

    /**
     * Create a partner product pricing rule.
     *
     * @required int   client_group_id  Client group ID.
     * @required int   product_id       Product ID.
     * @required float price            Partner price (base currency).
     *
     * @optional string period          Billing period code (1M, 3M, 6M, 1Y, 2Y, 3Y). Omit for all periods.
     */
    #[RequiredParams(['client_group_id' => 'Client group ID is required', 'product_id' => 'Product ID is required', 'price' => 'Price is required'])]
    public function product_pricing_create(array $data): int
    {
        $this->_checkManagePermission();

        if ((float) $data['price'] < 0) {
            throw new \FOSSBilling\InformationException('Price must be zero or greater');
        }

        if (!empty($data['period'])) {
            $period = strtoupper(trim((string) $data['period']));
            if (!in_array($period, self::ALLOWED_PERIODS, true)) {
                throw new \FOSSBilling\InformationException('Invalid period. Allowed: 1W, 1M, 3M, 6M, 1Y, 2Y, 3Y');
            }
            $data['period'] = $period;
        }

        return $this->getService()->createProductPricing($data);
    }

    /**
     * Update a partner product pricing rule.
     *
     * @required int   id     Rule ID.
     * @required float price  New price.
     *
     * @optional string period New billing period code.
     */
    #[RequiredParams(['id' => 'Rule ID is required', 'price' => 'Price is required'])]
    public function product_pricing_update(array $data): bool
    {
        $this->_checkManagePermission();

        if ((float) $data['price'] < 0) {
            throw new \FOSSBilling\InformationException('Price must be zero or greater');
        }

        if (!empty($data['period'])) {
            $period = strtoupper(trim((string) $data['period']));
            if (!in_array($period, self::ALLOWED_PERIODS, true)) {
                throw new \FOSSBilling\InformationException('Invalid period. Allowed: 1W, 1M, 3M, 6M, 1Y, 2Y, 3Y');
            }
            $data['period'] = $period;
        }

        return $this->getService()->updateProductPricing($data);
    }

    /**
     * Delete a partner product pricing rule.
     *
     * @required int id Rule ID.
     */
    #[RequiredParams(['id' => 'Rule ID is required'])]
    public function product_pricing_delete(array $data): bool
    {
        $this->_checkManagePermission();

        return $this->getService()->deleteProductPricing((int) $data['id']);
    }

    /* ================================================================
     *  TLD Pricing
     * ================================================================ */

    /**
     * List partner TLD pricing rules.
     *
     * @optional int client_group_id Filter by client group.
     */
    public function tld_pricing_get_list(array $data): array
    {
        $this->_checkManagePermission();

        return $this->getService()->getTldPricingList($data);
    }

    /**
     * Create or update a partner TLD pricing rule.
     *
     * @required int    client_group_id    Client group ID.
     * @required string tld               TLD (e.g. .com or com).
     *
     * @optional float  price_registration Registration price.
     * @optional float  price_renew        Renewal price.
     * @optional float  price_transfer     Transfer price.
     */
    #[RequiredParams(['client_group_id' => 'Client group ID is required', 'tld' => 'TLD is required'])]
    public function tld_pricing_create(array $data): int
    {
        $this->_checkManagePermission();

        foreach (['price_registration', 'price_renew', 'price_transfer'] as $field) {
            if (isset($data[$field]) && (float) $data[$field] < 0) {
                throw new \FOSSBilling\InformationException('Price values must be zero or greater');
            }
        }

        $tld = strtolower(trim((string) $data['tld']));
        if (!str_starts_with($tld, '.')) {
            $tld = '.' . $tld;
        }

        if (strlen($tld) > 32 || !preg_match('/^\.[a-z]{2,30}$/', $tld)) {
            throw new \FOSSBilling\InformationException('Invalid TLD format. Use a valid extension such as .com or .io');
        }

        $data['tld'] = $tld;

        return $this->getService()->createTldPricing($data);
    }

    /**
     * Update a partner TLD pricing rule.
     *
     * @required int   id                 Rule ID.
     *
     * @optional float price_registration Registration price.
     * @optional float price_renew        Renewal price.
     * @optional float price_transfer     Transfer price.
     */
    #[RequiredParams(['id' => 'Rule ID is required'])]
    public function tld_pricing_update(array $data): bool
    {
        $this->_checkManagePermission();

        foreach (['price_registration', 'price_renew', 'price_transfer'] as $field) {
            if (isset($data[$field]) && (float) $data[$field] < 0) {
                throw new \FOSSBilling\InformationException('Price values must be zero or greater');
            }
        }

        return $this->getService()->updateTldPricing($data);
    }

    /**
     * Delete a partner TLD pricing rule.
     *
     * @required int id Rule ID.
     */
    #[RequiredParams(['id' => 'Rule ID is required'])]
    public function tld_pricing_delete(array $data): bool
    {
        $this->_checkManagePermission();

        return $this->getService()->deleteTldPricing((int) $data['id']);
    }

    /* ================================================================
     *  Private helpers
     * ================================================================ */

    private function _checkManagePermission(): void
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException(
            'partnership',
            'manage_settings'
        );
    }
}
