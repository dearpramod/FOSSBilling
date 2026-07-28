<?php

declare(strict_types=1);

/**
 * FOSSBilling Server Product Type Module
 * SPDX-License-Identifier: Apache-2.0.
 */

namespace Box\Mod\Serviceserver;

use Box\Mod\Product\Entity\Product;
use FOSSBilling\InjectionAwareInterface;
use RedBeanPHP\OODBBean;

class Service implements InjectionAwareInterface
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

    public function getModulePermissions(): array
    {
        return [
            'can_always_access' => true,
            'manage_settings'   => [],
        ];
    }

    public function install(): bool
    {
        $sql = "
        CREATE TABLE IF NOT EXISTS `service_server` (
            `id`            int(11) unsigned     NOT NULL AUTO_INCREMENT,
            `client_id`     int(11) unsigned     NOT NULL,
            `order_id`      int(11) unsigned     NOT NULL,
            `hostname`      varchar(255)         DEFAULT NULL,
            `ip`            varchar(45)          DEFAULT NULL,
            `root_password` varchar(255)         DEFAULT NULL,
            `os`            varchar(100)         DEFAULT NULL,
            `cpu_cores`     smallint(6)          DEFAULT NULL,
            `ram_mb`        int(11)              DEFAULT NULL,
            `disk_gb`       int(11)              DEFAULT NULL,
            `bandwidth_gb`  int(11)              DEFAULT NULL,
            `location`      varchar(100)         DEFAULT NULL,
            `notes`         text                 DEFAULT NULL,
            `status`        varchar(50)          NOT NULL DEFAULT 'pending',
            `created_at`    datetime             DEFAULT NULL,
            `updated_at`    datetime             DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_order_id`  (`order_id`),
            KEY `idx_client_id` (`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $this->di['db']->exec($sql);

        return true;
    }

    public function uninstall(): bool
    {
        $this->di['db']->exec('DROP TABLE IF EXISTS `service_server`');

        return true;
    }

    public function attachOrderConfig(Product $product, array $data): array
    {
        $config = json_decode($product->config ?? '', true) ?? [];

        return array_merge($config, $data);
    }

    public function validateCustomForm(array &$data, array $product): void
    {
        if (empty($product['form_id'])) {
            return;
        }

        // Maps lowercase field labels to canonical data keys so FormBuilder
        // required checks pass when native server config fields are present.
        $nativeLabelToKey = [
            'hostname' => ['hostname', 'server_hostname'],
            'host name' => ['hostname', 'server_hostname'],
            'os' => ['os', 'server_os'],
            'operating system' => ['os', 'server_os'],
            'server os' => ['os', 'server_os'],
            'notes' => ['notes', 'server_notes'],
            'server notes' => ['notes', 'server_notes'],
        ];

        $nativeAliases = [
            ['hostname', 'server_hostname'],
            ['os', 'server_os'],
            ['notes', 'server_notes'],
        ];
        foreach ($nativeAliases as [$canonical, $legacy]) {
            if (!empty($data[$canonical]) && empty($data[$legacy])) {
                $data[$legacy] = $data[$canonical];
            } elseif (!empty($data[$legacy]) && empty($data[$canonical])) {
                $data[$canonical] = $data[$legacy];
            }
        }

        $formbuilderService = $this->di['mod_service']('formbuilder');
        $form = $formbuilderService->getForm($product['form_id']);

        foreach ($form['fields'] as $field) {
            if ($field['required'] === 1 || $field['required'] === '1') {
                $field_name = $field['name'];
                $labelLower = strtolower(trim((string) $field['label']));
                $satisfied = isset($data[$field_name]) && $data[$field_name] !== '';

                if (!$satisfied && isset($nativeLabelToKey[$labelLower])) {
                    foreach ($nativeLabelToKey[$labelLower] as $nativeKey) {
                        if (!empty($data[$nativeKey])) {
                            $satisfied = true;

                            break;
                        }
                    }
                }

                if (!$satisfied) {
                    throw new \FOSSBilling\InformationException('You must fill in all required fields. :field is missing', [':field' => $field['label']], 9684);
                }
            }

            if ($field['readonly'] === 1 || $field['readonly'] === '1') {
                $field_name = $field['name'];
                if (isset($data[$field_name]) && $data[$field_name] != $field['default_value']) {
                    throw new \FOSSBilling\InformationException('Field :field is read only. You cannot change its value', [':field' => $field['label']], 5468);
                }
            }
        }
    }

    // Order lifecycle — bare method names (non-core service module convention).

    public function create(OODBBean $order): OODBBean
    {
        $config = json_decode($order->config ?? '{}', true) ?? [];

        $model = $this->di['db']->dispense('service_server');
        $model->client_id = $order->client_id;
        $model->order_id = $order->id;
        $model->hostname = $config['hostname'] ?? null;
        $model->ip = $config['ip'] ?? null;
        $model->root_password = $config['root_password'] ?? null;
        $model->os = $config['os'] ?? null;
        $model->cpu_cores = $config['cpu_cores'] ?? null;
        $model->ram_mb = $config['ram_mb'] ?? null;
        $model->disk_gb = $config['disk_gb'] ?? null;
        $model->bandwidth_gb = $config['bandwidth_gb'] ?? null;
        $model->location = $config['location'] ?? null;
        $model->notes = $config['notes'] ?? null;
        $model->status = 'pending';
        $model->created_at = date('Y-m-d H:i:s');
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return $model;
    }

    public function activate(OODBBean $order, OODBBean $model): bool
    {
        $model->status = 'active';
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }

    public function renew(OODBBean $order, OODBBean $model): bool
    {
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }

    public function suspend(OODBBean $order, OODBBean $model): bool
    {
        $model->status = 'suspended';
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }

    public function unsuspend(OODBBean $order, OODBBean $model): bool
    {
        $model->status = 'active';
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }

    public function cancel(OODBBean $order, OODBBean $model): bool
    {
        $model->status = 'cancelled';
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }

    public function uncancel(OODBBean $order, OODBBean $model): bool
    {
        $model->status = 'active';
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }

    public function delete(?OODBBean $order, ?OODBBean $model): void
    {
        if ($model instanceof OODBBean) {
            $this->di['db']->trash($model);
        }
    }

    public function toApiArray(OODBBean $model, bool $isAdmin = false): array
    {
        $data = [
            'id' => $model->id,
            'order_id' => $model->order_id,
            'client_id' => $model->client_id,
            'hostname' => $model->hostname,
            'ip' => $model->ip,
            'os' => $model->os,
            'cpu_cores' => $model->cpu_cores,
            'ram_mb' => $model->ram_mb,
            'disk_gb' => $model->disk_gb,
            'bandwidth_gb' => $model->bandwidth_gb,
            'location' => $model->location,
            'notes' => $model->notes,
            'status' => $model->status,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
        ];

        if ($isAdmin) {
            $data['root_password'] = $model->root_password;
        }

        return $data;
    }

    public function getDefaultOptions(): array
    {
        return [
            [
                'id'          => 'location',
                'label'       => 'Server Location',
                'description' => 'Choose a datacenter closest to you or your audience for optimal performance.',
                'required'    => false,
                'options'     => [
                    ['value' => 'india',       'label' => 'India',          'group' => 'Asia',          'badge' => 'Best',    'meta' => '39ms'],
                    ['value' => 'singapore',   'label' => 'Singapore',      'group' => 'Asia',          'badge' => 'Good',    'meta' => '52ms'],
                    ['value' => 'malaysia',    'label' => 'Malaysia',       'group' => 'Asia',          'badge' => 'Good',    'meta' => '87ms'],
                    ['value' => 'indonesia',   'label' => 'Indonesia',      'group' => 'Asia',          'badge' => 'Good',    'meta' => '97ms'],
                    ['value' => 'japan',       'label' => 'Japan',          'group' => 'Asia',          'badge' => 'Fair',    'meta' => '132ms'],
                    ['value' => 'south-korea', 'label' => 'South Korea',    'group' => 'Asia',          'badge' => 'Fair',    'meta' => '118ms'],
                    ['value' => 'netherlands', 'label' => 'Netherlands',    'group' => 'Europe',        'badge' => 'Fair',    'meta' => '164ms'],
                    ['value' => 'germany',     'label' => 'Germany',        'group' => 'Europe',        'badge' => 'Fair',    'meta' => '172ms'],
                    ['value' => 'uk',          'label' => 'United Kingdom', 'group' => 'Europe',        'badge' => 'Fair',    'meta' => '188ms'],
                    ['value' => 'us-west',     'label' => 'USA (West)',     'group' => 'North America',  'badge' => 'Distant', 'meta' => '218ms'],
                    ['value' => 'us-east',     'label' => 'USA (East)',     'group' => 'North America',  'badge' => 'Distant', 'meta' => '235ms'],
                ],
            ],
            [
                'id'          => 'os',
                'label'       => 'Operating System',
                'description' => 'Choose an OS, control panel, or application. You can change this later from the VPS dashboard.',
                'required'    => false,
                'display'     => 'card-grid',
                'options'     => [
                    ['value' => 'ubuntu-22',    'label' => 'Ubuntu 22.04 LTS', 'group' => 'Plain OS',      'badge' => '', 'meta' => 'LTS'],
                    ['value' => 'ubuntu-20',    'label' => 'Ubuntu 20.04 LTS', 'group' => 'Plain OS',      'badge' => '', 'meta' => 'LTS'],
                    ['value' => 'debian-12',    'label' => 'Debian 12',        'group' => 'Plain OS',      'badge' => '', 'meta' => ''],
                    ['value' => 'debian-11',    'label' => 'Debian 11',        'group' => 'Plain OS',      'badge' => '', 'meta' => ''],
                    ['value' => 'almalinux-9',  'label' => 'AlmaLinux 9',      'group' => 'Plain OS',      'badge' => '', 'meta' => ''],
                    ['value' => 'almalinux-8',  'label' => 'AlmaLinux 8',      'group' => 'Plain OS',      'badge' => '', 'meta' => ''],
                    ['value' => 'rocky-9',      'label' => 'Rocky Linux 9',    'group' => 'Plain OS',      'badge' => '', 'meta' => ''],
                    ['value' => 'rocky-8',      'label' => 'Rocky Linux 8',    'group' => 'Plain OS',      'badge' => '', 'meta' => ''],
                    ['value' => 'centos-7',     'label' => 'CentOS 7',         'group' => 'Plain OS',      'badge' => '', 'meta' => ''],
                    ['value' => 'alpine',       'label' => 'Alpine Linux',     'group' => 'Plain OS',      'badge' => '', 'meta' => ''],
                    ['value' => 'arch',         'label' => 'Arch Linux',       'group' => 'Plain OS',      'badge' => '', 'meta' => ''],
                    ['value' => 'fedora-39',    'label' => 'Fedora 39',        'group' => 'Plain OS',      'badge' => '', 'meta' => ''],
                    ['value' => 'cloudlinux-8', 'label' => 'CloudLinux 8',     'group' => 'Plain OS',      'badge' => '', 'meta' => ''],
                    ['value' => 'kali',         'label' => 'Kali Linux',       'group' => 'Plain OS',      'badge' => '', 'meta' => ''],
                    ['value' => 'nixos',        'label' => 'NixOS',            'group' => 'Plain OS',      'badge' => '', 'meta' => ''],
                    ['value' => 'opensuse',     'label' => 'openSUSE',         'group' => 'Plain OS',      'badge' => '', 'meta' => ''],
                    ['value' => 'cpanel',       'label' => 'cPanel / WHM',     'group' => 'Control Panel', 'badge' => '', 'meta' => 'License req.', 'price_monthly' => 15],
                    ['value' => 'plesk',        'label' => 'Plesk',            'group' => 'Control Panel', 'badge' => '', 'meta' => 'License req.', 'price_monthly' => 10],
                    ['value' => 'directadmin',  'label' => 'DirectAdmin',      'group' => 'Control Panel', 'badge' => '', 'meta' => '', 'price_monthly' => 5],
                    ['value' => 'cyberpanel',   'label' => 'CyberPanel',       'group' => 'Control Panel', 'badge' => '', 'meta' => 'Free'],
                    ['value' => 'hestiacp',     'label' => 'HestiaCP',         'group' => 'Control Panel', 'badge' => '', 'meta' => 'Free'],
                    ['value' => 'aapanel',      'label' => 'aaPanel',          'group' => 'Control Panel', 'badge' => '', 'meta' => 'Free'],
                    ['value' => 'wordpress',    'label' => 'WordPress',        'group' => 'Applications',  'badge' => '', 'meta' => ''],
                    ['value' => 'lamp',         'label' => 'LAMP Stack',       'group' => 'Applications',  'badge' => '', 'meta' => ''],
                    ['value' => 'lemp',         'label' => 'LEMP Stack',       'group' => 'Applications',  'badge' => '', 'meta' => ''],
                ],
            ],
        ];
    }

    public function getDefaultLocations(): array
    {
        return [
            ['region' => 'Asia', 'options' => [
                ['id' => 'india',       'label' => 'India',       'latency' => 39,  'quality' => 'Best'],
                ['id' => 'singapore',   'label' => 'Singapore',   'latency' => 52,  'quality' => 'Good'],
                ['id' => 'malaysia',    'label' => 'Malaysia',    'latency' => 87,  'quality' => 'Good'],
                ['id' => 'indonesia',   'label' => 'Indonesia',   'latency' => 97,  'quality' => 'Good'],
                ['id' => 'japan',       'label' => 'Japan',       'latency' => 132, 'quality' => 'Fair'],
                ['id' => 'south-korea', 'label' => 'South Korea', 'latency' => 118, 'quality' => 'Fair'],
            ]],
            ['region' => 'Europe', 'options' => [
                ['id' => 'netherlands', 'label' => 'Netherlands',    'latency' => 164, 'quality' => 'Fair'],
                ['id' => 'germany',     'label' => 'Germany',        'latency' => 172, 'quality' => 'Fair'],
                ['id' => 'uk',          'label' => 'United Kingdom', 'latency' => 188, 'quality' => 'Fair'],
            ]],
            ['region' => 'North America', 'options' => [
                ['id' => 'us-west', 'label' => 'USA (West)', 'latency' => 218, 'quality' => 'Distant'],
                ['id' => 'us-east', 'label' => 'USA (East)', 'latency' => 235, 'quality' => 'Distant'],
            ]],
        ];
    }

    public function getServiceByOrderId(int $orderId): ?OODBBean
    {
        return $this->di['db']->findOne('service_server', 'order_id = ?', [$orderId]) ?: null;
    }

    public function update(OODBBean $model, array $data): bool
    {
        $fields = ['hostname', 'ip', 'root_password', 'os', 'cpu_cores', 'ram_mb', 'disk_gb', 'bandwidth_gb', 'location', 'notes', 'status'];
        $intFields = ['cpu_cores', 'ram_mb', 'disk_gb', 'bandwidth_gb'];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                // Convert empty string to null for int fields to prevent MySQL storing 0.
                if (in_array($field, $intFields, true) && $value === '') {
                    $value = null;
                }
                $model->$field = $value;
            }
        }
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }
}
