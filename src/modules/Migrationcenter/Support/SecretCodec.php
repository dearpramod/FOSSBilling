<?php

declare(strict_types=1);

/**
 * Migration Center — encrypts and decrypts the previous-host secret blob.
 *
 * The password and SSH key are bundled into one JSON document before
 * encryption so another secret field can be added later without a schema
 * change. Encryption is delegated to the core Box_Crypt service, the same
 * one the Extension and Email modules use for their stored secrets.
 *
 * Failures deliberately surface a generic message: the underlying reason
 * must never reach a client or appear in an exception shown in the UI.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Support;

final class SecretCodec
{
    /**
     * @param string|null $passphrase overrides the configured salt; production passes null
     */
    public function __construct(
        private readonly \Box_Crypt $crypt,
        private readonly ?string $passphrase = null,
    ) {
    }

    /**
     * @param array{password?: string, ssh_key?: string} $secret
     */
    public function encode(array $secret): string
    {
        $payload = [
            'password' => (string) ($secret['password'] ?? ''),
            'ssh_key' => (string) ($secret['ssh_key'] ?? ''),
        ];

        return $this->crypt->encrypt(json_encode($payload, JSON_THROW_ON_ERROR), $this->passphrase);
    }

    /**
     * @return array{password: string, ssh_key: string}
     *
     * @throws \FOSSBilling\Exception when the stored value cannot be decrypted or parsed
     */
    public function decode(string $encrypted): array
    {
        if ($encrypted === '') {
            return ['password' => '', 'ssh_key' => ''];
        }

        $json = $this->crypt->decrypt($encrypted, $this->passphrase);
        if (!is_string($json)) {
            throw new \FOSSBilling\Exception('Stored migration credentials could not be read.');
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \FOSSBilling\Exception('Stored migration credentials could not be read.');
        }

        return [
            'password' => (string) ($data['password'] ?? ''),
            'ssh_key' => (string) ($data['ssh_key'] ?? ''),
        ];
    }
}
