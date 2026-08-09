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
        return $app->render('mod_order_domain_plans');
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
