<?php

declare(strict_types=1);

/**
 * WhatsApp Notifications — Admin Controller.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Whatsapp\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;

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
                    'index' => 500,
                    'label' => __trans('WhatsApp Notifications'),
                    'uri' => $this->di['url']->adminLink('extension/settings/whatsapp'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        // After activation the admin JS redirects to /admin/whatsapp — forward to the settings page.
        $app->get('/whatsapp', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): RedirectResponse
    {
        $this->di['is_admin_logged'];

        return $app->redirect('extension/settings/whatsapp');
    }
}
