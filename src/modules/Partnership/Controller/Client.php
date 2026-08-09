<?php

declare(strict_types=1);

/**
 * Partnership Pricing — Client Controller.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Partnership\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

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
        $app->get('/partnership-program', 'get_index', [], static::class);
        $app->get('/partnership-program/', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string|RedirectResponse|Response
    {
        if (!$this->di['auth']->isClientLoggedIn()) {
            return $app->redirect($this->di['url']->link('login'));
        }

        $this->di['is_client_logged'];

        $db = $this->di['db'];

        // Show the apply page for clients in the Default group (or no group).
        $isDefaultGroup = true;
        $group = null;

        try {
            $client = $this->di['loggedin_client'];
            if ($client->client_group_id) {
                $group = $db->load('ClientGroup', (int) $client->client_group_id);
                if ($group && strtolower((string) $group->title) !== 'default') {
                    $isDefaultGroup = false;
                }
            }
        } catch (\Exception $e) {
            $this->di['logger']->warning('Partnership: failed to load client group: ' . $e->getMessage());
        }

        if ($isDefaultGroup) {
            $helpdesk = $db->findOne('SupportHelpdesk', '1 ORDER BY id ASC');

            return $app->render('mod_partnership_apply', [
                'helpdesk_id' => $helpdesk !== null ? (int) $helpdesk->id : 0,
                'has_helpdesk' => $helpdesk !== null,
            ]);
        }

        $pricing = $this->di['mod_service']('partnership')->getPricingForLoggedInClient();
        if (empty($pricing['product_prices']) && empty($pricing['tld_prices'])) {
            return $app->show404(new \FOSSBilling\InformationException('Page not found', null, 404));
        }

        $api = $this->di['api_guest'];

        // Pre-fetch unique products via the guest API (includes pricing array).
        $productIds = [];
        foreach ($pricing['product_prices'] as $row) {
            $productIds[(int) $row['product_id']] = true;
        }

        $productLookup = [];
        foreach (array_keys($productIds) as $productId) {
            try {
                $productLookup[$productId] = $api->product_get(['id' => $productId]);
            } catch (\Exception) {
                continue;
            }
        }

        $periodOrder = ['1W' => 10, '1M' => 20, '3M' => 30, '6M' => 40, '1Y' => 50, '2Y' => 60, '3Y' => 70];
        $products = [];

        foreach ($pricing['product_prices'] as $row) {
            $productId = (int) $row['product_id'];
            $period = $row['period'] !== null ? (string) $row['period'] : null;

            if (!isset($products[$productId])) {
                $product = $productLookup[$productId] ?? [];
                $products[$productId] = [
                    'id' => $productId,
                    'title' => $product['title'] ?? ('Product #' . $productId),
                    'type' => $product['type'] ?? '',
                    'rows' => [],
                ];
            }

            $products[$productId]['rows'][] = [
                'period' => $period,
                'period_label' => $period !== null ? ($periodOrder[$period] ?? 999) : 1000,
                'price' => (float) $row['price'],
                'regular_price' => null,
                'discount_percent' => null,
            ];
        }

        // Enrich rows with regular price and discount from the guest API pricing array.
        foreach ($products as $productId => &$product) {
            $apiProduct = $productLookup[$productId] ?? [];
            $pricingData = $apiProduct['pricing'] ?? [];

            foreach ($product['rows'] as &$pricingRow) {
                try {
                    $regularPrice = $this->extractRegularPrice($pricingData, $pricingRow['period']);
                    if ($regularPrice !== null) {
                        $partnerPrice = $pricingRow['price'];
                        $pricingRow['regular_price'] = $regularPrice;
                        if ($regularPrice > 0.0) {
                            $pricingRow['discount_percent'] = round((($regularPrice - $partnerPrice) / $regularPrice) * 100, 2);
                        }
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
            unset($pricingRow);

            usort($product['rows'], static fn (array $l, array $r): int => $l['period_label'] <=> $r['period_label']);
        }
        unset($product);

        $tldPrices = $pricing['tld_prices'];
        usort($tldPrices, static fn (array $l, array $r): int => strcmp((string) $l['tld'], (string) $r['tld']));

        $groupTitle = $group ? (string) $group->title : 'Partner';

        return $app->render('mod_partnership_program', [
            'products' => array_values($products),
            'tld_prices' => $tldPrices,
            'group_title' => $groupTitle,
        ]);
    }

    /**
     * Extract the regular price for a given period from the product's pricing array.
     * Pricing array structure (from guest product_get):
     *   recurrent: ['type' => 'recurrent', 'recurrent' => ['1M' => ['price' => ..., 'enabled' => true], ...]]
     *   once:      ['type' => 'once', 'once' => ['price' => ...]]
     *   free:      ['type' => 'free'].
     */
    private function extractRegularPrice(array $pricing, ?string $period): ?float
    {
        $type = $pricing['type'] ?? null;

        if ($type === 'recurrent') {
            if ($period === null) {
                // No period specified — return the first enabled period's price.
                foreach ($pricing['recurrent'] ?? [] as $periodData) {
                    if (!empty($periodData['enabled'])) {
                        return (float) $periodData['price'];
                    }
                }

                return null;
            }

            $periodData = $pricing['recurrent'][$period] ?? null;
            if ($periodData !== null) {
                return (float) $periodData['price'];
            }

            return null;
        }

        if ($type === 'once') {
            return (float) ($pricing['once']['price'] ?? 0.0);
        }

        if ($type === 'free') {
            return 0.0;
        }

        return null;
    }
}
