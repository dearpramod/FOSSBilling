<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling.
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace FOSSBilling\Twig\Extension;

use Twig\Attribute\AsTwigFilter;
use Twig\Environment;

class LegacyExtension
{
    public function __construct(private ?\Pimple\Container $di)
    {
    }

    #[AsTwigFilter('money', isSafe: ['html'], needsEnvironment: true)]
    public function money(Environment $env, mixed $price, ?string $currency = null): string
    {
        $globals = $env->getGlobals();

        return $globals['guest']->currency_format(['price' => $price, 'code' => $currency, 'convert' => false]);
    }

    #[AsTwigFilter('money_convert', isSafe: ['html'], needsEnvironment: true)]
    public function moneyConvert(Environment $env, mixed $price, ?string $currency = null): string
    {
        $globals = $env->getGlobals();
        $api_guest = $globals['guest'];
        if ($currency === null) {
            $c = $api_guest->cart_get_currency();
            $currency = $c['code'];
        }

        return $api_guest->currency_format(['price' => $price, 'code' => $currency, 'convert' => true]);
    }

    #[AsTwigFilter('money_without_currency', isSafe: ['html'], needsEnvironment: true)]
    public function moneyWithoutCurrency(Environment $env, mixed $price, ?string $currency = null): string
    {
        $globals = $env->getGlobals();

        return $globals['guest']->currency_format(['price' => $price, 'code' => $currency, 'convert' => false, 'without_currency' => true]);
    }

    #[AsTwigFilter('ip_country_name')]
    public function ipCountryName(?string $ip): string
    {
        if ($ip === null) {
            return '';
        }

        try {
            $record = $this->di['geoip']->country($ip);

            return $record->name;
        } catch (\Exception) {
            return '';
        }
    }

    #[AsTwigFilter('ip_country_code')]
    public function ipCountryCode(?string $ip): string
    {
        if ($ip === null) {
            return '';
        }

        try {
            $record = $this->di['geoip']->country($ip);

            return strtolower($record->isoCode ?? '');
        } catch (\Exception) {
            return '';
        }
    }

    #[AsTwigFilter('mod_asset_url')]
    public function modAssetUrl(?string $asset, ?string $module): string
    {
        if ($asset === null || $module === null) {
            return '';
        }

        return SYSTEM_URL . 'modules/' . ucfirst($module) . "/assets/{$asset}";
    }

    #[AsTwigFilter('period_title', isSafe: ['html'])]
    public function periodTitle(?string $period): string
    {
        if ($period === null) {
            return '';
        }

        return $this->di['api_guest']->system_period_title(['code' => $period]);
    }
}
