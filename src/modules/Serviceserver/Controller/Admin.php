<?php

declare(strict_types=1);

/**
 * FOSSBilling Server Product Type — Admin Controller
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Serviceserver\Controller;

class Admin implements \FOSSBilling\InjectionAwareInterface
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

    public function fetchNavigation(): array
    {
        return [
            'subpages' => [
                [
                    'location' => 'system',
                    'index'    => 150,
                    'label'    => __trans('Server Option Fields'),
                    'uri'      => $this->di['url']->adminLink('serviceserver'),
                    'class'    => '',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/serviceserver', 'get_index', null, static::class);
        $app->get('/serviceserver/category/:id', 'get_category', ['id' => '[0-9]+'], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];

        $service   = $this->di['mod_service']('Serviceserver');
        $modConfig = $this->di['mod_config']('Serviceserver');
        $pairs     = $service->getServerProductCategoryPairs();

        $categories = [];
        foreach ($pairs as $id => $title) {
            $categories[] = [
                'id'         => $id,
                'title'      => $title,
                'has_custom' => !empty($modConfig['option_fields_cat_' . $id]),
            ];
        }

        return $app->render('mod_serviceserver_index', ['categories' => $categories]);
    }

    public function get_category(\Box_App $app, $id): string
    {
        $this->di['is_admin_logged'];

        $categoryId = (int) $id;
        $service    = $this->di['mod_service']('Serviceserver');

        if (!isset($service->getServerProductCategoryPairs()[$categoryId])) {
            throw new \FOSSBilling\InformationException(
                'Category :id has no server-type products',
                [':id' => $categoryId]
            );
        }

        $api           = $this->di['api_admin'];
        $category      = $api->product_category_get(['id' => $categoryId]);
        $option_fields = $api->serviceserver_category_options_get(['category_id' => $categoryId]);

        return $app->render('mod_serviceserver_category', [
            'category'      => $category,
            'option_fields' => $option_fields,
        ]);
    }
}
