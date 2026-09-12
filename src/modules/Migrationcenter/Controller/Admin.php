<?php

declare(strict_types=1);

/**
 * Migration Center — Admin Controller.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Controller;

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
                    'location' => 'support',
                    'index' => 800,
                    'label' => __trans('Migration Center'),
                    'uri' => $this->di['url']->adminLink('migrationcenter'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/migrationcenter', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_migrationcenter_index');
    }
}
