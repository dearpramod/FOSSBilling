<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use function Tests\Helpers\container;
use function Tests\Helpers\moduleService;

/*
 * Regression: the guest cart built before a Google OAuth sign-in was being lost.
 *
 * The Cart module's onAfterClientLogin listener calls getSessionCart(), which
 * auto-creates (and persists) an empty cart under the current session when none
 * exists. If that listener fires BEFORE the guest cart is transferred to the
 * authenticated session, an empty cart is created under the new session and then
 * shadows the real (transferred) cart — the cart appears wiped.
 *
 * Therefore transferFromOtherSession() must run BEFORE onAfterClientLogin fires.
 */
test('complete_login transfers the guest cart before firing onAfterClientLogin', function (): void {
    $guest = apiEndpoint(new Box\Mod\Sociallogin\Api\Guest());
    $guest->setIp('127.0.0.1');

    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 5;
    $client->status = Model_Client::ACTIVE;
    $client->email = 'buyer@example.com';

    // Records the order in which the two operations we care about happen.
    $order = [];

    $serviceMock = Mockery::mock(Box\Mod\Sociallogin\Service::class);
    $serviceMock->shouldReceive('consumePendingLoginToken')->once()->andReturn(5);

    $dbMock = Mockery::mock('\Box_Database');
    $dbMock->shouldReceive('load')->with('Client', 5)->andReturn($client);

    $eventMock = Mockery::mock('\Box_EventManager');
    $eventMock->shouldReceive('fire')->andReturnUsing(function ($params) use (&$order) {
        if (($params['event'] ?? '') === 'onAfterClientLogin') {
            $order[] = 'onAfterClientLogin';
        }

        return null;
    });

    $sessionMock = Mockery::mock(FOSSBilling\Session::class);
    $sessionMock->shouldReceive('set')->andReturnNull();
    $sessionMock->shouldReceive('regenerateId')->andReturnNull();

    $cartServiceMock = Mockery::mock(Box\Mod\Cart\Service::class);
    $cartServiceMock->shouldReceive('transferFromOtherSession')->once()
        ->andReturnUsing(function () use (&$order) {
            $order[] = 'transferFromOtherSession';

            return true;
        });

    $di = container();
    $di['events_manager'] = $eventMock;
    $di['session'] = $sessionMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService(['cart' => $cartServiceMock]));

    $guest->setDi($di);
    $guest->setService($serviceMock);

    $result = $guest->complete_login([
        'token' => 'login-token',
        'old_session_id' => 'guestsession123',
    ]);

    expect($result)->toBeTrue();
    expect($order)->toBe(['transferFromOtherSession', 'onAfterClientLogin']);
});
