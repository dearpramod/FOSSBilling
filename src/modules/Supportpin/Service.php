<?php

declare(strict_types=1);

/**
 * Support PIN — Core service.
 *
 * Generates and manages globally unique 6-digit PINs stored in extension_meta.
 * No core schema changes required.
 *
 * Meta keys used (extension = 'mod_supportpin', rel_type = 'client'):
 *   support_pin              — the PIN value
 *   support_pin_fails        — consecutive failed verify attempts
 *   support_pin_locked_until — ISO datetime or empty
 *   support_pin_changed_at   — ISO datetime of last regeneration
 *   support_pin_regen_today  — JSON {"date":"Y-m-d","count":N}
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Supportpin;

use FOSSBilling\InjectionAwareInterface;

class Service implements InjectionAwareInterface
{
    private const string EXT = 'mod_supportpin';

    private const string REL_TYPE = 'client';

    private const int MAX_FAILS = 5;

    private const int LOCK_MINS = 30;

    private const int MAX_REGEN = 2;

    private const int MAX_RETRIES = 10;

    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function install(): bool
    {
        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    // ── Hooks ────────────────────────────────────────────────────────────

    public static function onAfterClientSignUp(\Box_Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        $id = (int) ($params['id'] ?? 0);
        if ($id > 0) {
            /** @var self $svc */
            $svc = $di['mod_service']('supportpin');
            $svc->generatePin($id);
        }
    }

    public static function onAfterAdminClientCreate(\Box_Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();
        $id = (int) ($params['id'] ?? 0);
        if ($id > 0) {
            /** @var self $svc */
            $svc = $di['mod_service']('supportpin');
            $svc->generatePin($id);
        }
    }

    // ── Public API ───────────────────────────────────────────────────────

    /**
     * Return the existing PIN for a client, or generate one if missing.
     */
    public function getPinForClient(int $clientId): string
    {
        $row = $this->getMeta($clientId, 'support_pin');
        if ($row !== null && (string) $row->meta_value !== '') {
            return (string) $row->meta_value;
        }

        return $this->generatePin($clientId);
    }

    /**
     * Generate a globally unique 6-digit PIN for a client.
     * Overwrites any existing PIN.
     */
    public function generatePin(int $clientId): string
    {
        $pin = null;
        for ($i = 0; $i < self::MAX_RETRIES; ++$i) {
            $candidate = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            if (!$this->pinExists($candidate)) {
                $pin = $candidate;

                break;
            }
        }

        if ($pin === null) {
            throw new \FOSSBilling\InformationException('Could not generate a unique Support PIN. Please try again.');
        }

        $now = date('Y-m-d H:i:s');
        $this->upsertMeta($clientId, 'support_pin', $pin, $now);
        $this->upsertMeta($clientId, 'support_pin_changed_at', $now, $now);
        $this->upsertMeta($clientId, 'support_pin_fails', '0', $now);
        $this->upsertMeta($clientId, 'support_pin_locked_until', '', $now);

        return $pin;
    }

    /**
     * Regenerate PIN for a client (client-initiated).
     * Rate limited: max MAX_REGEN regenerations per 24 hours.
     */
    public function regeneratePin(int $clientId): string
    {
        $this->checkRegenLimit($clientId);

        $pin = $this->generatePin($clientId);
        $this->incrementRegenCount($clientId);

        $this->di['logger']->info(sprintf('Client %d regenerated their Support PIN.', $clientId));

        return $pin;
    }

    /**
     * Lookup client_id by PIN. Returns null if not found.
     * Does NOT increment fail counter — use verifyPin() for authentication.
     */
    public function findClientByPin(string $pin): ?int
    {
        $pin = trim($pin);
        if ($pin === '' || strlen($pin) !== 6) {
            return null;
        }

        $row = $this->di['db']->getRow(
            "SELECT client_id FROM extension_meta
             WHERE extension = ? AND meta_key = 'support_pin' AND meta_value = ?
             LIMIT 1",
            [self::EXT, $pin]
        );

        return isset($row['client_id']) ? (int) $row['client_id'] : null;
    }

    /**
     * Verify PIN + email combination for a client.
     * Increments fail counter on mismatch; locks after MAX_FAILS failures.
     */
    public function verifyPin(int $clientId, string $pin, string $email): bool
    {
        if ($this->isLocked($clientId)) {
            throw new \FOSSBilling\InformationException('This account is temporarily locked. Please try again later.');
        }

        $client = $this->di['db']->load('Client', $clientId);
        if (!$client instanceof \Model_Client) {
            return false;
        }

        $storedPin = $this->getPinForClient($clientId);
        $emailMatch = strtolower(trim($email)) === strtolower(trim((string) $client->email));
        $pinMatch = hash_equals($storedPin, trim($pin));

        if ($pinMatch && $emailMatch) {
            $now = date('Y-m-d H:i:s');
            $this->upsertMeta($clientId, 'support_pin_fails', '0', $now);
            $this->upsertMeta($clientId, 'support_pin_locked_until', '', $now);

            return true;
        }

        $this->incrementFails($clientId);

        return false;
    }

    /**
     * Whether this client's PIN is currently locked out.
     */
    public function isLocked(int $clientId): bool
    {
        $row = $this->getMeta($clientId, 'support_pin_locked_until');
        if ($row === null || (string) $row->meta_value === '') {
            return false;
        }

        return strtotime((string) $row->meta_value) > time();
    }

    /**
     * Reset fail counter and lockout (admin only).
     */
    public function resetLock(int $clientId): bool
    {
        $now = date('Y-m-d H:i:s');
        $this->upsertMeta($clientId, 'support_pin_fails', '0', $now);
        $this->upsertMeta($clientId, 'support_pin_locked_until', '', $now);

        return true;
    }

    /**
     * Return PIN data as an array for API responses.
     */
    public function toApiArray(int $clientId): array
    {
        $keys = ['support_pin', 'support_pin_changed_at', 'support_pin_fails', 'support_pin_locked_until'];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $rows = $this->di['db']->find(
            'ExtensionMeta',
            'extension = ? AND rel_type = ? AND client_id = ? AND meta_key IN (' . $placeholders . ')',
            array_merge([self::EXT, self::REL_TYPE, $clientId], $keys)
        );

        $meta = [];
        foreach ($rows as $row) {
            $meta[(string) $row->meta_key] = (string) $row->meta_value;
        }

        $lockedUntil = $meta['support_pin_locked_until'] ?? '';
        $isLocked = $lockedUntil !== '' && strtotime($lockedUntil) > time();

        return [
            'client_id' => $clientId,
            'pin' => $meta['support_pin'] ?? null,
            'changed_at' => $meta['support_pin_changed_at'] ?? null,
            'fails' => isset($meta['support_pin_fails']) ? (int) $meta['support_pin_fails'] : 0,
            'locked' => $isLocked,
            'locked_until' => $isLocked ? $lockedUntil : null,
        ];
    }

    // ── Private helpers ──────────────────────────────────────────────────

    private function pinExists(string $pin): bool
    {
        $count = (int) $this->di['db']->getCell(
            "SELECT COUNT(*) FROM extension_meta WHERE extension = ? AND meta_key = 'support_pin' AND meta_value = ?",
            [self::EXT, $pin]
        );

        return $count > 0;
    }

    private function getMeta(int $clientId, string $key): ?object
    {
        return $this->di['db']->findOne(
            'ExtensionMeta',
            'extension = ? AND rel_type = ? AND client_id = ? AND meta_key = ?',
            [self::EXT, self::REL_TYPE, $clientId, $key]
        );
    }

    private function upsertMeta(int $clientId, string $key, string $value, string $now): void
    {
        $row = $this->getMeta($clientId, $key);
        if ($row !== null) {
            $row->meta_value = $value;
            $row->updated_at = $now;
            $this->di['db']->store($row);
        } else {
            $meta = $this->di['db']->dispense('ExtensionMeta');
            $meta->extension = self::EXT;
            $meta->rel_type = self::REL_TYPE;
            $meta->client_id = $clientId;
            $meta->meta_key = $key;
            $meta->meta_value = $value;
            $meta->created_at = $now;
            $meta->updated_at = $now;
            $this->di['db']->store($meta);
        }
    }

    private function incrementFails(int $clientId): void
    {
        $now = date('Y-m-d H:i:s');

        $existing = $this->getMeta($clientId, 'support_pin_fails');
        if ($existing === null) {
            $this->upsertMeta($clientId, 'support_pin_fails', '0', $now);
        }

        // Atomic increment to prevent read-modify-write race under concurrent wrong-guess requests.
        $this->di['db']->exec(
            "UPDATE extension_meta
             SET meta_value = CAST(meta_value AS UNSIGNED) + 1, updated_at = ?
             WHERE extension = ? AND rel_type = ? AND client_id = ? AND meta_key = 'support_pin_fails'",
            [$now, self::EXT, self::REL_TYPE, $clientId]
        );

        $fails = (int) $this->di['db']->getCell(
            "SELECT meta_value FROM extension_meta
             WHERE extension = ? AND rel_type = ? AND client_id = ? AND meta_key = 'support_pin_fails'
             LIMIT 1",
            [self::EXT, self::REL_TYPE, $clientId]
        );

        if ($fails >= self::MAX_FAILS) {
            $lockUntil = date('Y-m-d H:i:s', time() + self::LOCK_MINS * 60);
            $this->upsertMeta($clientId, 'support_pin_locked_until', $lockUntil, $now);
        }
    }

    private function checkRegenLimit(int $clientId): void
    {
        $row = $this->getMeta($clientId, 'support_pin_regen_today');
        if ($row === null || (string) $row->meta_value === '') {
            return;
        }

        $data = json_decode((string) $row->meta_value, true);
        if (!is_array($data)) {
            return;
        }

        $windowStart = (int) ($data['window_start'] ?? 0);
        $count = (int) ($data['count'] ?? 0);

        if ($windowStart > 0 && (time() - $windowStart) < 86400 && $count >= self::MAX_REGEN) {
            throw new \FOSSBilling\InformationException('You can regenerate your Support PIN a maximum of :max times per 24 hours.', [':max' => self::MAX_REGEN]);
        }
    }

    private function incrementRegenCount(int $clientId): void
    {
        $now = date('Y-m-d H:i:s');
        $row = $this->getMeta($clientId, 'support_pin_regen_today');

        $windowStart = time();
        $count = 1;

        if ($row !== null && (string) $row->meta_value !== '') {
            $data = json_decode((string) $row->meta_value, true);
            if (is_array($data)) {
                $prevStart = (int) ($data['window_start'] ?? 0);
                if ($prevStart > 0 && (time() - $prevStart) < 86400) {
                    $windowStart = $prevStart;
                    $count = (int) ($data['count'] ?? 0) + 1;
                }
            }
        }

        $this->upsertMeta(
            $clientId,
            'support_pin_regen_today',
            (string) json_encode(['window_start' => $windowStart, 'count' => $count]),
            $now
        );
    }
}
