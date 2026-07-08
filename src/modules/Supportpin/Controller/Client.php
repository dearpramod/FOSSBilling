<?php

declare(strict_types=1);

/**
 * Support PIN — Client Controller.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Supportpin\Controller;

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
        $app->get('/supportpin', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_client_logged'];

        return $app->render('mod_supportpin_index');
    }
}
