<?php

declare(strict_types=1);

namespace Box\Mod\Bulkpricingupdater;

class Service implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    private const array PERIOD_COLUMN_MAP = [
        '1W' => 'w_price',
        '1M' => 'm_price',
        '3M' => 'q_price',
        '6M' => 'b_price',
        '1Y' => 'a_price',
        '2Y' => 'bia_price',
        '3Y' => 'tria_price',
    ];

    private const array ACTIVE_ORDER_STATUSES = ['active', 'failed_renew'];

    /** Maximum number of active orders to preview/apply per bulk job (prevents memory exhaustion). */
    private const int MAX_ORDER_ROWS = 5000;

    /** Maximum allowed TLD string length (including leading dot). */
    private const int MAX_TLD_LENGTH = 64;

    private const array PERIOD_LABELS = [
        '1W' => 'Weekly',
        '1M' => 'Monthly',
        '3M' => 'Quarterly',
        '6M' => 'Semi-Annually',
        '1Y' => 'Annually',
        '2Y' => 'Biennially',
        '3Y' => 'Triennially',
    ];

    /** Maximum products returned by getProductList to prevent unbounded result sets. */
    private const int MAX_PRODUCT_LIST_ROWS = 1000;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return [
            'can_always_access' => true,
            'manage_settings' => [
                'type' => 'bool',
                'display_name' => 'Manage bulk pricing updates',
                'description' => 'Allows staff to preview and apply bulk product and domain renewal repricing.',
            ],
        ];
    }

    public function install(): bool
    {
        $this->di['db']->exec(
            'CREATE TABLE IF NOT EXISTS `pricing_bulk_job` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `scope` VARCHAR(32) NOT NULL,
                `mode` VARCHAR(16) NOT NULL,
                `value` DECIMAL(18,4) NOT NULL,
                `filters_json` LONGTEXT NULL,
                `preview_json` LONGTEXT NULL,
                `applied_changes` INT NOT NULL DEFAULT 0,
                `created_by` BIGINT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_scope_created` (`scope`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        return true;
    }

    public function uninstall(): bool
    {
        $this->di['db']->exec('DROP TABLE IF EXISTS `pricing_bulk_job`');

        return true;
    }

    public function previewProductRenewalUpdate(array $data): array
    {
        $period = $this->normalizePeriod($data['period'] ?? null);
        $mode = $this->normalizeMode($data['mode'] ?? null);
        $value = (float) ($data['value'] ?? 0);
        $includeActiveOrders = $this->toBool($data['include_active_orders'] ?? true);

        $targets = $this->getProductTargets($period, $data);
        $productPriceMap = [];

        foreach ($targets as &$target) {
            $target['current_price'] = (float) $target['current_price'];
            $target['new_price'] = $this->calculateNewPrice($target['current_price'], $mode, $value);
            $target['delta'] = round($target['new_price'] - $target['current_price'], 2);
            $productPriceMap[(int) $target['id']] = $target['new_price'];
        }
        unset($target);

        $orderImpact = [
            'count' => 0,
            'delta_total' => 0.0,
            'sample' => [],
            'rows' => [],
        ];

        if ($includeActiveOrders && !empty($productPriceMap)) {
            $orderImpact = $this->previewProductOrderImpact($productPriceMap, $period);
        }

        return [
            'scope' => 'product_renewal',
            'mode' => $mode,
            'value' => $value,
            'period' => $period,
            'filters' => [
                'product_category_id' => isset($data['product_category_id']) ? (int) $data['product_category_id'] : null,
                'product_id' => isset($data['product_id']) ? (int) $data['product_id'] : null,
                'include_active_orders' => $includeActiveOrders,
            ],
            'products' => [
                'count' => count($targets),
                'delta_total' => round(array_sum(array_column($targets, 'delta')), 2),
                'sample' => array_slice($targets, 0, 100),
                'rows' => $targets,
            ],
            'active_orders' => [
                'count' => $orderImpact['count'],
                'delta_total' => $orderImpact['delta_total'],
                'sample' => $orderImpact['sample'],
                'rows' => $orderImpact['rows'],
            ],
        ];
    }

    public function applyProductRenewalUpdate(array $data, ?int $adminId = null): array
    {
        $preview = $this->previewProductRenewalUpdate($data);
        $periodColumn = self::PERIOD_COLUMN_MAP[$preview['period']];
        $products = $preview['products']['rows'];
        $orders = $preview['active_orders']['rows'];
        $db = $this->di['db'];

        $appliedProductChanges = count($products);
        $appliedOrderChanges = count($orders);

        try {
            $db->exec('START TRANSACTION');

            $this->batchUpdateProductPayment($periodColumn, $products, $db);
            $this->batchUpdateClientOrders($orders, $db);

            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');

            throw $e;
        }

        $jobId = $this->saveJob(
            'product_renewal',
            $preview['mode'],
            (float) $preview['value'],
            $preview['filters'],
            [
                'products' => [
                    'count' => $preview['products']['count'],
                    'delta_total' => $preview['products']['delta_total'],
                ],
                'active_orders' => [
                    'count' => $preview['active_orders']['count'],
                    'delta_total' => $preview['active_orders']['delta_total'],
                ],
            ],
            $appliedProductChanges + $appliedOrderChanges,
            $adminId
        );

        $this->di['logger']->info(sprintf(
            'Bulk product renewal pricing applied by admin #%s (job #%d): mode=%s value=%s period=%s products=%d orders=%d',
            $adminId ?? 'unknown',
            $jobId,
            $preview['mode'],
            $preview['value'],
            $preview['period'],
            $appliedProductChanges,
            $appliedOrderChanges
        ));

        return [
            'job_id' => $jobId,
            'applied_products' => $appliedProductChanges,
            'applied_active_orders' => $appliedOrderChanges,
            'period' => $preview['period'],
        ];
    }

    public function previewDomainRenewalUpdate(array $data): array
    {
        $mode = $this->normalizeMode($data['mode'] ?? null);
        $value = (float) ($data['value'] ?? 0);
        $includeActiveOrders = $this->toBool($data['include_active_orders'] ?? true);

        $targets = $this->getTldTargets($data);
        $renewPriceByTld = [];

        foreach ($targets as &$target) {
            $target['current_renew_price'] = (float) $target['price_renew'];
            $target['new_renew_price'] = $this->calculateNewPrice($target['current_renew_price'], $mode, $value);
            $target['delta'] = round($target['new_renew_price'] - $target['current_renew_price'], 2);
            $renewPriceByTld[$this->normalizeTld((string) $target['tld'])] = $target['new_renew_price'];
        }
        unset($target);

        $orderImpact = [
            'count' => 0,
            'delta_total' => 0.0,
            'sample' => [],
            'rows' => [],
        ];

        if ($includeActiveOrders && !empty($renewPriceByTld)) {
            $orderImpact = $this->previewDomainOrderImpact($renewPriceByTld);
        }

        return [
            'scope' => 'domain_renewal',
            'mode' => $mode,
            'value' => $value,
            'filters' => [
                'tld' => isset($data['tld']) ? $this->normalizeTld((string) $data['tld']) : null,
                'tld_registrar_id' => isset($data['tld_registrar_id']) ? (int) $data['tld_registrar_id'] : null,
                'include_active_orders' => $includeActiveOrders,
            ],
            'tlds' => [
                'count' => count($targets),
                'delta_total' => round(array_sum(array_column($targets, 'delta')), 2),
                'sample' => array_slice($targets, 0, 100),
                'rows' => $targets,
            ],
            'active_orders' => [
                'count' => $orderImpact['count'],
                'delta_total' => $orderImpact['delta_total'],
                'sample' => $orderImpact['sample'],
                'rows' => $orderImpact['rows'],
            ],
        ];
    }

    public function applyDomainRenewalUpdate(array $data, ?int $adminId = null): array
    {
        $preview = $this->previewDomainRenewalUpdate($data);
        $tlds = $preview['tlds']['rows'];
        $orders = $preview['active_orders']['rows'];
        $db = $this->di['db'];

        $appliedTldChanges = count($tlds);
        $appliedOrderChanges = count($orders);

        try {
            $db->exec('START TRANSACTION');

            $this->batchUpdateTldRenewPrice($tlds, $db);
            $this->batchUpdateClientOrders($orders, $db);

            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');

            throw $e;
        }

        $jobId = $this->saveJob(
            'domain_renewal',
            $preview['mode'],
            (float) $preview['value'],
            $preview['filters'],
            [
                'tlds' => [
                    'count' => $preview['tlds']['count'],
                    'delta_total' => $preview['tlds']['delta_total'],
                ],
                'active_orders' => [
                    'count' => $preview['active_orders']['count'],
                    'delta_total' => $preview['active_orders']['delta_total'],
                ],
            ],
            $appliedTldChanges + $appliedOrderChanges,
            $adminId
        );

        $this->di['logger']->info(sprintf(
            'Bulk domain renewal pricing applied by admin #%s (job #%d): mode=%s value=%s tlds=%d orders=%d',
            $adminId ?? 'unknown',
            $jobId,
            $preview['mode'],
            $preview['value'],
            $appliedTldChanges,
            $appliedOrderChanges
        ));

        return [
            'job_id' => $jobId,
            'applied_tlds' => $appliedTldChanges,
            'applied_active_orders' => $appliedOrderChanges,
        ];
    }

    public function getJobs(array $data = []): array
    {
        $limit = isset($data['limit']) ? max(1, min(100, (int) $data['limit'])) : 25;
        $rows = $this->di['db']->getAll(
            'SELECT id, scope, mode, value, applied_changes, created_by, created_at
             FROM pricing_bulk_job
             ORDER BY id DESC
             LIMIT ' . $limit
        );

        return $rows ?: [];
    }

    public function getProductList(array $data = []): array
    {
        $query = trim((string) ($data['query'] ?? ''));
        $bindings = [':domain_type' => 'domain'];
        $where = ['p.type != :domain_type'];

        if ($query !== '') {
            $where[] = 'p.title LIKE :query';
            $bindings[':query'] = '%' . $query . '%';
        }

        $rows = $this->di['db']->getAll(
            sprintf(
                'SELECT p.id, p.title, p.status, p.product_category_id
                 FROM product p
                 WHERE %s
                 ORDER BY p.title ASC
                 LIMIT %d',
                implode(' AND ', $where),
                self::MAX_PRODUCT_LIST_ROWS
            ),
            $bindings
        );

        return $rows ?: [];
    }

    public function getSelectedProductPricing(array $data): array
    {
        $productIds = $this->extractIdList($data['product_ids'] ?? ($data['product_ids_csv'] ?? null));
        if (empty($productIds)) {
            return [
                'currency' => $this->getDefaultCurrencyCode(),
                'billing_cycles' => [],
                'rows' => [],
            ];
        }

        $bindings = [];
        $inClause = $this->buildInClause('selected_product_id', $productIds, $bindings);
        $rows = $this->di['db']->getAll(
            sprintf(
                'SELECT p.id AS product_id, p.title AS product_title,
                        pp.w_price, pp.w_setup_price, pp.w_enabled,
                        pp.m_price, pp.m_setup_price, pp.m_enabled,
                        pp.q_price, pp.q_setup_price, pp.q_enabled,
                        pp.b_price, pp.b_setup_price, pp.b_enabled,
                        pp.a_price, pp.a_setup_price, pp.a_enabled,
                        pp.bia_price, pp.bia_setup_price, pp.bia_enabled,
                        pp.tria_price, pp.tria_setup_price, pp.tria_enabled
                 FROM product p
                 INNER JOIN product_payment pp ON pp.id = p.product_payment_id
                 WHERE p.id IN (%s)
                 ORDER BY p.title ASC',
                $inClause
            ),
            $bindings
        ) ?: [];

        $pricingRows = [];
        $billingCycles = [];

        foreach ($rows as $row) {
            foreach (self::PERIOD_COLUMN_MAP as $period => $priceColumn) {
                $enabledColumn = str_replace('_price', '_enabled', $priceColumn);
                $setupColumn = str_replace('_price', '_setup_price', $priceColumn);

                $enabled = (int) ($row[$enabledColumn] ?? 0);
                $price = (float) ($row[$priceColumn] ?? 0);
                $setupPrice = (float) ($row[$setupColumn] ?? 0);

                if ($enabled === 1) {
                    $billingCycles[$period] = [
                        'code' => $period,
                        'label' => self::PERIOD_LABELS[$period] ?? $period,
                    ];
                }

                $pricingRows[] = [
                    'product_id' => (int) $row['product_id'],
                    'product_title' => $row['product_title'],
                    'period' => $period,
                    'period_label' => self::PERIOD_LABELS[$period] ?? $period,
                    'enabled' => $enabled,
                    'price' => $price,
                    'setup_price' => $setupPrice,
                ];
            }
        }

        return [
            'currency' => $this->getDefaultCurrencyCode(),
            'billing_cycles' => array_values($billingCycles),
            'rows' => $pricingRows,
        ];
    }

    private function getProductTargets(string $period, array $filters): array
    {
        $column = self::PERIOD_COLUMN_MAP[$period];
        $where = ['p.type != :domain_type'];
        $bindings = [':domain_type' => 'domain'];

        $productIds = $this->extractIdList($filters['product_ids'] ?? ($filters['product_ids_csv'] ?? null));
        if (!empty($productIds)) {
            $where[] = sprintf('p.id IN (%s)', $this->buildInClause('filter_product_id', $productIds, $bindings));
        }

        if (!empty($filters['product_category_id'])) {
            $where[] = 'p.product_category_id = :category_id';
            $bindings[':category_id'] = (int) $filters['product_category_id'];
        }

        if (!empty($filters['product_id'])) {
            $where[] = 'p.id = :product_id';
            $bindings[':product_id'] = (int) $filters['product_id'];
        }

        $sql = sprintf(
            'SELECT p.id, p.title, p.product_category_id, pp.%s AS current_price
             FROM product p
             INNER JOIN product_payment pp ON pp.id = p.product_payment_id
             WHERE %s
             ORDER BY p.title',
            $column,
            implode(' AND ', $where)
        );

        return $this->di['db']->getAll($sql, $bindings) ?: [];
    }

    private function previewProductOrderImpact(array $productPriceMap, string $period): array
    {
        $bindings = [':period' => $period];
        $inClause = $this->buildInClause('product_id', array_keys($productPriceMap), $bindings);
        $statusBindings = $this->buildStatusBindings($bindings);

        $sql = sprintf(
            'SELECT co.id, co.client_id, co.product_id, co.currency, co.price, co.period, co.status
             FROM client_order co
             WHERE co.period = :period
               AND co.product_id IN (%s)
               AND co.status IN (%s)
             ORDER BY co.id DESC
             LIMIT %d',
            $inClause,
            $statusBindings,
            self::MAX_ORDER_ROWS
        );

        $rows = $this->di['db']->getAll($sql, $bindings) ?: [];
        $rateCache = [];

        foreach ($rows as &$row) {
            $current = (float) $row['price'];
            $basePrice = (float) $productPriceMap[(int) $row['product_id']];
            $rate = $this->getCurrencyRate($rateCache, (string) $row['currency']);
            $newPrice = round($basePrice * $rate, 2);

            $row['current_price'] = $current;
            $row['new_price'] = $newPrice;
            $row['delta'] = round($newPrice - $current, 2);
        }
        unset($row);

        return [
            'count' => count($rows),
            'delta_total' => round(array_sum(array_column($rows, 'delta')), 2),
            'sample' => array_slice($rows, 0, 100),
            'rows' => $rows,
        ];
    }

    private function getTldTargets(array $filters): array
    {
        $where = ['1 = 1'];
        $bindings = [];

        if (!empty($filters['tld'])) {
            $where[] = 'tld = :tld';
            $bindings[':tld'] = $this->normalizeTld((string) $filters['tld']);
        }

        if (!empty($filters['tld_registrar_id'])) {
            $where[] = 'tld_registrar_id = :registrar_id';
            $bindings[':registrar_id'] = (int) $filters['tld_registrar_id'];
        }

        $sql = sprintf(
            'SELECT id, tld, tld_registrar_id, price_renew
             FROM tld
             WHERE %s
             ORDER BY tld',
            implode(' AND ', $where)
        );

        return $this->di['db']->getAll($sql, $bindings) ?: [];
    }

    private function previewDomainOrderImpact(array $renewPriceByTld): array
    {
        $bindings = [];
        $statusBindings = $this->buildStatusBindings($bindings);

        $sql = sprintf(
            'SELECT co.id, co.client_id, co.product_id, co.currency, co.price, co.period, co.status, co.config
             FROM client_order co
             INNER JOIN product p ON p.id = co.product_id
             WHERE p.type = :domain_type
               AND co.period IS NOT NULL
               AND co.status IN (%s)
             ORDER BY co.id DESC
             LIMIT %d',
            $statusBindings,
            self::MAX_ORDER_ROWS
        );
        $bindings[':domain_type'] = 'domain';

        $rows = $this->di['db']->getAll($sql, $bindings) ?: [];
        $rateCache = [];
        $affected = [];

        foreach ($rows as $row) {
            $tld = $this->extractTldFromOrderConfig((string) ($row['config'] ?? ''));
            if ($tld === null || !array_key_exists($tld, $renewPriceByTld)) {
                continue;
            }

            $current = (float) $row['price'];
            $baseRenewPrice = (float) $renewPriceByTld[$tld];
            $rate = $this->getCurrencyRate($rateCache, (string) $row['currency']);
            $newPrice = round($baseRenewPrice * $rate, 2);

            $row['tld'] = $tld;
            $row['current_price'] = $current;
            $row['new_price'] = $newPrice;
            $row['delta'] = round($newPrice - $current, 2);
            unset($row['config']);

            $affected[] = $row;
        }

        return [
            'count' => count($affected),
            'delta_total' => round(array_sum(array_column($affected, 'delta')), 2),
            'sample' => array_slice($affected, 0, 100),
            'rows' => $affected,
        ];
    }

    private function normalizePeriod(?string $period): string
    {
        $period = strtoupper(trim((string) $period));
        if (!array_key_exists($period, self::PERIOD_COLUMN_MAP)) {
            throw new \FOSSBilling\InformationException('Invalid period. Allowed values: 1W, 1M, 3M, 6M, 1Y, 2Y, 3Y');
        }

        return $period;
    }

    private function normalizeMode(?string $mode): string
    {
        $mode = strtolower(trim((string) $mode));
        if (!in_array($mode, ['set', 'percent'], true)) {
            throw new \FOSSBilling\InformationException('Invalid mode. Allowed values: set, percent');
        }

        return $mode;
    }

    private function normalizeTld(string $tld): string
    {
        $normalized = strtolower(trim($tld));
        if ($normalized === '') {
            return $normalized;
        }

        if (!str_starts_with($normalized, '.')) {
            $normalized = '.' . $normalized;
        }

        if (strlen($normalized) > self::MAX_TLD_LENGTH) {
            throw new \FOSSBilling\InformationException('TLD value is too long');
        }

        return $normalized;
    }

    private function calculateNewPrice(float $currentPrice, string $mode, float $value): float
    {
        $newPrice = $mode === 'set'
            ? $value
            : ($currentPrice + ($currentPrice * $value / 100));

        return round(max(0, $newPrice), 2);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $str = strtolower(trim((string) $value));

        return in_array($str, ['1', 'true', 'yes', 'on'], true);
    }

    private function buildInClause(string $prefix, array $values, array &$bindings): string
    {
        $placeholders = [];
        $index = 0;

        foreach ($values as $value) {
            $key = ':' . $prefix . '_' . $index;
            $bindings[$key] = (int) $value;
            $placeholders[] = $key;
            ++$index;
        }

        if (empty($placeholders)) {
            $bindings[':' . $prefix . '_none'] = -1;

            return ':' . $prefix . '_none';
        }

        return implode(', ', $placeholders);
    }

    private function buildStatusBindings(array &$bindings): string
    {
        $statusPlaceholders = [];
        foreach (self::ACTIVE_ORDER_STATUSES as $idx => $status) {
            $key = ':status_' . $idx;
            $bindings[$key] = $status;
            $statusPlaceholders[] = $key;
        }

        return implode(', ', $statusPlaceholders);
    }

    private function extractTldFromOrderConfig(string $rawConfig): ?string
    {
        if ($rawConfig === '') {
            return null;
        }

        $config = json_decode($rawConfig, true);
        if (!is_array($config)) {
            return null;
        }

        $action = $config['action'] ?? null;
        $tld = null;

        if ($action === 'register') {
            $tld = $config['register_tld'] ?? null;
        } elseif ($action === 'transfer') {
            $tld = $config['transfer_tld'] ?? null;
        } elseif ($action === 'owndomain') {
            $tld = $config['owndomain_tld'] ?? null;
        }

        if ($tld === null) {
            $tld = $config['register_tld'] ?? $config['transfer_tld'] ?? $config['owndomain_tld'] ?? null;
        }

        if ($tld === null || trim((string) $tld) === '') {
            return null;
        }

        return $this->normalizeTld((string) $tld);
    }

    private function getCurrencyRate(array &$rateCache, string $currency): float
    {
        if (!isset($rateCache[$currency])) {
            $rate = $this->di['mod_service']('Currency')->getCurrencyRepository()->getRateByCode($currency);
            $rateCache[$currency] = $rate !== null ? (float) $rate : 1.0;
        }

        return $rateCache[$currency];
    }

    private function saveJob(
        string $scope,
        string $mode,
        float $value,
        array $filters,
        array $preview,
        int $appliedChanges,
        ?int $adminId,
    ): int {
        $this->di['dbal']->executeStatement(
            'INSERT INTO pricing_bulk_job
                (scope, mode, value, filters_json, preview_json, applied_changes, created_by, created_at)
             VALUES
                (:scope, :mode, :value, :filters_json, :preview_json, :applied_changes, :created_by, :created_at)',
            [
                'scope' => $scope,
                'mode' => $mode,
                'value' => $value,
                'filters_json' => json_encode($filters, JSON_UNESCAPED_SLASHES),
                'preview_json' => json_encode($preview, JSON_UNESCAPED_SLASHES),
                'applied_changes' => $appliedChanges,
                'created_by' => $adminId,
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );

        return (int) $this->di['dbal']->lastInsertId();
    }

    private function extractIdList(mixed $rawIds): array
    {
        if (is_array($rawIds)) {
            $values = $rawIds;
        } else {
            $rawString = trim((string) $rawIds);
            if ($rawString === '') {
                return [];
            }

            $values = explode(',', $rawString);
        }

        $ids = [];
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique(array_slice($ids, 0, 500)));
    }

    private function getDefaultCurrencyCode(): string
    {
        $default = $this->di['mod_service']('Currency')->getCurrencyRepository()->findDefault();

        return $default !== null ? $default->getCode() : 'USD';
    }

    /**
     * Batch-update `product_payment.{$column}` for multiple products in a single statement.
     *
     * @param string $column   a validated column name from PERIOD_COLUMN_MAP (never user-supplied)
     * @param array  $products rows with keys `id` and `new_price`
     * @param mixed  $db       the FOSSBilling db instance
     */
    private function batchUpdateProductPayment(string $column, array $products, mixed $db): void
    {
        if (empty($products)) {
            return;
        }

        $bindings = [];
        $caseParts = [];
        $idPlaceholders = [];

        foreach ($products as $i => $product) {
            $idKey = ':bup_id_' . $i;
            $priceKey = ':bup_price_' . $i;
            $bindings[$idKey] = (int) $product['id'];
            $bindings[$priceKey] = (float) $product['new_price'];
            $caseParts[] = "WHEN {$idKey} THEN {$priceKey}";
            $idPlaceholders[] = $idKey;
        }

        // $column is always a validated constant from PERIOD_COLUMN_MAP — safe to interpolate.
        $db->exec(
            sprintf(
                'UPDATE product_payment pp
                   INNER JOIN product p ON pp.id = p.product_payment_id
                 SET pp.%s = CASE p.id %s END
                 WHERE p.id IN (%s)',
                $column,
                implode(' ', $caseParts),
                implode(', ', $idPlaceholders)
            ),
            $bindings
        );
    }

    /**
     * Batch-update `client_order.price` for multiple orders in a single statement.
     *
     * @param array $orders rows with keys `id` and `new_price`
     * @param mixed $db     the FOSSBilling db instance
     */
    private function batchUpdateClientOrders(array $orders, mixed $db): void
    {
        if (empty($orders)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $bindings = [':buo_updated_at' => $now];
        $caseParts = [];
        $idPlaceholders = [];

        foreach ($orders as $i => $order) {
            $idKey = ':buo_id_' . $i;
            $priceKey = ':buo_price_' . $i;
            $bindings[$idKey] = (int) $order['id'];
            $bindings[$priceKey] = (float) $order['new_price'];
            $caseParts[] = "WHEN {$idKey} THEN {$priceKey}";
            $idPlaceholders[] = $idKey;
        }

        $db->exec(
            sprintf(
                'UPDATE client_order
                 SET price = CASE id %s END, updated_at = :buo_updated_at
                 WHERE id IN (%s)',
                implode(' ', $caseParts),
                implode(', ', $idPlaceholders)
            ),
            $bindings
        );
    }

    /**
     * Batch-update `tld.price_renew` for multiple TLDs in a single statement.
     *
     * @param array $tlds rows with keys `id` and `new_renew_price`
     * @param mixed $db   the FOSSBilling db instance
     */
    private function batchUpdateTldRenewPrice(array $tlds, mixed $db): void
    {
        if (empty($tlds)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $bindings = [':but_updated_at' => $now];
        $caseParts = [];
        $idPlaceholders = [];

        foreach ($tlds as $i => $tld) {
            $idKey = ':but_id_' . $i;
            $priceKey = ':but_price_' . $i;
            $bindings[$idKey] = (int) $tld['id'];
            $bindings[$priceKey] = (float) $tld['new_renew_price'];
            $caseParts[] = "WHEN {$idKey} THEN {$priceKey}";
            $idPlaceholders[] = $idKey;
        }

        $db->exec(
            sprintf(
                'UPDATE tld
                 SET price_renew = CASE id %s END, updated_at = :but_updated_at
                 WHERE id IN (%s)',
                implode(' ', $caseParts),
                implode(', ', $idPlaceholders)
            ),
            $bindings
        );
    }
}
