<?php

declare(strict_types=1);

/**
 * Partnership Pricing Module for FOSSBilling.
 *
 * Stores custom per-product/period and per-TLD pricing rules scoped to
 * client groups. Partner prices are applied silently during cart checkout
 * and surfaced on the partnership program page when the partner client is logged in.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Partnership;

class Service implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    /** @var int|false|null Cached client group ID for the current request. false = not yet resolved, null = no group. */
    private int|false|null $cachedClientGroupId = false;

    /**
     * Per-request partner price cache keyed by "groupId:productId:period".
     *
     * @var array<string, float|null>
     */
    private array $productPriceCache = [];

    /**
     * Per-request TLD price cache keyed by "groupId:tld".
     *
     * @var array<string, array<string, float>|null>
     */
    private array $tldPriceCache = [];

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
                'display_name' => 'Manage partner pricing',
                'description' => 'Allows staff to create, update, and delete partnership pricing rules.',
            ],
        ];
    }

    /* ================================================================
     *  Install / Uninstall
     * ================================================================ */

    public function install(): bool
    {
        $db = $this->di['db'];

        $db->exec("
            CREATE TABLE IF NOT EXISTS `partner_product_pricing` (
                `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `client_group_id` BIGINT UNSIGNED NOT NULL,
                `product_id`      BIGINT UNSIGNED NOT NULL,
                `period`          VARCHAR(10)     NULL DEFAULT NULL COMMENT '1M,3M,6M,1Y,2Y,3Y — NULL means all periods',
                `price`           DECIMAL(18,2)   NOT NULL,
                `created_at`      DATETIME        NOT NULL,
                `updated_at`      DATETIME        NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_partner_product` (`client_group_id`, `product_id`, `period`),
                KEY `idx_group_product` (`client_group_id`, `product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec('
            CREATE TABLE IF NOT EXISTS `partner_tld_pricing` (
                `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `client_group_id`     BIGINT UNSIGNED NOT NULL,
                `tld`                 VARCHAR(32)     NOT NULL,
                `price_registration`  DECIMAL(18,2)   NOT NULL DEFAULT 0.00,
                `price_renew`         DECIMAL(18,2)   NOT NULL DEFAULT 0.00,
                `price_transfer`      DECIMAL(18,2)   NOT NULL DEFAULT 0.00,
                `created_at`          DATETIME        NOT NULL,
                `updated_at`          DATETIME        NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_partner_tld` (`client_group_id`, `tld`),
                KEY `idx_group_tld` (`client_group_id`, `tld`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        return true;
    }

    public function uninstall(): bool
    {
        $db = $this->di['db'];
        $db->exec('DROP TABLE IF EXISTS `partner_product_pricing`');
        $db->exec('DROP TABLE IF EXISTS `partner_tld_pricing`');

        return true;
    }

    /* ================================================================
     *  Core Pricing Resolution
     * ================================================================ */

    /**
     * Return the partner price for a cart item, or null if no rule applies.
     *
     * Called from Cart/Service.php::cartProductToApiArray() when this module is active.
     * Returning null means "use the normal product price".
     */
    public function getPartnerPriceForCartItem(int $productId, string $productType, array $config): ?float
    {
        $clientGroupId = $this->getLoggedInClientGroupId();
        if ($clientGroupId === null) {
            return null;
        }

        if ($productType === 'domain') {
            return $this->getDomainPartnerPrice($clientGroupId, $config);
        }

        return $this->getProductPartnerPrice($clientGroupId, $productId, $config['period'] ?? null);
    }

    private function getLoggedInClientGroupId(): ?int
    {
        // Avoid triggering the redirect that $di['loggedin_client'] issues for browser
        // requests when no client session exists — that causes an infinite redirect loop
        // on pages (e.g. login, checkout) that include the cart while no client is authed.
        if ($this->cachedClientGroupId !== false) {
            return $this->cachedClientGroupId;
        }

        if (!$this->di['auth']->isClientLoggedIn()) {
            return $this->cachedClientGroupId = null;
        }

        try {
            $client = $this->di['loggedin_client'];
        } catch (\Exception) {
            return $this->cachedClientGroupId = null;
        }

        if (!$client || !$client->client_group_id) {
            return $this->cachedClientGroupId = null;
        }

        return $this->cachedClientGroupId = (int) $client->client_group_id;
    }

    private function getProductPartnerPrice(int $groupId, int $productId, ?string $period): ?float
    {
        $cacheKey = $groupId . ':' . $productId . ':' . ($period ?? '');
        if (array_key_exists($cacheKey, $this->productPriceCache)) {
            return $this->productPriceCache[$cacheKey];
        }

        $db = $this->di['db'];

        if ($period !== null) {
            // Exact period match first, catch-all (period IS NULL) as fallback.
            // ORDER BY (period IS NULL) returns 0 for exact match — exact always wins.
            $row = $db->getRow(
                'SELECT price FROM partner_product_pricing
                  WHERE client_group_id = :gid AND product_id = :pid
                    AND (period = :period OR period IS NULL)
                  ORDER BY (period IS NULL)
                  LIMIT 1',
                [':gid' => $groupId, ':pid' => $productId, ':period' => $period]
            );
        } else {
            $row = $db->getRow(
                'SELECT price FROM partner_product_pricing
                  WHERE client_group_id = :gid AND product_id = :pid AND period IS NULL LIMIT 1',
                [':gid' => $groupId, ':pid' => $productId]
            );
        }

        return $this->productPriceCache[$cacheKey] = $row ? (float) $row['price'] : null;
    }

    private function getDomainPartnerPrice(int $groupId, array $config): ?float
    {
        $action = $config['action'] ?? null;
        if ($action === 'owndomain') {
            return 0.0;
        }

        $tld = match ($action) {
            'register' => $config['register_tld'] ?? null,
            'transfer' => $config['transfer_tld'] ?? null,
            default => null,
        };

        if ($tld === null) {
            return null;
        }

        $cacheKey = $groupId . ':' . $tld;
        if (!array_key_exists($cacheKey, $this->tldPriceCache)) {
            $row = $this->di['db']->getRow(
                'SELECT price_registration, price_renew, price_transfer FROM partner_tld_pricing
                  WHERE client_group_id = :gid AND tld = :tld LIMIT 1',
                [':gid' => $groupId, ':tld' => $tld]
            );
            $this->tldPriceCache[$cacheKey] = $row ?: null;
        }

        $row = $this->tldPriceCache[$cacheKey];
        if (!$row) {
            return null;
        }

        return match ($action) {
            'register' => (float) $row['price_registration'],
            'transfer' => (float) $row['price_transfer'],
            default => null,
        };
    }

    /* ================================================================
     *  Product Pricing CRUD
     * ================================================================ */

    public function getProductPricingList(array $data): array
    {
        $db = $this->di['db'];

        $where = [];
        $bindings = [];

        if (!empty($data['client_group_id'])) {
            $where[] = 'ppp.client_group_id = :gid';
            $bindings[':gid'] = (int) $data['client_group_id'];
        }
        if (!empty($data['product_id'])) {
            $where[] = 'ppp.product_id = :pid';
            $bindings[':pid'] = (int) $data['product_id'];
        }

        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $rows = $db->getAll(
            "SELECT ppp.id, ppp.client_group_id, ppp.product_id, ppp.period, ppp.price,
                    cg.title AS group_title, p.title AS product_title,
                    ppp.created_at, ppp.updated_at
               FROM partner_product_pricing ppp
          LEFT JOIN client_group cg ON cg.id = ppp.client_group_id
          LEFT JOIN product p ON p.id = ppp.product_id
            $whereStr
           ORDER BY cg.title, p.title, ppp.period",
            $bindings
        );

        return $rows ?: [];
    }

    public function createProductPricing(array $data): int
    {
        $db = $this->di['db'];
        $now = date('Y-m-d H:i:s');

        $db->exec(
            'INSERT INTO partner_product_pricing
                (client_group_id, product_id, period, price, created_at, updated_at)
             VALUES (:gid, :pid, :period, :price, :ca, :ua)
             ON DUPLICATE KEY UPDATE price = VALUES(price), updated_at = VALUES(updated_at)',
            [
                ':gid' => (int) $data['client_group_id'],
                ':pid' => (int) $data['product_id'],
                ':period' => $data['period'] ?? null,
                ':price' => (float) $data['price'],
                ':ca' => $now,
                ':ua' => $now,
            ]
        );

        $id = (int) $db->getInsertId();

        $this->di['logger']->info(sprintf(
            'Partner product pricing rule created/updated: group=%d product=%d period=%s price=%s (rule id=%d)',
            (int) $data['client_group_id'],
            (int) $data['product_id'],
            $data['period'] ?? 'all',
            (float) $data['price'],
            $id
        ));

        return $id;
    }

    public function updateProductPricing(array $data): bool
    {
        $db = $this->di['db'];
        $db->exec(
            'UPDATE partner_product_pricing
                SET price = :price, period = :period, updated_at = :ua
              WHERE id = :id',
            [
                ':id' => (int) $data['id'],
                ':price' => (float) $data['price'],
                ':period' => $data['period'] ?? null,
                ':ua' => date('Y-m-d H:i:s'),
            ]
        );

        $this->di['logger']->info(sprintf(
            'Partner product pricing rule updated: id=%d period=%s price=%s',
            (int) $data['id'],
            $data['period'] ?? 'all',
            (float) $data['price']
        ));

        return true;
    }

    public function deleteProductPricing(int $id): bool
    {
        $this->di['db']->exec(
            'DELETE FROM partner_product_pricing WHERE id = :id',
            [':id' => $id]
        );

        $this->di['logger']->info(sprintf('Partner product pricing rule deleted: id=%d', $id));

        return true;
    }

    /* ================================================================
     *  TLD Pricing CRUD
     * ================================================================ */

    public function getTldPricingList(array $data): array
    {
        $db = $this->di['db'];

        $where = [];
        $bindings = [];

        if (!empty($data['client_group_id'])) {
            $where[] = 'ptp.client_group_id = :gid';
            $bindings[':gid'] = (int) $data['client_group_id'];
        }

        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $rows = $db->getAll(
            "SELECT ptp.id, ptp.client_group_id, ptp.tld,
                    ptp.price_registration, ptp.price_renew, ptp.price_transfer,
                    cg.title AS group_title, ptp.created_at, ptp.updated_at
               FROM partner_tld_pricing ptp
          LEFT JOIN client_group cg ON cg.id = ptp.client_group_id
            $whereStr
           ORDER BY cg.title, ptp.tld",
            $bindings
        );

        return $rows ?: [];
    }

    public function createTldPricing(array $data): int
    {
        $db = $this->di['db'];
        $now = date('Y-m-d H:i:s');

        $tld = (string) $data['tld'];
        if (!str_starts_with($tld, '.')) {
            $tld = '.' . $tld;
        }

        $db->exec(
            'INSERT INTO partner_tld_pricing
                (client_group_id, tld, price_registration, price_renew, price_transfer, created_at, updated_at)
             VALUES (:gid, :tld, :reg, :renew, :transfer, :ca, :ua)
             ON DUPLICATE KEY UPDATE
                price_registration = VALUES(price_registration),
                price_renew        = VALUES(price_renew),
                price_transfer     = VALUES(price_transfer),
                updated_at         = VALUES(updated_at)',
            [
                ':gid' => (int) $data['client_group_id'],
                ':tld' => strtolower($tld),
                ':reg' => (float) ($data['price_registration'] ?? 0),
                ':renew' => (float) ($data['price_renew'] ?? 0),
                ':transfer' => (float) ($data['price_transfer'] ?? 0),
                ':ca' => $now,
                ':ua' => $now,
            ]
        );

        $id = (int) $db->getInsertId();

        $this->di['logger']->info(sprintf(
            'Partner TLD pricing rule created/updated: group=%d tld=%s (rule id=%d)',
            (int) $data['client_group_id'],
            $tld,
            $id
        ));

        return $id;
    }

    public function updateTldPricing(array $data): bool
    {
        $db = $this->di['db'];
        $db->exec(
            'UPDATE partner_tld_pricing
                SET price_registration = :reg,
                    price_renew        = :renew,
                    price_transfer     = :transfer,
                    updated_at         = :ua
              WHERE id = :id',
            [
                ':id' => (int) $data['id'],
                ':reg' => (float) ($data['price_registration'] ?? 0),
                ':renew' => (float) ($data['price_renew'] ?? 0),
                ':transfer' => (float) ($data['price_transfer'] ?? 0),
                ':ua' => date('Y-m-d H:i:s'),
            ]
        );

        $this->di['logger']->info(sprintf(
            'Partner TLD pricing rule updated: id=%d reg=%s renew=%s transfer=%s',
            (int) $data['id'],
            (float) ($data['price_registration'] ?? 0),
            (float) ($data['price_renew'] ?? 0),
            (float) ($data['price_transfer'] ?? 0)
        ));

        return true;
    }

    public function deleteTldPricing(int $id): bool
    {
        $this->di['db']->exec(
            'DELETE FROM partner_tld_pricing WHERE id = :id',
            [':id' => $id]
        );

        $this->di['logger']->info(sprintf('Partner TLD pricing rule deleted: id=%d', $id));

        return true;
    }

    /* ================================================================
     *  Client-facing helpers
     * ================================================================ */

    /**
     * Return all partner pricing for the logged-in client's group.
     *
     * @return array{product_prices: list<array>, tld_prices: list<array>}
     */
    public function getPricingForLoggedInClient(): array
    {
        $clientGroupId = $this->getLoggedInClientGroupId();
        if ($clientGroupId === null) {
            return ['product_prices' => [], 'tld_prices' => []];
        }

        $productPrices = $this->di['db']->getAll(
            'SELECT product_id, period, price FROM partner_product_pricing
              WHERE client_group_id = :gid ORDER BY product_id, period',
            [':gid' => $clientGroupId]
        ) ?: [];

        $tldPrices = $this->di['db']->getAll(
            'SELECT tld, price_registration, price_renew, price_transfer
               FROM partner_tld_pricing
              WHERE client_group_id = :gid ORDER BY tld',
            [':gid' => $clientGroupId]
        ) ?: [];

        return [
            'product_prices' => $productPrices,
            'tld_prices' => $tldPrices,
        ];
    }
}
