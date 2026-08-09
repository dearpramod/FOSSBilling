<?php

declare(strict_types=1);

/**
 * Chat Widget — Admin controller.
 *
 * Adds the settings page under Extensions. Settings persist through the core
 * extension/config_save endpoint (ext=mod_chat); this controller only renders
 * the form.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Chat\Controller;

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
                    'label' => __trans('Chat widget'),
                    'index' => 2100,
                    'uri' => $this->di['url']->adminLink('chat'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/chat', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_chat_settings');
    }
}
