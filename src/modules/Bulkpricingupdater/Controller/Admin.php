<?php

declare(strict_types=1);

namespace Box\Mod\Bulkpricingupdater\Controller;

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
                    'index' => 520,
                    'label' => __trans('Bulk Pricing Updater'),
                    'uri' => $this->di['url']->adminLink('extension/settings/bulkpricingupdater'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/bulkpricingupdater', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_bulkpricingupdater_settings');
    }
}
