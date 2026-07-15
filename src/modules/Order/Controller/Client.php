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
        $app->get('/order/checkout', 'get_checkout', [], static::class);
        $app->post('/order/checkout/login', 'post_checkout_login', [], static::class);
        $app->get('/order/service', 'get_orders', [], static::class);
        $app->get('/order/service/manage/:id', 'get_order', ['id' => '[0-9]+'], static::class);
        $app->get('/order/domain-registration', 'get_domain_order', [], static::class);
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

    public function post_checkout_login(\Box_App $app): never
    {
        $token        = $_POST['CSRFToken'] ?? '';
        $sessionToken = (string) $this->di['session']->get('csrf_token');

        if (empty($token) || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
            $app->redirect('/order/checkout?auth_error=' . urlencode('Security check failed, please try again.'));
        }

        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $app->redirect('/order/checkout?auth_error=' . urlencode('Email and password are required.'));
        }

        try {
            $this->di['api_guest']->client_login(['email' => $email, 'password' => $password]);
        } catch (\Exception $e) {
            $app->redirect('/order/checkout?auth_tab=login&auth_error=' . urlencode($e->getMessage()));
        }

        $app->redirect('/order/checkout');
    }

    public function get_configure_product_by_slug(\Box_App $app, $slug): string|\Symfony\Component\HttpFoundation\Response
    {
        $api = $this->di['api_guest'];

        // First: try to match a product by its slug.
        try {
            $product = $api->product_get(['slug' => $slug]);
            $tpl = 'mod_service' . $product['type'] . '_order';
            if ($api->system_template_exists(['file' => $tpl . '.html.twig'])) {
                return $app->render($tpl, ['product' => $product]);
            }

            return $app->render('mod_order_product', ['product' => $product]);
        } catch (\FOSSBilling\Exception $e) {
            // Not a product slug — fall through to category lookup.
        }

        // Second: try to match a product category by title-derived slug.
        $categories = $api->product_category_get_list(['per_page' => 200]);
        foreach ($categories['list'] as $cat) {
            if ($this->titleToSlug($cat['title']) === $slug) {
                $productId = $this->di['request']->query->get('product');
                if ($productId && ctype_digit((string) $productId)) {
                    $product  = $api->product_get(['id' => (int) $productId]);
                    $orderTpl = 'mod_order_' . $product['type'];
                    if ($api->system_template_exists(['file' => $orderTpl . '.html.twig'])) {
                        return $app->render($orderTpl);
                    }
                    $formTpl = 'mod_service' . $product['type'] . '_order_form';
                    if ($api->system_template_exists(['file' => $formTpl . '.html.twig'])) {
                        return $app->render('mod_order_category', [
                            'category_id'   => $cat['id'],
                            'category_slug' => $slug,
                        ]);
                    }

                    return $app->redirect('/order/' . (int) $productId);
                }

                return $app->render('mod_order_category', [
                    'category_id'   => $cat['id'],
                    'category_slug' => $slug,
                ]);
            }
        }

        return $app->show404(new \FOSSBilling\InformationException('Page :url not found', [':url' => '/order/' . $slug], 404));
    }

    public function get_configure_product(\Box_App $app, $id): string
    {
        $api     = $this->di['api_guest'];
        $product = $api->product_get(['id' => (int) $id]);

        $formTpl = 'mod_service' . $product['type'] . '_order_form';
        if ($api->system_template_exists(['file' => $formTpl . '.html.twig'])
            && !empty($product['product_category_id'])
        ) {
            $categoryId = (int) $product['product_category_id'];
            $categories = $api->product_category_get_list(['per_page' => 200]);
            foreach ($categories['list'] as $cat) {
                if ((int) $cat['id'] === $categoryId) {
                    $app->redirect('/order/' . $this->titleToSlug($cat['title']) . '?product=' . (int) $id);
                }
            }
        }

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

    public function get_domain_order(\Box_App $app): string
    {
        return $app->render('mod_order_domain_plans');
    }

    public function get_domain_transfer(\Box_App $app): string
    {
        return $app->render('mod_order_domain_transfer');
    }

    public function get_order(\Box_App $app, $id): string
    {
        $api   = $this->di['api_client'];
        $order = $api->order_get(['id' => $id]);

        return $app->render('mod_order_manage', ['order' => $order]);
    }

    /**
     * Convert a human-readable title to a URL slug.
     * e.g. "Web Hosting" → "web-hosting"
     */
    private function titleToSlug(string $title): string
    {
        $slug = mb_strtolower($title);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }
}
