<?php

declare(strict_types=1);

/**
 * Social Login module for FOSSBilling — Admin Controller.
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Sociallogin\Controller;

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
                    'label' => __trans('Social Login'),
                    'index' => 2200,
                    'uri' => $this->di['url']->adminLink('sociallogin'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/sociallogin', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_sociallogin_settings');
    }
}
