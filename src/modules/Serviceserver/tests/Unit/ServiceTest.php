<?php

declare(strict_types=1);

use Box\Mod\Product\Entity\Product;
use Box\Mod\Serviceserver\Service;

test('server selections are normalized into the native order configuration', function (): void {
    $product = new Product();
    $product->setConfig(json_encode([
        'cpu_cores' => 2,
        'ram_mb' => 2048,
    ]));

    $service = new Service();
    $result = $service->attachOrderConfig($product, [
        'period' => '1M',
        'config' => [
            'location' => 'india',
            'os' => 'ubuntu-20',
        ],
    ]);

    expect($result)->toMatchArray([
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'period' => '1M',
        'location' => 'india',
        'os' => 'ubuntu-20',
    ]);
    expect($result)->not->toHaveKey('config');
});

test('client-submitted spec fields cannot override admin product configuration', function (): void {
    $product = new Product();
    $product->setConfig(json_encode([
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 50,
        'bandwidth_gb' => 1000,
    ]));

    $service = new Service();

    // A crafted cart/add_item payload attempts to inflate the hardware spec and
    // assign admin-only provisioning fields via both the namespaced config object
    // and top-level keys.
    $result = $service->attachOrderConfig($product, [
        'period' => '1M',
        'cpu_cores' => 64,
        'ram_mb' => 131072,
        'ip' => '10.0.0.1',
        'root_password' => 'pwned',
        'status' => 'active',
        'config' => [
            'disk_gb' => 99999,
            'bandwidth_gb' => 99999,
            'location' => 'india',
        ],
    ]);

    // Admin-owned fields must retain the product configuration values.
    expect($result)->toMatchArray([
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 50,
        'bandwidth_gb' => 1000,
        'period' => '1M',
        'location' => 'india',
    ]);
    // Provisioning-only fields must not be carried over from user input.
    expect($result)->not->toHaveKey('ip');
    expect($result)->not->toHaveKey('root_password');
    expect($result)->not->toHaveKey('status');
});
