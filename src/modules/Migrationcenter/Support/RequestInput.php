<?php

declare(strict_types=1);

/**
 * Migration Center — validated client-submitted request payload.
 *
 * Validation lives here rather than in the service so it can be exercised
 * without a database or container. Nothing in this class logs or echoes the
 * secret values it carries.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Support;

final class RequestInput
{
    private function __construct(
        public readonly string $panelType,
        public readonly string $host,
        public readonly ?int $port,
        public readonly string $username,
        public readonly string $password,
        public readonly string $sshKey,
        public readonly ?string $clientNotes,
        public readonly ?int $clientOrderId,
    ) {
    }

    /**
     * @throws \FOSSBilling\InformationException when the payload is unusable
     */
    public static function fromClientData(array $data): self
    {
        $panelType = trim((string) ($data['panel_type'] ?? ''));
        if (!PanelType::isValid($panelType)) {
            throw new \FOSSBilling\InformationException('Please choose a valid control panel type.');
        }

        $host = trim((string) ($data['host'] ?? ''));
        if ($host === '') {
            throw new \FOSSBilling\InformationException('The previous host address is required.');
        }

        if (mb_strlen($host) > 255) {
            throw new \FOSSBilling\InformationException('The previous host address is too long.');
        }

        $username = trim((string) ($data['username'] ?? ''));
        if ($username === '') {
            throw new \FOSSBilling\InformationException('The username at your previous host is required.');
        }

        $port = self::parsePort($data['port'] ?? null);

        $password = (string) ($data['password'] ?? '');
        $sshKey = (string) ($data['ssh_key'] ?? '');
        if (trim($password) === '' && trim($sshKey) === '') {
            throw new \FOSSBilling\InformationException('Provide either a password or an SSH key for your previous host.');
        }

        $clientNotes = trim((string) ($data['client_notes'] ?? ''));
        $clientOrderId = self::parseClientOrderId($data['client_order_id'] ?? null);

        return new self(
            $panelType,
            $host,
            $port,
            $username,
            $password,
            $sshKey,
            $clientNotes === '' ? null : $clientNotes,
            $clientOrderId,
        );
    }

    private static function parsePort(mixed $raw): ?int
    {
        $value = trim((string) ($raw ?? ''));
        if ($value === '') {
            return null;
        }

        if (!ctype_digit($value)) {
            throw new \FOSSBilling\InformationException('The port must be a number between 1 and 65535.');
        }

        $port = (int) $value;
        if ($port < 1 || $port > 65535) {
            throw new \FOSSBilling\InformationException('The port must be a number between 1 and 65535.');
        }

        return $port;
    }

    private static function parseClientOrderId(mixed $raw): ?int
    {
        $value = trim((string) ($raw ?? ''));
        if ($value === '') {
            return null;
        }

        if (!ctype_digit($value)) {
            throw new \FOSSBilling\InformationException('The selected service is not valid.');
        }

        $orderId = (int) $value;
        if ($orderId === 0) {
            return null;
        }

        return $orderId;
    }
}
