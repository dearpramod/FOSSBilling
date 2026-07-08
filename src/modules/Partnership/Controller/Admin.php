<?php

declare(strict_types=1);

/**
 * Partnership Pricing — Admin Controller.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Partnership\Controller;

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
                    'location' => 'extensions',
                    'index' => 510,
                    'label' => __trans('Partnership Pricing'),
                    'uri' => $this->di['url']->adminLink('partnership'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/partnership', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_partnership_settings');
    }
}
