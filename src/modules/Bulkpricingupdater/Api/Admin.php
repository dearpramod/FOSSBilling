<?php

declare(strict_types=1);

namespace Box\Mod\Bulkpricingupdater\Api;

use FOSSBilling\Validation\Api\RequiredParams;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    /**
     * List products for bulk pricing criteria selection.
     *
     * @optional string query Search query by product name.
     */
    public function product_get_list(array $data): array
    {
        $this->checkPermissions('bulkpricingupdater', 'manage_settings');

        return $this->getService()->getProductList($data);
    }

    /**
     * Get billing cycles and current prices for selected products.
     */
    public function product_period_pricing_get(array $data): array
    {
        $this->checkPermissions('bulkpricingupdater', 'manage_settings');

        if (!isset($data['product_ids']) && !isset($data['product_ids_csv'])) {
            throw new \FOSSBilling\InformationException('Product ids are required');
        }

        return $this->getService()->getSelectedProductPricing($data);
    }

    /**
     * Preview product renewal repricing impact.
     *
     * @optional int    product_category_id Product category filter.
     * @optional int    product_id Product filter.
     * @optional array|string product_ids Product ids array or comma-separated ids.
     * @optional bool   include_active_orders Reprice active recurring orders in preview.
     */
    #[RequiredParams(['period' => 'Period is required', 'mode' => 'Mode is required', 'value' => 'Value is required'])]
    public function preview_product_renewal_update(array $data): array
    {
        $this->checkPermissions('bulkpricingupdater', 'manage_settings');
        $this->validateModeAndValue($data, true);

        return $this->getService()->previewProductRenewalUpdate($data);
    }

    /**
     * Apply product renewal repricing in bulk.
     *
     * @optional int    product_category_id Product category filter.
     * @optional int    product_id Product filter.
     * @optional array|string product_ids Product ids array or comma-separated ids.
     * @optional bool   include_active_orders Reprice active recurring orders.
     */
    #[RequiredParams(['period' => 'Period is required', 'mode' => 'Mode is required', 'value' => 'Value is required'])]
    public function apply_product_renewal_update(array $data): array
    {
        $this->checkPermissions('bulkpricingupdater', 'manage_settings');
        $this->validateModeAndValue($data, true);

        return $this->getService()->applyProductRenewalUpdate($data, $this->getAdminId());
    }

    /**
     * Preview domain renewal repricing impact.
     *
     * @optional string tld Target single TLD (.com or com).
     * @optional int    tld_registrar_id Registrar filter.
     * @optional bool   include_active_orders Reprice active domain orders in preview.
     */
    #[RequiredParams(['mode' => 'Mode is required', 'value' => 'Value is required'])]
    public function preview_domain_renewal_update(array $data): array
    {
        $this->checkPermissions('bulkpricingupdater', 'manage_settings');
        $this->validateModeAndValue($data, false);

        return $this->getService()->previewDomainRenewalUpdate($data);
    }

    /**
     * Apply domain renewal repricing in bulk.
     *
     * @optional string tld Target single TLD (.com or com).
     * @optional int    tld_registrar_id Registrar filter.
     * @optional bool   include_active_orders Reprice active domain orders.
     */
    #[RequiredParams(['mode' => 'Mode is required', 'value' => 'Value is required'])]
    public function apply_domain_renewal_update(array $data): array
    {
        $this->checkPermissions('bulkpricingupdater', 'manage_settings');
        $this->validateModeAndValue($data, false);

        return $this->getService()->applyDomainRenewalUpdate($data, $this->getAdminId());
    }

    /**
     * List latest bulk pricing jobs.
     *
     * @optional int limit Number of rows to return, max 100.
     */
    public function job_get_list(array $data): array
    {
        $this->checkPermissions('bulkpricingupdater', 'manage_settings');

        return $this->getService()->getJobs($data);
    }

    private function validateModeAndValue(array $data, bool $validatePeriod): void
    {
        $mode = strtolower(trim((string) ($data['mode'] ?? '')));
        if (!in_array($mode, ['set', 'percent'], true)) {
            throw new \FOSSBilling\InformationException('Mode must be set or percent');
        }

        if (!is_numeric($data['value'])) {
            throw new \FOSSBilling\InformationException('Value must be numeric');
        }

        if ($mode === 'set' && (float) $data['value'] < 0) {
            throw new \FOSSBilling\InformationException('Set value must be zero or greater');
        }

        if ($mode === 'percent') {
            $pct = (float) $data['value'];
            if ($pct < -100 || $pct > 10000) {
                throw new \FOSSBilling\InformationException('Percent value must be between -100 and 10000');
            }
        }

        if ($validatePeriod) {
            $allowedPeriods = ['1W', '1M', '3M', '6M', '1Y', '2Y', '3Y'];
            $period = strtoupper(trim((string) ($data['period'] ?? '')));
            if (!in_array($period, $allowedPeriods, true)) {
                throw new \FOSSBilling\InformationException('Invalid period');
            }
        }
    }

    private function getAdminId(): ?int
    {
        $identity = $this->getIdentity();

        return ($identity instanceof \Model_Admin && isset($identity->id)) ? (int) $identity->id : null;
    }
}
