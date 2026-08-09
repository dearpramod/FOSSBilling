<?php

declare(strict_types=1);

/**
 * Chat Widget — Guest API.
 *
 * The guest API is always available, so the widget can read its config on both
 * public and authenticated client pages. Read-only; no mutation, no rate limit.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Chat\Api;

class Guest extends \FOSSBilling\Api\AbstractApi
{
    /**
     * The minimal public channel configuration consumed by the client widget.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->getService()->getPublicConfig();
    }
}
