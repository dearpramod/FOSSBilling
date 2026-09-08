<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Order\Controller;

class Client implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/order', 'get_products', [], static::class);
        $app->get('/order/service', 'get_orders', [], static::class);
        $app->get('/order/service/manage/:id', 'get_order', ['id' => '[0-9]+'], static::class);
        $app->get('/order/checkout', 'get_checkout', [], static::class);
        $app->get('/order/domain-registration', 'get_domain_registration', [], static::class);
        $app->get('/order/domain-transfer', 'get_domain_transfer', [], static::class);
        $app->get('/order/:id', 'get_configure_product', ['id' => '[0-9]+'], static::class);
        $app->get('/order/:slug', 'get_configure_product_by_slug', ['slug' => '[a-z0-9-]+'], static::class);
    }

    public function get_products(\Box_App $app): string
    {
        return $app->render('mod_order_index');
    }

    public function get_checkout(\Box_App $app): string
    {
        return $app->render('mod_order_checkout');
    }

    public function get_domain_registration(\Box_App $app): string
    {
        return $app->render('mod_order_domain_plans', [
            'hosting_bundles' => $this->getFreeDomainHostingBundles(),
        ]);
    }

    /**
     * Active hosting products that grant a free domain registration, shaped for the
     * domain-flow "get it free with hosting" upsell step. The free-domain config
     * (free_domain / free_tlds / free_domain_periods) is stripped from the guest
     * product API (getPublicConfig), so it is read here server-side. Prices are the
     * base-currency amount for the qualifying free period; the template applies the
     * money_convert filter. Local patch — re-audit after FOSSBilling upgrades.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getFreeDomainHostingBundles(): array
    {
        try {
            $productService = $this->di['mod_service']('product');
            $products = $this->di['em']->getRepository(\Box\Mod\Product\Entity\Product::class)
                ->findBy(['type' => \Box\Mod\Product\Service::HOSTING]);
        } catch (\Throwable $e) {
            $this->di['logger']->setChannel('order')->info('Free-domain bundle lookup failed: ' . $e->getMessage());

            return [];
        }

        $periodOrder = ['1M', '2M', '3M', '4M', '5M', '6M', '7M', '8M', '9M', '10M', '11M', '1Y', '2Y', '3Y', '4Y', '5Y'];
        $periodLabels = ['1M' => '1 month', '3M' => '3 months', '6M' => '6 months', '1Y' => '1 year', '2Y' => '2 years', '3Y' => '3 years'];

        $bundles = [];
        foreach ($products as $product) {
            if ($product->getStatus() !== 'enabled' || $product->isHidden()) {
                continue;
            }

            $config = json_decode($product->getConfig() ?? '', true) ?: [];
            // Must grant a free registration AND allow the register action.
            if (empty($config['free_domain']) || empty($config['allow_domain_register'])) {
                continue;
            }

            $freeTlds = array_values($config['free_tlds'] ?? []);
            $freePeriods = $config['free_domain_periods'] ?? [];
            if ($freeTlds === [] || $freePeriods === []) {
                continue;
            }

            try {
                $pricing = $productService->getProductPricingArray($product);
            } catch (\Throwable) {
                continue;
            }
            $recurrent = $pricing['recurrent'] ?? [];

            // Shortest qualifying free period that actually has enabled pricing.
            $period = null;
            foreach ($periodOrder as $p) {
                if (in_array($p, $freePeriods, true) && !empty($recurrent[$p]['enabled'])) {
                    $period = $p;

                    break;
                }
            }
            if ($period === null) {
                continue;
            }

            $bundles[] = [
                'id' => (int) $product->getId(),
                'title' => $product->getTitle(),
                'description' => $product->getDescription(),
                'free_tlds' => $freeTlds,
                'free_period' => $period,
                'free_period_label' => $periodLabels[$period] ?? $period,
                'price_raw' => (float) ($recurrent[$period]['price'] ?? 0),
            ];
        }

        usort($bundles, static fn ($a, $b) => $a['price_raw'] <=> $b['price_raw']);

        return $bundles;
    }

    public function get_domain_transfer(\Box_App $app): string
    {
        return $app->render('mod_order_domain_transfer');
    }

    public function get_configure_product_by_slug(\Box_App $app, $slug): string
    {
        $api = $this->di['api_guest'];

        // Try product lookup first — only the API call is wrapped, not the render.
        $product = null;

        try {
            $product = $api->product_get(['slug' => $slug]);
        } catch (\Exception) {
            // Product not found by slug — fall through to category lookup.
        }

        if ($product !== null) {
            $tpl = 'mod_service' . $product['type'] . '_order';
            if ($api->system_template_exists(['file' => $tpl . '.html.twig'])) {
                return $app->render($tpl, ['product' => $product]);
            }

            return $app->render('mod_order_product', ['product' => $product]);
        }

        // Try matching the slug against slugified category titles.
        $categories = $api->product_category_get_list(['per_page' => 200, 'deep' => 0]);
        foreach ($categories['list'] as $cat) {
            $catSlug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($cat['title'] ?? ''))), '-');
            if ($catSlug === $slug) {
                return $app->render('mod_order_category', ['category_id' => $cat['id'], 'category_slug' => $slug]);
            }
        }

        throw new \FOSSBilling\InformationException('Page not found', [], 404);
    }

    public function get_configure_product(\Box_App $app, $id): string
    {
        $api = $this->di['api_guest'];
        $product = $api->product_get(['id' => $id]);
        $tpl = 'mod_service' . $product['type'] . '_order';
        if ($api->system_template_exists(['file' => $tpl . '.html.twig'])) {
            return $app->render($tpl, ['product' => $product]);
        }

        return $app->render('mod_order_product', ['product' => $product]);
    }

    public function get_orders(\Box_App $app): string
    {
        $this->di['is_client_logged'];

        return $app->render('mod_order_list');
    }

    public function get_order(\Box_App $app, $id): string
    {
        $api = $this->di['api_client'];
        $data = [
            'id' => $id,
        ];
        $order = $api->order_get($data);

        return $app->render('mod_order_manage', ['order' => $order]);
    }
}
