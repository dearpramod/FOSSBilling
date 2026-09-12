# Migration Center Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a `migrationcenter` FOSSBilling module that lets an existing client submit their previous hosting provider's credentials to migration support staff, who work the request through a status workflow in a dedicated admin queue.

**Architecture:** A standard FOSSBilling extension module. Pure domain rules (status transitions, panel types, input validation, secret encoding) live in small, dependency-free classes under `Support/` so they are unit-testable without a database. Persistence is a Doctrine entity + repository over one module-owned table created by `Service::install()`. Credentials are encrypted at rest with the existing `Box_Crypt` service (`$di['crypt']`) and only ever leave the server through one permission-gated admin endpoint.

**Tech Stack:** PHP 8.3, Doctrine ORM/DBAL, Pimple DI, Twig, Pest (tests), Tailwind + Alpine.js (meroserver client theme), Tabler (admin_default theme).

**Spec:** `docs/superpowers/specs/2026-09-12-migrationcenter-design.md`

## Global Constraints

- Module id: `migrationcenter`. PHP namespace: `Box\Mod\Migrationcenter`. Directory: `src/modules/Migrationcenter/`.
- Table name: `mod_migrationcenter_request`.
- All work happens inside `FOSSBilling/`. Never write to `merovps/`.
- PSR-12. Every new PHP file starts with `<?php`, `declare(strict_types=1);`, a short docblock, and `SPDX-License-Identifier: Apache-2.0`.
- Avoid RedBeanPHP (`$di['db']`) in new code — use Doctrine (`$di['em']`), per `FOSSBilling/.claude/CLAUDE.md`.
- Id and foreign-key columns are **signed** `BIGINT` mapped to `?int` / `int` (`Types::BIGINT`), never `UNSIGNED`, per `FOSSBilling/.claude/CLAUDE.md`.
- Run unit tests from `FOSSBilling/`: `./src/vendor/bin/pest --test-directory ../tests --testsuite=Modules --filter="<name>"`
- Full suite: `composer test`. Formatting: `composer cs:fix`. Static analysis: `composer phpstan`.
- Local server: `localhost:9000`. DB host `127.0.0.1:3306`, db/user/pass all `merovps`. Test client `pramodyadav826@gmail.com` (client_id=2). Admin API token `3c8btj3fNL8Vu7V5ieXGEiZ7pbk6LMqJ` (HTTP Basic `--user "admin:<token>"`).
- After any Twig template change: `find FOSSBilling/src/data/cache -name "*.php" -delete`
- After meroserver template/CSS edits: `npm run build-meroserver` from `FOSSBilling/`.

## Deviations from the spec (deliberate, review these first)

1. **`BIGINT UNSIGNED` → signed `BIGINT`.** The spec's SQL used `UNSIGNED` for id/FK columns. The codebase convention (`FOSSBilling/.claude/CLAUDE.md`, matching `src/install/sql/structure.sql`) is signed `BIGINT` so Doctrine's `BigIntType` never widens to `string`. Signed is used throughout this plan.
2. **`port SMALLINT UNSIGNED` → `INT NULL`.** Avoids Doctrine unsigned-mapping friction; the 1–65535 range is enforced in `RequestInput`.
3. **Added `secret_purged_at DATETIME NULL`.** Without it the UI cannot distinguish "purged" from "never stored". Purely additive.
4. **The secret is returned by a dedicated `reveal_secret` admin endpoint, not by admin `get`.** The spec said one permission-gated endpoint returns the plaintext; `reveal_secret` *is* that endpoint. Splitting it keeps plaintext out of the detail page's Twig-injected payload, which a `view_migrations`-only staff member can render. Per the approved encryption decision (no forced click-to-reveal, no audit log), the detail page auto-calls it on load for staff who hold `manage_migrations` — the staff member sees the credentials immediately, exactly as if it were inlined.
5. **Client nav link is a guarded edit to `meroserver/html/layout_default.html.twig`, not a widget.** meroserver exposes widget slots for body/header/content/footer only — there is no nav slot. The established pattern in this exact file is the `guest.extension_is_on({'mod': '...'})` guard used by `supportpin` (line ~244). Task 8 follows it.

---

### Task 1: Domain vocabulary — statuses and panel types

**Files:**
- Create: `src/modules/Migrationcenter/Support/RequestStatus.php`
- Create: `src/modules/Migrationcenter/Support/PanelType.php`
- Test: `src/modules/Migrationcenter/tests/Unit/RequestStatusTest.php`
- Test: `src/modules/Migrationcenter/tests/Unit/PanelTypeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `RequestStatus::SUBMITTED|IN_PROGRESS|COMPLETED|FAILED|CANCELLED` (string constants), `RequestStatus::ALL` (string[]), `RequestStatus::isValid(string): bool`, `RequestStatus::canTransition(string $from, string $to): bool`, `RequestStatus::isPurgeable(string): bool`, `RequestStatus::isClientCancellable(string): bool`
  - `PanelType::CPANEL_WHM|PLESK|DIRECTADMIN|SSH_CUSTOM|OTHER`, `PanelType::ALL` (string[]), `PanelType::isValid(string): bool`, `PanelType::label(string): string`

- [ ] **Step 1: Write the failing tests**

Create `src/modules/Migrationcenter/tests/Unit/RequestStatusTest.php`:

```php
<?php

/**
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Box\Mod\Migrationcenter\Support\RequestStatus;

describe('isValid', function (): void {
    test('accepts every known status', function (): void {
        foreach (RequestStatus::ALL as $status) {
            expect(RequestStatus::isValid($status))->toBeTrue();
        }
    });

    test('rejects unknown statuses', function (): void {
        expect(RequestStatus::isValid('pending'))->toBeFalse()
            ->and(RequestStatus::isValid(''))->toBeFalse()
            ->and(RequestStatus::isValid('SUBMITTED'))->toBeFalse();
    });
});

describe('canTransition', function (): void {
    test('submitted can move to any working or terminal state', function (): void {
        expect(RequestStatus::canTransition(RequestStatus::SUBMITTED, RequestStatus::IN_PROGRESS))->toBeTrue()
            ->and(RequestStatus::canTransition(RequestStatus::SUBMITTED, RequestStatus::COMPLETED))->toBeTrue()
            ->and(RequestStatus::canTransition(RequestStatus::SUBMITTED, RequestStatus::FAILED))->toBeTrue()
            ->and(RequestStatus::canTransition(RequestStatus::SUBMITTED, RequestStatus::CANCELLED))->toBeTrue();
    });

    test('failed can be retried but completed and cancelled are terminal', function (): void {
        expect(RequestStatus::canTransition(RequestStatus::FAILED, RequestStatus::IN_PROGRESS))->toBeTrue()
            ->and(RequestStatus::canTransition(RequestStatus::COMPLETED, RequestStatus::IN_PROGRESS))->toBeFalse()
            ->and(RequestStatus::canTransition(RequestStatus::CANCELLED, RequestStatus::IN_PROGRESS))->toBeFalse();
    });

    test('a status never transitions to itself', function (): void {
        foreach (RequestStatus::ALL as $status) {
            expect(RequestStatus::canTransition($status, $status))->toBeFalse();
        }
    });

    test('unknown statuses never transition', function (): void {
        expect(RequestStatus::canTransition('bogus', RequestStatus::COMPLETED))->toBeFalse()
            ->and(RequestStatus::canTransition(RequestStatus::SUBMITTED, 'bogus'))->toBeFalse();
    });
});

describe('isPurgeable', function (): void {
    test('only finished requests may have their secret purged', function (): void {
        expect(RequestStatus::isPurgeable(RequestStatus::COMPLETED))->toBeTrue()
            ->and(RequestStatus::isPurgeable(RequestStatus::FAILED))->toBeTrue()
            ->and(RequestStatus::isPurgeable(RequestStatus::CANCELLED))->toBeTrue()
            ->and(RequestStatus::isPurgeable(RequestStatus::SUBMITTED))->toBeFalse()
            ->and(RequestStatus::isPurgeable(RequestStatus::IN_PROGRESS))->toBeFalse();
    });
});

describe('isClientCancellable', function (): void {
    test('a client may only cancel before staff pick the request up', function (): void {
        expect(RequestStatus::isClientCancellable(RequestStatus::SUBMITTED))->toBeTrue()
            ->and(RequestStatus::isClientCancellable(RequestStatus::IN_PROGRESS))->toBeFalse()
            ->and(RequestStatus::isClientCancellable(RequestStatus::COMPLETED))->toBeFalse();
    });
});
```

Create `src/modules/Migrationcenter/tests/Unit/PanelTypeTest.php`:

```php
<?php

/**
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Box\Mod\Migrationcenter\Support\PanelType;

describe('isValid', function (): void {
    test('accepts every known panel type', function (): void {
        foreach (PanelType::ALL as $type) {
            expect(PanelType::isValid($type))->toBeTrue();
        }
    });

    test('rejects unknown panel types', function (): void {
        expect(PanelType::isValid('whm'))->toBeFalse()
            ->and(PanelType::isValid(''))->toBeFalse();
    });
});

describe('label', function (): void {
    test('returns a human label for known types', function (): void {
        expect(PanelType::label(PanelType::CPANEL_WHM))->toBe('cPanel / WHM')
            ->and(PanelType::label(PanelType::SSH_CUSTOM))->toBe('SSH / Custom');
    });

    test('falls back to the raw value for unknown types', function (): void {
        expect(PanelType::label('mystery'))->toBe('mystery');
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run from `FOSSBilling/`:
```bash
./src/vendor/bin/pest --test-directory ../tests --testsuite=Modules --filter="isValid|canTransition|isPurgeable|isClientCancellable|label"
```
Expected: FAIL — `Class "Box\Mod\Migrationcenter\Support\RequestStatus" not found`.

- [ ] **Step 3: Write the implementations**

Create `src/modules/Migrationcenter/Support/RequestStatus.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration Center — request status vocabulary and transition rules.
 *
 * Pure domain logic: no DI, no database, no side effects.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Support;

final class RequestStatus
{
    public const string SUBMITTED = 'submitted';

    public const string IN_PROGRESS = 'in_progress';

    public const string COMPLETED = 'completed';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';

    public const array ALL = [
        self::SUBMITTED,
        self::IN_PROGRESS,
        self::COMPLETED,
        self::FAILED,
        self::CANCELLED,
    ];

    /**
     * Statuses in which the migration is finished for good and the stored
     * credentials are no longer needed, so staff may purge them.
     */
    private const array PURGEABLE = [self::COMPLETED, self::FAILED, self::CANCELLED];

    /**
     * Allowed staff-driven status moves. Completed and cancelled are terminal;
     * failed may be retried by moving back to in_progress.
     */
    private const array TRANSITIONS = [
        self::SUBMITTED => [self::IN_PROGRESS, self::COMPLETED, self::FAILED, self::CANCELLED],
        self::IN_PROGRESS => [self::COMPLETED, self::FAILED, self::CANCELLED],
        self::COMPLETED => [],
        self::FAILED => [self::IN_PROGRESS, self::CANCELLED],
        self::CANCELLED => [],
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        if (!self::isValid($from) || !self::isValid($to)) {
            return false;
        }

        return in_array($to, self::TRANSITIONS[$from], true);
    }

    public static function isPurgeable(string $status): bool
    {
        return in_array($status, self::PURGEABLE, true);
    }

    /**
     * A client may withdraw their own request only while no staff member has
     * started working on it.
     */
    public static function isClientCancellable(string $status): bool
    {
        return $status === self::SUBMITTED;
    }
}
```

Create `src/modules/Migrationcenter/Support/PanelType.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration Center — control panel vocabulary for the previous host.
 *
 * Pure domain logic: no DI, no database, no side effects.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Support;

final class PanelType
{
    public const string CPANEL_WHM = 'cpanel_whm';

    public const string PLESK = 'plesk';

    public const string DIRECTADMIN = 'directadmin';

    public const string SSH_CUSTOM = 'ssh_custom';

    public const string OTHER = 'other';

    public const array ALL = [
        self::CPANEL_WHM,
        self::PLESK,
        self::DIRECTADMIN,
        self::SSH_CUSTOM,
        self::OTHER,
    ];

    private const array LABELS = [
        self::CPANEL_WHM => 'cPanel / WHM',
        self::PLESK => 'Plesk',
        self::DIRECTADMIN => 'DirectAdmin',
        self::SSH_CUSTOM => 'SSH / Custom',
        self::OTHER => 'Other',
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run from `FOSSBilling/`:
```bash
./src/vendor/bin/pest --test-directory ../tests --testsuite=Modules --filter="isValid|canTransition|isPurgeable|isClientCancellable|label"
```
Expected: PASS (all tests green).

- [ ] **Step 5: Format and commit**

```bash
cd FOSSBilling
composer cs:fix -- src/modules/Migrationcenter
git add src/modules/Migrationcenter
git commit -m "feat(migrationcenter): add request status and panel type domain rules"
```

---

### Task 2: Client input validation

**Files:**
- Create: `src/modules/Migrationcenter/Support/RequestInput.php`
- Test: `src/modules/Migrationcenter/tests/Unit/RequestInputTest.php`

**Interfaces:**
- Consumes: `PanelType::isValid()` from Task 1.
- Produces: `RequestInput::fromClientData(array $data): RequestInput`, throwing `\FOSSBilling\InformationException` on invalid input. Readonly public properties: `string $panelType`, `string $host`, `?int $port`, `string $username`, `string $password`, `string $sshKey`, `?string $clientNotes`, `?int $clientOrderId`.

- [ ] **Step 1: Write the failing test**

Create `src/modules/Migrationcenter/tests/Unit/RequestInputTest.php`:

```php
<?php

/**
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Box\Mod\Migrationcenter\Support\PanelType;
use Box\Mod\Migrationcenter\Support\RequestInput;

function validMigrationPayload(array $overrides = []): array
{
    return array_merge([
        'panel_type' => PanelType::CPANEL_WHM,
        'host' => 'old-host.example.com',
        'port' => '2083',
        'username' => 'olduser',
        'password' => 'sup3rsecret',
        'ssh_key' => '',
        'client_notes' => 'WordPress site, please keep the database.',
        'client_order_id' => '7',
    ], $overrides);
}

describe('fromClientData', function (): void {
    test('builds a value object from a complete payload', function (): void {
        $input = RequestInput::fromClientData(validMigrationPayload());

        expect($input->panelType)->toBe(PanelType::CPANEL_WHM)
            ->and($input->host)->toBe('old-host.example.com')
            ->and($input->port)->toBe(2083)
            ->and($input->username)->toBe('olduser')
            ->and($input->password)->toBe('sup3rsecret')
            ->and($input->sshKey)->toBe('')
            ->and($input->clientNotes)->toBe('WordPress site, please keep the database.')
            ->and($input->clientOrderId)->toBe(7);
    });

    test('trims host and username', function (): void {
        $input = RequestInput::fromClientData(validMigrationPayload([
            'host' => '  old-host.example.com  ',
            'username' => "  olduser\n",
        ]));

        expect($input->host)->toBe('old-host.example.com')
            ->and($input->username)->toBe('olduser');
    });

    test('omitted port, notes and order become null', function (): void {
        $input = RequestInput::fromClientData(validMigrationPayload([
            'port' => '',
            'client_notes' => '   ',
            'client_order_id' => '',
        ]));

        expect($input->port)->toBeNull()
            ->and($input->clientNotes)->toBeNull()
            ->and($input->clientOrderId)->toBeNull();
    });

    test('an SSH key alone satisfies the credential requirement', function (): void {
        $input = RequestInput::fromClientData(validMigrationPayload([
            'password' => '',
            'ssh_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
        ]));

        expect($input->password)->toBe('')
            ->and($input->sshKey)->toBe('-----BEGIN OPENSSH PRIVATE KEY-----');
    });

    test('rejects an unknown panel type', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['panel_type' => 'cpanel']));
    })->throws(FOSSBilling\InformationException::class, 'Please choose a valid control panel type.');

    test('rejects an empty host', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['host' => '   ']));
    })->throws(FOSSBilling\InformationException::class, 'The previous host address is required.');

    test('rejects a host longer than 255 characters', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['host' => str_repeat('a', 256)]));
    })->throws(FOSSBilling\InformationException::class, 'The previous host address is too long.');

    test('rejects an empty username', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['username' => '']));
    })->throws(FOSSBilling\InformationException::class, 'The username at your previous host is required.');

    test('rejects a port outside the valid range', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['port' => '70000']));
    })->throws(FOSSBilling\InformationException::class, 'The port must be a number between 1 and 65535.');

    test('rejects a non-numeric port', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['port' => 'ssh']));
    })->throws(FOSSBilling\InformationException::class, 'The port must be a number between 1 and 65535.');

    test('rejects a payload with neither password nor SSH key', function (): void {
        RequestInput::fromClientData(validMigrationPayload(['password' => '', 'ssh_key' => '']));
    })->throws(FOSSBilling\InformationException::class, 'Provide either a password or an SSH key for your previous host.');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./src/vendor/bin/pest --test-directory ../tests --testsuite=Modules --filter="fromClientData"
```
Expected: FAIL — `Class "Box\Mod\Migrationcenter\Support\RequestInput" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/modules/Migrationcenter/Support/RequestInput.php`:

```php
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
        $clientOrderId = (int) ($data['client_order_id'] ?? 0);

        return new self(
            $panelType,
            $host,
            $port,
            $username,
            $password,
            $sshKey,
            $clientNotes === '' ? null : $clientNotes,
            $clientOrderId > 0 ? $clientOrderId : null,
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
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./src/vendor/bin/pest --test-directory ../tests --testsuite=Modules --filter="fromClientData"
```
Expected: PASS (11 tests).

- [ ] **Step 5: Format and commit**

```bash
composer cs:fix -- src/modules/Migrationcenter
git add src/modules/Migrationcenter
git commit -m "feat(migrationcenter): validate client-submitted migration payloads"
```

---

### Task 3: Secret encoding and decoding

**Files:**
- Create: `src/modules/Migrationcenter/Support/SecretCodec.php`
- Test: `src/modules/Migrationcenter/tests/Unit/SecretCodecTest.php`

**Interfaces:**
- Consumes: `\Box_Crypt` (core, `src/library/Box/Crypt.php`) — `encrypt(string $text, ?string $pass = null): string`, `decrypt(?string $text, ?string $pass = null)` returning `string|false`.
- Produces: `new SecretCodec(\Box_Crypt $crypt, ?string $passphrase = null)`, `SecretCodec::encode(array $secret): string`, `SecretCodec::decode(string $encrypted): array` returning `array{password: string, ssh_key: string}`.

**Note:** the optional `$passphrase` exists so tests can round-trip with an explicit key. In production it stays `null`, which makes `Box_Crypt` fall back to `Config::getProperty('info.salt')` — identical to how the Extension and Email modules use it.

- [ ] **Step 1: Write the failing test**

Create `src/modules/Migrationcenter/tests/Unit/SecretCodecTest.php`:

```php
<?php

/**
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Box\Mod\Migrationcenter\Support\SecretCodec;

function migrationCodec(): SecretCodec
{
    return new SecretCodec(new Box_Crypt(), 'test-passphrase-for-migrationcenter');
}

describe('encode', function (): void {
    test('the ciphertext never contains the plaintext', function (): void {
        $encoded = migrationCodec()->encode([
            'password' => 'sup3rsecret',
            'ssh_key' => 'BEGIN-KEY-MATERIAL',
        ]);

        expect($encoded)->not->toContain('sup3rsecret')
            ->and($encoded)->not->toContain('BEGIN-KEY-MATERIAL')
            ->and($encoded)->not->toBe('');
    });

    test('two encodings of the same secret differ (random IV)', function (): void {
        $codec = migrationCodec();
        $secret = ['password' => 'sup3rsecret', 'ssh_key' => ''];

        expect($codec->encode($secret))->not->toBe($codec->encode($secret));
    });
});

describe('decode', function (): void {
    test('round-trips a password and an SSH key', function (): void {
        $codec = migrationCodec();
        $encoded = $codec->encode(['password' => 'sup3rsecret', 'ssh_key' => 'BEGIN-KEY-MATERIAL']);

        expect($codec->decode($encoded))->toBe([
            'password' => 'sup3rsecret',
            'ssh_key' => 'BEGIN-KEY-MATERIAL',
        ]);
    });

    test('missing fields normalise to empty strings', function (): void {
        $codec = migrationCodec();
        $encoded = $codec->encode(['password' => 'only-a-password']);

        expect($codec->decode($encoded))->toBe([
            'password' => 'only-a-password',
            'ssh_key' => '',
        ]);
    });

    test('a purged (empty) secret decodes to empty strings without throwing', function (): void {
        expect(migrationCodec()->decode(''))->toBe(['password' => '', 'ssh_key' => '']);
    });

    test('unreadable ciphertext throws a generic error that leaks nothing', function (): void {
        migrationCodec()->decode('this-is-not-valid-ciphertext');
    })->throws(FOSSBilling\Exception::class, 'Stored migration credentials could not be read.');

    test('ciphertext from a different key is not readable', function (): void {
        $encoded = migrationCodec()->encode(['password' => 'sup3rsecret']);
        $otherCodec = new SecretCodec(new Box_Crypt(), 'a-completely-different-passphrase');

        $otherCodec->decode($encoded);
    })->throws(FOSSBilling\Exception::class, 'Stored migration credentials could not be read.');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./src/vendor/bin/pest --test-directory ../tests --testsuite=Modules --filter="encode|decode"
```
Expected: FAIL — `Class "Box\Mod\Migrationcenter\Support\SecretCodec" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/modules/Migrationcenter/Support/SecretCodec.php`:

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./src/vendor/bin/pest --test-directory ../tests --testsuite=Modules --filter="encode|decode"
```
Expected: PASS (6 tests).

- [ ] **Step 5: Format and commit**

```bash
composer cs:fix -- src/modules/Migrationcenter
git add src/modules/Migrationcenter
git commit -m "feat(migrationcenter): encrypt previous-host secrets via Box_Crypt"
```

---

### Task 4: Doctrine entity and repository

**Files:**
- Create: `src/modules/Migrationcenter/Entity/MigrationRequest.php`
- Create: `src/modules/Migrationcenter/Repository/MigrationRequestRepository.php`
- Test: `src/modules/Migrationcenter/tests/Unit/MigrationRequestTest.php`

**Interfaces:**
- Consumes: `RequestStatus`, `PanelType` from Task 1.
- Produces: entity `Box\Mod\Migrationcenter\Entity\MigrationRequest` with getters/setters listed below, `toApiArray(): array` (never contains the secret or staff notes) and `toAdminApiArray(): array` (adds `staff_notes`); repository `MigrationRequestRepository` with `getSearchQueryBuilder(array $data): QueryBuilder`, `findOneForClient(int $id, int $clientId): ?MigrationRequest`, `countByStatus(): array<string,int>`.

- [ ] **Step 1: Write the failing test**

Create `src/modules/Migrationcenter/tests/Unit/MigrationRequestTest.php`:

```php
<?php

/**
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Box\Mod\Migrationcenter\Entity\MigrationRequest;
use Box\Mod\Migrationcenter\Support\PanelType;
use Box\Mod\Migrationcenter\Support\RequestStatus;

function migrationRequestFixture(): MigrationRequest
{
    return (new MigrationRequest())
        ->setClientId(2)
        ->setClientOrderId(7)
        ->setPanelType(PanelType::CPANEL_WHM)
        ->setHost('old-host.example.com')
        ->setPort(2083)
        ->setUsername('olduser')
        ->setSecretEncrypted('v2:ciphertext-goes-here')
        ->setClientNotes('WordPress site')
        ->setStaffNotes('Waiting on DNS TTL');
}

describe('toApiArray', function (): void {
    test('never exposes the encrypted secret or the staff notes', function (): void {
        $array = migrationRequestFixture()->toApiArray();

        expect($array)->not->toHaveKey('secret_encrypted')
            ->and($array)->not->toHaveKey('staff_notes')
            ->and(json_encode($array))->not->toContain('ciphertext-goes-here');
    });

    test('reports whether a secret is still stored', function (): void {
        $request = migrationRequestFixture();
        expect($request->toApiArray()['has_secret'])->toBeTrue();

        $request->purgeSecret();
        expect($request->toApiArray()['has_secret'])->toBeFalse();
    });

    test('carries the safe metadata a client needs to confirm what they sent', function (): void {
        $array = migrationRequestFixture()->toApiArray();

        expect($array['client_id'])->toBe(2)
            ->and($array['client_order_id'])->toBe(7)
            ->and($array['panel_type'])->toBe(PanelType::CPANEL_WHM)
            ->and($array['panel_type_label'])->toBe('cPanel / WHM')
            ->and($array['host'])->toBe('old-host.example.com')
            ->and($array['port'])->toBe(2083)
            ->and($array['username'])->toBe('olduser')
            ->and($array['status'])->toBe(RequestStatus::SUBMITTED)
            ->and($array['method'])->toBe('manual');
    });
});

describe('toAdminApiArray', function (): void {
    test('adds the internal staff notes but still hides the secret', function (): void {
        $array = migrationRequestFixture()->toAdminApiArray();

        expect($array['staff_notes'])->toBe('Waiting on DNS TTL')
            ->and($array)->not->toHaveKey('secret_encrypted')
            ->and(json_encode($array))->not->toContain('ciphertext-goes-here');
    });
});

describe('setStatus', function (): void {
    test('accepts a known status', function (): void {
        $request = migrationRequestFixture()->setStatus(RequestStatus::IN_PROGRESS);

        expect($request->getStatus())->toBe(RequestStatus::IN_PROGRESS);
    });

    test('rejects an unknown status', function (): void {
        migrationRequestFixture()->setStatus('pending');
    })->throws(FOSSBilling\InformationException::class, 'Unknown migration request status.');
});

describe('purgeSecret', function (): void {
    test('clears the ciphertext and records when it happened', function (): void {
        $request = migrationRequestFixture();
        expect($request->getSecretPurgedAt())->toBeNull();

        $request->purgeSecret();

        expect($request->getSecretEncrypted())->toBe('')
            ->and($request->getSecretPurgedAt())->toBeInstanceOf(DateTime::class);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./src/vendor/bin/pest --test-directory ../tests --testsuite=Modules --filter="toApiArray|toAdminApiArray|setStatus|purgeSecret"
```
Expected: FAIL — `Class "Box\Mod\Migrationcenter\Entity\MigrationRequest" not found`.

- [ ] **Step 3: Write the entity**

Create `src/modules/Migrationcenter/Entity/MigrationRequest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration Center — a client's request to migrate from a previous host.
 *
 * The encrypted secret is a private field with no getter on the API array
 * paths: neither toApiArray() nor toAdminApiArray() may ever include it or
 * its decrypted form. Only Service::revealSecret() decrypts, and only for
 * staff holding the manage_migrations permission.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Entity;

use Box\Mod\Migrationcenter\Support\PanelType;
use Box\Mod\Migrationcenter\Support\RequestStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use FOSSBilling\Interfaces\ApiArrayInterface;

#[ORM\Entity(repositoryClass: \Box\Mod\Migrationcenter\Repository\MigrationRequestRepository::class)]
#[ORM\Table(name: 'mod_migrationcenter_request')]
#[ORM\Index(name: 'idx_migrationcenter_client', columns: ['client_id'])]
#[ORM\Index(name: 'idx_migrationcenter_status', columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
class MigrationRequest implements ApiArrayInterface
{
    public const string METHOD_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    /** @phpstan-ignore property.unusedType (Doctrine entity ID is auto-generated by the database) */
    private ?int $id = null;

    #[ORM\Column(name: 'client_id', type: Types::BIGINT)]
    private int $clientId = 0;

    #[ORM\Column(name: 'client_order_id', type: Types::BIGINT, nullable: true)]
    private ?int $clientOrderId = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = RequestStatus::SUBMITTED;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $method = self::METHOD_MANUAL;

    #[ORM\Column(name: 'panel_type', type: Types::STRING, length: 30)]
    private string $panelType = PanelType::OTHER;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $host = '';

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $port = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $username = '';

    #[ORM\Column(name: 'secret_encrypted', type: Types::TEXT)]
    private string $secretEncrypted = '';

    #[ORM\Column(name: 'secret_purged_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTime $secretPurgedAt = null;

    #[ORM\Column(name: 'client_notes', type: Types::TEXT, nullable: true)]
    private ?string $clientNotes = null;

    #[ORM\Column(name: 'staff_notes', type: Types::TEXT, nullable: true)]
    private ?string $staffNotes = null;

    #[ORM\Column(name: 'assigned_staff_id', type: Types::BIGINT, nullable: true)]
    private ?int $assignedStaffId = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private ?\DateTime $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE)]
    private ?\DateTime $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTime();
        $this->updatedAt ??= new \DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    /**
     * Safe projection: client-visible metadata only. Must never carry the
     * secret (encrypted or not) or the internal staff notes.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->getId(),
            'client_id' => $this->clientId,
            'client_order_id' => $this->clientOrderId,
            'status' => $this->status,
            'method' => $this->method,
            'panel_type' => $this->panelType,
            'panel_type_label' => PanelType::label($this->panelType),
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'client_notes' => $this->clientNotes,
            'has_secret' => $this->secretEncrypted !== '',
            'secret_purged_at' => $this->secretPurgedAt?->format('Y-m-d H:i:s'),
            'assigned_staff_id' => $this->assignedStaffId,
            'can_client_cancel' => RequestStatus::isClientCancellable($this->status),
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Staff projection: adds internal notes and purge eligibility. Still no
     * secret — that comes only from Service::revealSecret().
     */
    public function toAdminApiArray(): array
    {
        return $this->toApiArray() + [
            'staff_notes' => $this->staffNotes,
            'can_purge_secret' => RequestStatus::isPurgeable($this->status) && $this->secretEncrypted !== '',
        ];
    }

    public function purgeSecret(): self
    {
        $this->secretEncrypted = '';
        $this->secretPurgedAt = new \DateTime();

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClientId(): int
    {
        return $this->clientId;
    }

    public function setClientId(int $clientId): self
    {
        $this->clientId = $clientId;

        return $this;
    }

    public function getClientOrderId(): ?int
    {
        return $this->clientOrderId;
    }

    public function setClientOrderId(?int $clientOrderId): self
    {
        $this->clientOrderId = $clientOrderId;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!RequestStatus::isValid($status)) {
            throw new \FOSSBilling\InformationException('Unknown migration request status.');
        }

        $this->status = $status;

        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): self
    {
        $this->method = $method;

        return $this;
    }

    public function getPanelType(): string
    {
        return $this->panelType;
    }

    public function setPanelType(string $panelType): self
    {
        $this->panelType = $panelType;

        return $this;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host): self
    {
        $this->host = $host;

        return $this;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function setPort(?int $port): self
    {
        $this->port = $port;

        return $this;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;

        return $this;
    }

    public function getSecretEncrypted(): string
    {
        return $this->secretEncrypted;
    }

    public function setSecretEncrypted(string $secretEncrypted): self
    {
        $this->secretEncrypted = $secretEncrypted;

        return $this;
    }

    public function getSecretPurgedAt(): ?\DateTime
    {
        return $this->secretPurgedAt;
    }

    public function getClientNotes(): ?string
    {
        return $this->clientNotes;
    }

    public function setClientNotes(?string $clientNotes): self
    {
        $this->clientNotes = $clientNotes;

        return $this;
    }

    public function getStaffNotes(): ?string
    {
        return $this->staffNotes;
    }

    public function setStaffNotes(?string $staffNotes): self
    {
        $this->staffNotes = $staffNotes;

        return $this;
    }

    public function getAssignedStaffId(): ?int
    {
        return $this->assignedStaffId;
    }

    public function setAssignedStaffId(?int $assignedStaffId): self
    {
        $this->assignedStaffId = $assignedStaffId;

        return $this;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }
}
```

- [ ] **Step 4: Write the repository**

Create `src/modules/Migrationcenter/Repository/MigrationRequestRepository.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration Center — migration request queries.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Repository;

use Box\Mod\Migrationcenter\Entity\MigrationRequest;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

class MigrationRequestRepository extends EntityRepository
{
    /**
     * @param array $data filters: 'id', 'client_id', 'status' (string or list),
     *                    'assigned_staff_id', 'panel_type', 'search'
     */
    public function getSearchQueryBuilder(array $data): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r');

        if (!empty($data['id'])) {
            $qb->andWhere('r.id = :id')->setParameter('id', (int) $data['id']);
        }

        if (!empty($data['client_id'])) {
            $qb->andWhere('r.clientId = :clientId')->setParameter('clientId', (int) $data['client_id']);
        }

        if (!empty($data['status'])) {
            // Accept a single status or a list, so the UI can group tabs.
            if (is_array($data['status'])) {
                $qb->andWhere('r.status IN (:statuses)')->setParameter('statuses', $data['status']);
            } else {
                $qb->andWhere('r.status = :status')->setParameter('status', (string) $data['status']);
            }
        }

        if (!empty($data['assigned_staff_id'])) {
            $qb->andWhere('r.assignedStaffId = :staffId')->setParameter('staffId', (int) $data['assigned_staff_id']);
        }

        if (!empty($data['panel_type'])) {
            $qb->andWhere('r.panelType = :panelType')->setParameter('panelType', (string) $data['panel_type']);
        }

        if (!empty($data['search'])) {
            $qb->andWhere('(r.host LIKE :q OR r.username LIKE :q)')
                ->setParameter('q', '%' . $data['search'] . '%');
        }

        $qb->orderBy('r.id', 'DESC');

        return $qb;
    }

    /**
     * Load a request only if it belongs to the given client. Returning null
     * for someone else's request is what keeps the client API scoped.
     */
    public function findOneForClient(int $id, int $clientId): ?MigrationRequest
    {
        return $this->findOneBy(['id' => $id, 'clientId' => $clientId]);
    }

    /**
     * @return array<string, int> status => count
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.status AS status, COUNT(r.id) AS total')
            ->groupBy('r.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
./src/vendor/bin/pest --test-directory ../tests --testsuite=Modules --filter="toApiArray|toAdminApiArray|setStatus|purgeSecret"
```
Expected: PASS (7 tests).

- [ ] **Step 6: Format and commit**

```bash
composer cs:fix -- src/modules/Migrationcenter
git add src/modules/Migrationcenter
git commit -m "feat(migrationcenter): add migration request entity and repository"
```

---

### Task 5: Module service, schema install, and permissions

**Files:**
- Create: `src/modules/Migrationcenter/manifest.json`
- Create: `src/modules/Migrationcenter/icon.svg`
- Create: `src/modules/Migrationcenter/Service.php`

**Interfaces:**
- Consumes: `MigrationRequest`, `MigrationRequestRepository` (Task 4), `RequestInput` (Task 2), `SecretCodec` (Task 3), `RequestStatus` (Task 1).
- Produces (all on `Box\Mod\Migrationcenter\Service`):
  - `install(): bool`, `uninstall(): bool`, `getModulePermissions(): array`
  - `createRequest(int $clientId, array $data): int`
  - `getClientRequests(int $clientId): array` — list of `toApiArray()`
  - `getRequestForClient(int $id, int $clientId): array` — `toApiArray()`
  - `cancelRequestForClient(int $id, int $clientId): bool`
  - `getAdminRequestList(array $data): array` — list of `toAdminApiArray()`
  - `getAdminRequest(int $id): array` — `toAdminApiArray()`
  - `revealSecret(int $id): array` — `array{password: string, ssh_key: string}`
  - `updateStatus(int $id, string $status): bool`
  - `assign(int $id, ?int $staffId): bool`
  - `updateStaffNotes(int $id, ?string $notes): bool`
  - `purgeSecret(int $id): bool`
  - `getStatusCounts(): array`

- [ ] **Step 1: Write the manifest and icon**

Create `src/modules/Migrationcenter/manifest.json`:

```json
{
  "id": "migrationcenter",
  "type": "mod",
  "name": "Migration Center",
  "description": "Lets clients hand their previous host's credentials to migration support staff, and gives staff a queue to work those migrations through. Credentials are encrypted at rest and only readable by staff holding the manage_migrations permission.",
  "version": "0.1.0",
  "minimum_fossbilling_version": "0.8.3",
  "homepage_url": "https://merovps.com",
  "author": "MeroVPS",
  "author_url": "https://merovps.com",
  "license": "Apache-2.0",
  "icon_url": "icon.svg"
}
```

Create `src/modules/Migrationcenter/icon.svg`:

```svg
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <path d="M4 7V5a1 1 0 0 1 1-1h6l2 2h6a1 1 0 0 1 1 1v2" />
  <path d="M3 11h12" />
  <path d="M11 7l4 4-4 4" />
  <path d="M20 12v6a1 1 0 0 1-1 1H8" />
</svg>
```

- [ ] **Step 2: Write the service**

Create `src/modules/Migrationcenter/Service.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration Center — core service.
 *
 * Owns the mod_migrationcenter_request table: creation, the staff status
 * workflow, and the single decryption path (revealSecret). Callers are
 * responsible for permission checks; the API layer enforces them.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter;

use Box\Mod\Migrationcenter\Entity\MigrationRequest;
use Box\Mod\Migrationcenter\Repository\MigrationRequestRepository;
use Box\Mod\Migrationcenter\Support\RequestInput;
use Box\Mod\Migrationcenter\Support\RequestStatus;
use Box\Mod\Migrationcenter\Support\SecretCodec;
use FOSSBilling\InjectionAwareInterface;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    private ?SecretCodec $codec = null;

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
            'view_migrations' => [
                'type' => 'bool',
                'display_name' => __trans('View migration requests'),
                'description' => __trans('Allows the staff member to see the migration queue and each request\'s details, but not the stored credentials.'),
            ],
            'manage_migrations' => [
                'type' => 'bool',
                'display_name' => __trans('Manage migrations and view credentials'),
                'description' => __trans('Allows the staff member to read the previous host credentials a client submitted, change a request\'s status, assign it, and purge the stored credentials.'),
            ],
        ];
    }

    public function install(): bool
    {
        $this->di['dbal']->executeStatement('
            CREATE TABLE IF NOT EXISTS `mod_migrationcenter_request` (
                `id`                BIGINT       NOT NULL AUTO_INCREMENT,
                `client_id`         BIGINT       NOT NULL,
                `client_order_id`   BIGINT       NULL,
                `status`            VARCHAR(20)  NOT NULL DEFAULT \'submitted\',
                `method`            VARCHAR(20)  NOT NULL DEFAULT \'manual\',
                `panel_type`        VARCHAR(30)  NOT NULL,
                `host`              VARCHAR(255) NOT NULL,
                `port`              INT          NULL,
                `username`          VARCHAR(255) NOT NULL,
                `secret_encrypted`  TEXT         NOT NULL,
                `secret_purged_at`  DATETIME     NULL,
                `client_notes`      TEXT         NULL,
                `staff_notes`       TEXT         NULL,
                `assigned_staff_id` BIGINT       NULL,
                `created_at`        DATETIME     NOT NULL,
                `updated_at`        DATETIME     NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_migrationcenter_client` (`client_id`),
                KEY `idx_migrationcenter_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        return true;
    }

    /**
     * Deliberately keeps the table: uninstalling the module must not destroy
     * a client's migration history.
     */
    public function uninstall(): bool
    {
        return true;
    }

    // ── Client-facing ────────────────────────────────────────────────────

    public function createRequest(int $clientId, array $data): int
    {
        $input = RequestInput::fromClientData($data);

        $orderId = $input->clientOrderId;
        if ($orderId !== null && !$this->clientOwnsOrder($clientId, $orderId)) {
            throw new \FOSSBilling\InformationException('The selected service does not belong to your account.');
        }

        $request = (new MigrationRequest())
            ->setClientId($clientId)
            ->setClientOrderId($orderId)
            ->setStatus(RequestStatus::SUBMITTED)
            ->setMethod(MigrationRequest::METHOD_MANUAL)
            ->setPanelType($input->panelType)
            ->setHost($input->host)
            ->setPort($input->port)
            ->setUsername($input->username)
            ->setClientNotes($input->clientNotes)
            ->setSecretEncrypted($this->codec()->encode([
                'password' => $input->password,
                'ssh_key' => $input->sshKey,
            ]));

        $this->di['em']->persist($request);
        $this->di['em']->flush();

        $id = (int) $request->getId();
        // Never log host, username or the secret — only the identifiers.
        $this->di['logger']->info(sprintf('Migration request %d created by client %d.', $id, $clientId));

        return $id;
    }

    public function getClientRequests(int $clientId): array
    {
        $requests = $this->repository()
            ->getSearchQueryBuilder(['client_id' => $clientId])
            ->getQuery()
            ->getResult();

        return array_map(static fn (MigrationRequest $r): array => $r->toApiArray(), $requests);
    }

    public function getRequestForClient(int $id, int $clientId): array
    {
        return $this->loadForClient($id, $clientId)->toApiArray();
    }

    public function cancelRequestForClient(int $id, int $clientId): bool
    {
        $request = $this->loadForClient($id, $clientId);

        if (!RequestStatus::isClientCancellable($request->getStatus())) {
            throw new \FOSSBilling\InformationException('This migration request can no longer be cancelled. Please contact support.');
        }

        $request->setStatus(RequestStatus::CANCELLED);
        $this->di['em']->flush();

        return true;
    }

    // ── Staff-facing ─────────────────────────────────────────────────────

    public function getAdminRequestList(array $data): array
    {
        $requests = $this->repository()
            ->getSearchQueryBuilder($data)
            ->getQuery()
            ->getResult();

        return array_map(static fn (MigrationRequest $r): array => $r->toAdminApiArray(), $requests);
    }

    public function getAdminRequest(int $id): array
    {
        return $this->load($id)->toAdminApiArray();
    }

    /**
     * The one and only decryption path. Callers must have already enforced
     * the manage_migrations permission.
     *
     * @return array{password: string, ssh_key: string}
     */
    public function revealSecret(int $id): array
    {
        return $this->codec()->decode($this->load($id)->getSecretEncrypted());
    }

    public function updateStatus(int $id, string $status): bool
    {
        $request = $this->load($id);
        $current = $request->getStatus();

        if ($current === $status) {
            return true;
        }

        if (!RequestStatus::canTransition($current, $status)) {
            throw new \FOSSBilling\InformationException('A migration request cannot move from :from to :to.', [':from' => $current, ':to' => $status]);
        }

        $request->setStatus($status);
        $this->di['em']->flush();

        $this->di['logger']->info(sprintf('Migration request %d moved from %s to %s.', $id, $current, $status));

        return true;
    }

    public function assign(int $id, ?int $staffId): bool
    {
        $this->load($id)->setAssignedStaffId($staffId !== null && $staffId > 0 ? $staffId : null);
        $this->di['em']->flush();

        return true;
    }

    public function updateStaffNotes(int $id, ?string $notes): bool
    {
        $notes = $notes === null ? null : trim($notes);
        $this->load($id)->setStaffNotes($notes === '' ? null : $notes);
        $this->di['em']->flush();

        return true;
    }

    public function purgeSecret(int $id): bool
    {
        $request = $this->load($id);

        if (!RequestStatus::isPurgeable($request->getStatus())) {
            throw new \FOSSBilling\InformationException('Credentials can only be purged once the migration is completed, failed or cancelled.');
        }

        $request->purgeSecret();
        $this->di['em']->flush();

        $this->di['logger']->info(sprintf('Migration request %d credentials purged.', $id));

        return true;
    }

    public function getStatusCounts(): array
    {
        return $this->repository()->countByStatus();
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function repository(): MigrationRequestRepository
    {
        return $this->di['em']->getRepository(MigrationRequest::class);
    }

    private function codec(): SecretCodec
    {
        return $this->codec ??= new SecretCodec($this->di['crypt']);
    }

    private function load(int $id): MigrationRequest
    {
        $request = $this->repository()->find($id);
        if (!$request instanceof MigrationRequest) {
            throw new \FOSSBilling\InformationException('Migration request not found.');
        }

        return $request;
    }

    private function loadForClient(int $id, int $clientId): MigrationRequest
    {
        $request = $this->repository()->findOneForClient($id, $clientId);
        if (!$request instanceof MigrationRequest) {
            throw new \FOSSBilling\InformationException('Migration request not found.');
        }

        return $request;
    }

    private function clientOwnsOrder(int $clientId, int $orderId): bool
    {
        $count = (int) $this->di['dbal']->fetchOne(
            'SELECT COUNT(*) FROM client_order WHERE id = ? AND client_id = ?',
            [$orderId, $clientId]
        );

        return $count > 0;
    }
}
```

- [ ] **Step 3: Install and activate the module**

```bash
cd FOSSBilling
curl -s -X POST "http://localhost:9000/api/admin/extension/activate" \
  --user "admin:3c8btj3fNL8Vu7V5ieXGEiZ7pbk6LMqJ" \
  -d "type=mod" -d "id=migrationcenter"
```
Expected: JSON `{"result":...,"error":null}` with no error.

- [ ] **Step 4: Verify the table exists with the expected shape**

```bash
mysql -h 127.0.0.1 -u merovps -pmerovps merovps -e "DESCRIBE mod_migrationcenter_request;"
```
Expected: 16 columns, `id` is `bigint` (NOT `bigint unsigned`), `secret_encrypted` is `text NOT NULL`, `secret_purged_at` is `datetime NULL`.

- [ ] **Step 5: Verify static analysis is clean**

```bash
composer phpstan 2>&1 | grep -i migrationcenter
```
Expected: no output (no findings in the new module).

- [ ] **Step 6: Format and commit**

```bash
composer cs:fix -- src/modules/Migrationcenter
git add src/modules/Migrationcenter
git commit -m "feat(migrationcenter): add module service, schema and permissions"
```

---

### Task 6: Client API, controller, and rate limiting

**Files:**
- Create: `src/modules/Migrationcenter/Api/Client.php`
- Create: `src/modules/Migrationcenter/Controller/Client.php`
- Modify: `src/library/FOSSBilling/Security/RateLimiter.php` (add two policies to `getDefaultConfig()`)

**Interfaces:**
- Consumes: `Service::createRequest/getClientRequests/getRequestForClient/cancelRequestForClient` (Task 5).
- Produces: API endpoints `client/migrationcenter/create` (returns `int` id), `client/migrationcenter/get_list` (returns `array`), `client/migrationcenter/get` (returns `array`), `client/migrationcenter/cancel` (returns `bool`); client route `GET /migrationcenter` rendering `mod_migrationcenter_index`.

**Note:** `RateLimiter.php` is a **core file** — it is on the local-patch list in `CLAUDE.md` and must be re-applied after any FOSSBilling upgrade. Task 11 adds the documentation row.

- [ ] **Step 1: Add the rate limiter policies**

In `src/library/FOSSBilling/Security/RateLimiter.php`, inside `getDefaultConfig()`'s `'policies'` array, add these lines immediately after the `'sociallogin_credential_ip'` entry:

```php
                // A migration request hands us third-party credentials and emails staff;
                // an unbounded intake form is both a spam vector and a way to bulk-probe
                // which order ids exist. A genuine client files one of these rarely.
                'migrationcenter_request_ip' => ['policy' => 'fixed_window', 'limit' => 10, 'interval' => '1 hour'],
                'migrationcenter_request_client' => ['policy' => 'fixed_window', 'limit' => 5, 'interval' => '1 hour'],
```

- [ ] **Step 2: Write the client API**

Create `src/modules/Migrationcenter/Api/Client.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration Center — Client API.
 *
 * The secret a client submits is write-only: no endpoint here ever returns
 * it, encrypted or otherwise. Every read is scoped to the logged-in client.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Api;

use FOSSBilling\Validation\Api\RequiredParams;

class Client extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Submit a migration request for the logged-in client.
     *
     * @optional int    port            Control panel / SSH port
     * @optional string password        Password at the previous host
     * @optional string ssh_key         SSH private key at the previous host
     * @optional string client_notes    Anything staff should know
     * @optional int    client_order_id The service being migrated onto
     */
    #[RequiredParams([
        'panel_type' => 'Please choose a valid control panel type.',
        'host' => 'The previous host address is required.',
        'username' => 'The username at your previous host is required.',
    ])]
    public function create(array $data): int
    {
        $client = $this->getIdentity();

        $this->getDi()['rate_limiter']->consumeOrThrow('migrationcenter_request_ip', (string) $this->getIp());
        $this->getDi()['rate_limiter']->consumeOrThrow('migrationcenter_request_client', (string) $client->id);

        return $this->getService()->createRequest((int) $client->id, $data);
    }

    /**
     * List the logged-in client's own migration requests.
     */
    public function get_list(array $data): array
    {
        return $this->getService()->getClientRequests((int) $this->getIdentity()->id);
    }

    /**
     * Get one of the logged-in client's own migration requests.
     */
    #[RequiredParams(['id' => 'Migration request ID is required'])]
    public function get(array $data): array
    {
        return $this->getService()->getRequestForClient((int) $data['id'], (int) $this->getIdentity()->id);
    }

    /**
     * Withdraw a migration request that staff have not started yet.
     */
    #[RequiredParams(['id' => 'Migration request ID is required'])]
    public function cancel(array $data): bool
    {
        return $this->getService()->cancelRequestForClient((int) $data['id'], (int) $this->getIdentity()->id);
    }
}
```

- [ ] **Step 3: Write the client controller**

Create `src/modules/Migrationcenter/Controller/Client.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration Center — Client Controller.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Controller;

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
        $app->get('/migrationcenter', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_client_logged'];

        return $app->render('mod_migrationcenter_index');
    }
}
```

- [ ] **Step 4: Verify the endpoints respond**

Log in as the test client in a browser at `http://localhost:9000/login` (`pramodyadav826@gmail.com`), then from the browser console:

```js
await (await fetch('/api/client/migrationcenter/get_list', {method:'POST',headers:{'Content-Type':'application/json'},body:'{}'})).json()
```
Expected: `{"result":[],"error":null}`.

Then submit one:

```js
await (await fetch('/api/client/migrationcenter/create', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({panel_type:'cpanel_whm',host:'old.example.com',port:2083,username:'olduser',password:'sup3rsecret',client_notes:'test'})})).json()
```
Expected: `{"result":<id>,"error":null}`.

- [ ] **Step 5: Verify the secret is encrypted at rest and absent from the API**

```bash
mysql -h 127.0.0.1 -u merovps -pmerovps merovps \
  -e "SELECT id, host, username, LEFT(secret_encrypted, 8) AS secret_prefix FROM mod_migrationcenter_request ORDER BY id DESC LIMIT 1;"
```
Expected: `secret_prefix` starts with `v2:` and the plaintext `sup3rsecret` appears nowhere.

```bash
mysql -h 127.0.0.1 -u merovps -pmerovps merovps \
  -e "SELECT COUNT(*) FROM mod_migrationcenter_request WHERE secret_encrypted LIKE '%sup3rsecret%';"
```
Expected: `0`.

Re-run `get_list` in the browser console. Expected: the request appears with `has_secret: true` and **no** `secret_encrypted` key.

- [ ] **Step 6: Verify the rate limit actually rejects a flood**

In the browser console as the logged-in test client, fire eight submissions (the per-client cap is 5/hour):

```js
for (let i = 0; i < 8; i++) {
  const r = await (await fetch('/api/client/migrationcenter/create', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({panel_type:'other',host:'flood'+i+'.example.com',username:'u',password:'p'})})).json();
  console.log(i, r.error ? r.error.message : 'ok');
}
```
Expected: the first few succeed, then the remainder fail with a rate-limit error mentioning too many requests. Clean up the test rows afterwards:

```bash
mysql -h 127.0.0.1 -u merovps -pmerovps merovps \
  -e "DELETE FROM mod_migrationcenter_request WHERE host LIKE 'flood%.example.com';"
```

Then clear the rate-limiter state so later tasks are not blocked:

```bash
rm -rf FOSSBilling/src/data/cache/rate_limiter* 2>/dev/null; find FOSSBilling/src/data/cache -name "*rate*" -delete 2>/dev/null; true
```

- [ ] **Step 7: Format and commit**

```bash
composer cs:fix -- src/modules/Migrationcenter src/library/FOSSBilling/Security/RateLimiter.php
git add src/modules/Migrationcenter src/library/FOSSBilling/Security/RateLimiter.php
git commit -m "feat(migrationcenter): add client API, route and rate limiting"
```

---

### Task 7: Admin API and controller

**Files:**
- Create: `src/modules/Migrationcenter/Api/Admin.php`
- Create: `src/modules/Migrationcenter/Controller/Admin.php`
- Test: `src/modules/Migrationcenter/tests/Unit/AdminApiPermissionsTest.php`

**Interfaces:**
- Consumes: `Service::getAdminRequestList/getAdminRequest/revealSecret/updateStatus/assign/updateStaffNotes/purgeSecret/getStatusCounts` (Task 5), `RequestStatus` (Task 1).
- Produces: API endpoints `admin/migrationcenter/get_list`, `get`, `status_counts` (all `view_migrations`); `reveal_secret`, `update_status`, `assign`, `update_notes`, `purge_secret` (all `manage_migrations`). Admin route `GET /migrationcenter` rendering `mod_migrationcenter_index`, plus a nav entry under the `support` group.

**Security rule for this task:** every method calls `checkPermissions()` explicitly. Module-level access must never imply secret access — this is the exact gap that was fixed in `Serviceserver`/`Sms`/`Whatsapp` (see `CLAUDE.md`).

- [ ] **Step 1: Write the admin API**

Create `src/modules/Migrationcenter/Api/Admin.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration Center — Admin API.
 *
 * Permission split: view_migrations sees the queue and each request's
 * metadata; manage_migrations is required to read the stored credentials or
 * to change anything. Every method checks explicitly — `can_always_access`
 * on the module would otherwise let any staff account through.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Api;

use Box\Mod\Migrationcenter\Support\RequestStatus;
use FOSSBilling\Validation\Api\RequiredParams;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    /**
     * List migration requests.
     *
     * @optional string status            Filter by status
     * @optional int    client_id         Filter by client
     * @optional int    assigned_staff_id Filter by assignee
     * @optional string search            Match host or username
     */
    public function get_list(array $data): array
    {
        $this->checkPermissions('migrationcenter', 'view_migrations');

        return $this->getService()->getAdminRequestList($data);
    }

    /**
     * Get one migration request's details. Never contains the credentials.
     */
    #[RequiredParams(['id' => 'Migration request ID is required'])]
    public function get(array $data): array
    {
        $this->checkPermissions('migrationcenter', 'view_migrations');

        return $this->getService()->getAdminRequest((int) $data['id']);
    }

    /**
     * Per-status counts for the queue tabs.
     */
    public function status_counts(array $data): array
    {
        $this->checkPermissions('migrationcenter', 'view_migrations');

        return $this->getService()->getStatusCounts();
    }

    /**
     * Decrypt and return the previous host credentials.
     *
     * This is the only endpoint in the entire module that returns plaintext
     * credentials. It is kept separate from get() so the detail page's
     * server-rendered payload carries nothing sensitive for staff who only
     * hold view_migrations.
     */
    #[RequiredParams(['id' => 'Migration request ID is required'])]
    public function reveal_secret(array $data): array
    {
        $this->checkPermissions('migrationcenter', 'manage_migrations');

        return $this->getService()->revealSecret((int) $data['id']);
    }

    /**
     * Move a request through the status workflow.
     */
    #[RequiredParams(['id' => 'Migration request ID is required', 'status' => 'New status is required'])]
    public function update_status(array $data): bool
    {
        $this->checkPermissions('migrationcenter', 'manage_migrations');

        $status = (string) $data['status'];
        if (!RequestStatus::isValid($status)) {
            throw new \FOSSBilling\InformationException('Unknown migration request status.');
        }

        return $this->getService()->updateStatus((int) $data['id'], $status);
    }

    /**
     * Assign a request to a staff member, or clear the assignment.
     *
     * @optional int staff_id Omit or pass 0 to unassign
     */
    #[RequiredParams(['id' => 'Migration request ID is required'])]
    public function assign(array $data): bool
    {
        $this->checkPermissions('migrationcenter', 'manage_migrations');

        return $this->getService()->assign((int) $data['id'], isset($data['staff_id']) ? (int) $data['staff_id'] : null);
    }

    /**
     * Update the internal staff notes on a request.
     *
     * @optional string staff_notes Internal notes; never shown to the client
     */
    #[RequiredParams(['id' => 'Migration request ID is required'])]
    public function update_notes(array $data): bool
    {
        $this->checkPermissions('migrationcenter', 'manage_migrations');

        return $this->getService()->updateStaffNotes((int) $data['id'], isset($data['staff_notes']) ? (string) $data['staff_notes'] : null);
    }

    /**
     * Permanently erase the stored credentials for a finished request.
     */
    #[RequiredParams(['id' => 'Migration request ID is required'])]
    public function purge_secret(array $data): bool
    {
        $this->checkPermissions('migrationcenter', 'manage_migrations');

        return $this->getService()->purgeSecret((int) $data['id']);
    }
}
```

- [ ] **Step 2: Write the admin controller**

Create `src/modules/Migrationcenter/Controller/Admin.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration Center — Admin Controller.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Controller;

class Admin implements \FOSSBilling\InjectionAwareInterface
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

    public function fetchNavigation(): array
    {
        return [
            'subpages' => [
                [
                    'location' => 'support',
                    'index' => 800,
                    'label' => __trans('Migration Center'),
                    'uri' => $this->di['url']->adminLink('migrationcenter'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/migrationcenter', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_migrationcenter_index');
    }
}
```

- [ ] **Step 3: Write the permission guard test**

This is the regression guard for the exact bug class that was fixed in `Serviceserver`/`Sms`/`Whatsapp`: a public API method that forgets its `checkPermissions()` call is reachable by any staff account. Rather than stand up a Staff service, the test asserts structurally that every public endpoint checks, and that the secret-returning ones demand `manage_migrations`.

Create `src/modules/Migrationcenter/tests/Unit/AdminApiPermissionsTest.php`:

```php
<?php

/**
 * SPDX-License-Identifier: Apache-2.0.
 */

declare(strict_types=1);

use Box\Mod\Migrationcenter\Api\Admin;

/**
 * Return the source text of one method on the admin API.
 */
function migrationAdminMethodSource(string $method): string
{
    $reflection = new ReflectionMethod(Admin::class, $method);
    $file = file($reflection->getFileName());

    return implode('', array_slice(
        $file,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1
    ));
}

/**
 * Every endpoint a staff member can call on this module.
 */
function migrationAdminEndpoints(): array
{
    return array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        array_filter(
            (new ReflectionClass(Admin::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === Admin::class
        )
    );
}

describe('admin API permission gating', function (): void {
    test('there is at least one endpoint to check', function (): void {
        expect(migrationAdminEndpoints())->not->toBeEmpty();
    });

    test('every endpoint calls checkPermissions', function (): void {
        foreach (migrationAdminEndpoints() as $method) {
            expect(migrationAdminMethodSource($method))
                ->toContain("\$this->checkPermissions('migrationcenter'", "{$method}() must call checkPermissions()");
        }
    });

    test('endpoints that read or change credentials require manage_migrations', function (): void {
        $privileged = ['reveal_secret', 'update_status', 'assign', 'update_notes', 'purge_secret'];

        foreach ($privileged as $method) {
            expect(migrationAdminMethodSource($method))
                ->toContain("'manage_migrations'", "{$method}() must require manage_migrations");
        }
    });

    test('read-only endpoints require only view_migrations', function (): void {
        foreach (['get_list', 'get', 'status_counts'] as $method) {
            $source = migrationAdminMethodSource($method);
            expect($source)->toContain("'view_migrations'")
                ->and($source)->not->toContain("'manage_migrations'");
        }
    });

    test('no read-only endpoint reaches the decryption path', function (): void {
        foreach (['get_list', 'get', 'status_counts'] as $method) {
            expect(migrationAdminMethodSource($method))
                ->not->toContain('revealSecret', "{$method}() must never decrypt credentials");
        }
    });
});
```

Run it:
```bash
./src/vendor/bin/pest --test-directory ../tests --testsuite=Modules --filter="admin API permission gating"
```
Expected: PASS (5 tests).

- [ ] **Step 4: Verify the admin endpoints work**

```bash
curl -s -X POST "http://localhost:9000/api/admin/migrationcenter/get_list" \
  --user "admin:3c8btj3fNL8Vu7V5ieXGEiZ7pbk6LMqJ" -d "{}"
```
Expected: the request created in Task 6, with `staff_notes` and `can_purge_secret` present and **no** secret field.

```bash
curl -s -X POST "http://localhost:9000/api/admin/migrationcenter/reveal_secret" \
  --user "admin:3c8btj3fNL8Vu7V5ieXGEiZ7pbk6LMqJ" -d "id=1"
```
Expected: `{"result":{"password":"sup3rsecret","ssh_key":""},"error":null}`.

- [ ] **Step 5: Verify the status workflow is enforced**

```bash
curl -s -X POST "http://localhost:9000/api/admin/migrationcenter/update_status" \
  --user "admin:3c8btj3fNL8Vu7V5ieXGEiZ7pbk6LMqJ" -d "id=1" -d "status=completed"
curl -s -X POST "http://localhost:9000/api/admin/migrationcenter/update_status" \
  --user "admin:3c8btj3fNL8Vu7V5ieXGEiZ7pbk6LMqJ" -d "id=1" -d "status=in_progress"
```
Expected: the first returns `{"result":true,...}`; the second returns an error containing `cannot move from completed to in_progress`.

- [ ] **Step 6: Verify purge works and is irreversible**

```bash
curl -s -X POST "http://localhost:9000/api/admin/migrationcenter/purge_secret" \
  --user "admin:3c8btj3fNL8Vu7V5ieXGEiZ7pbk6LMqJ" -d "id=1"
mysql -h 127.0.0.1 -u merovps -pmerovps merovps \
  -e "SELECT id, secret_encrypted = '' AS purged, secret_purged_at FROM mod_migrationcenter_request WHERE id = 1;"
```
Expected: `purged` is `1` and `secret_purged_at` is a timestamp. A follow-up `reveal_secret` returns empty strings rather than an error.

- [ ] **Step 7: Format and commit**

```bash
composer cs:fix -- src/modules/Migrationcenter
git add src/modules/Migrationcenter
git commit -m "feat(migrationcenter): add permission-gated admin API and queue route"
```

---

### Task 8: Client template and portal navigation

**Files:**
- Create: `src/modules/Migrationcenter/templates/client/mod_migrationcenter_index.html.twig`
- Modify: `src/themes/meroserver/html/layout_default.html.twig` (add the sidebar nav link)
- Modify: `src/themes/meroserver/html/mod_servicehosting_manage.html.twig:41-71` (add the per-service entry point)

**Interfaces:**
- Consumes: `client/migrationcenter/*` endpoints (Task 6), `PanelType::ALL` values.
- Produces: the page at `/migrationcenter`; nothing consumes it.

**Alpine.js timing rule (from `CLAUDE.md`):** `Alpine.start()` runs in `<head>`, so any function used in `x-data="fn()"` must be defined in an inline `<script>` inside `{% block content %}` **before** its element — never in `{% block js %}`.

- [ ] **Step 1: Write the client template**

Create `src/modules/Migrationcenter/templates/client/mod_migrationcenter_index.html.twig`:

```twig
{% extends "layout_default.html.twig" %}

{% block meta_title %}Migration Center{% endblock %}

{% block content %}
{# Alpine factory must be defined before the x-data element — Alpine.start() runs in <head>. #}
<script>
function migrationCenter() {
    return {
        submitting: false,
        error: '',
        success: '',
        form: {
            panel_type: 'cpanel_whm',
            host: '',
            port: '',
            username: '',
            password: '',
            ssh_key: '',
            client_notes: '',
            // Pre-filled when arriving from a service's manage page (?order_id=N).
            // `request.x` is a RequestDataView over the query string — it renders
            // empty when the param is absent, so no |default is needed.
            client_order_id: '{{ request.order_id }}'
        },
        showKey: false,
        reset() {
            this.form.password = '';
            this.form.ssh_key = '';
            this.form.host = '';
            this.form.port = '';
            this.form.username = '';
            this.form.client_notes = '';
        },
        submit() {
            this.error = '';
            this.success = '';
            this.submitting = true;
            API.client.post('migrationcenter/create', this.form, (r) => {
                this.submitting = false;
                this.success = 'Your migration request has been sent to our migration team. We will be in touch shortly.';
                this.reset();
                setTimeout(() => window.location.reload(), 1500);
            }, (e) => {
                this.submitting = false;
                this.error = e.message || 'Could not submit your migration request.';
            });
        },
        cancel(id) {
            if (!confirm('Withdraw this migration request?')) { return; }
            API.client.post('migrationcenter/cancel', { id: id }, () => window.location.reload(),
                (e) => { this.error = e.message || 'Could not cancel this request.'; });
        }
    };
}
</script>

<div x-data="migrationCenter()" class="max-w-4xl mx-auto px-4 py-8 space-y-8">

    <header>
        <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Migration Center</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Moving in from another host? Send us the login details for your old hosting account and our
            migration team will move your data across for you.
        </p>
    </header>

    {# ── Existing requests ─────────────────────────────────────────── #}
    {% set requests = client.migrationcenter_get_list %}
    {% if requests|length %}
    <section class="rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50">
            <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Your migration requests</h2>
        </div>
        <div class="divide-y divide-slate-200 dark:divide-slate-700">
            {% for r in requests %}
            <div class="px-5 py-4 flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-slate-800 dark:text-white truncate">
                        {{ r.username }}@{{ r.host }}{% if r.port %}:{{ r.port }}{% endif %}
                    </p>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ r.panel_type_label }} · submitted {{ r.created_at|format_date }}
                    </p>
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <span class="px-2.5 py-1 rounded-full text-xs font-medium
                        {% if r.status == 'completed' %}bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400
                        {% elseif r.status == 'failed' %}bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400
                        {% elseif r.status == 'in_progress' %}bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400
                        {% elseif r.status == 'cancelled' %}bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300
                        {% else %}bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-400{% endif %}">
                        {{ r.status|replace({'_': ' '})|title }}
                    </span>
                    {% if r.can_client_cancel %}
                    <button type="button" @click="cancel({{ r.id }})"
                            class="text-xs font-medium text-slate-500 hover:text-red-600 dark:hover:text-red-400">
                        Withdraw
                    </button>
                    {% endif %}
                </div>
            </div>
            {% endfor %}
        </div>
    </section>
    {% endif %}

    {# ── New request form ──────────────────────────────────────────── #}
    <section class="rounded-xl border border-slate-200 dark:border-slate-700 p-6">
        <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Request a migration</h2>

        <div x-show="error" x-cloak class="mt-4 rounded-lg bg-red-50 dark:bg-red-500/10 px-4 py-3 text-sm text-red-700 dark:text-red-400" x-text="error"></div>
        <div x-show="success" x-cloak class="mt-4 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-400" x-text="success"></div>

        <form @submit.prevent="submit()" class="mt-5 grid gap-5 sm:grid-cols-2">

            <label class="block sm:col-span-2">
                <span class="text-xs font-medium text-slate-600 dark:text-slate-300">Which service is this for?</span>
                <select x-model="form.client_order_id"
                        class="mt-1.5 w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm">
                    <option value="">Not sure / not listed</option>
                    {% for order in client.order_get_list({'per_page': 100}).list %}
                    <option value="{{ order.id }}">#{{ order.id }} — {{ order.title }}</option>
                    {% endfor %}
                </select>
            </label>

            <label class="block">
                <span class="text-xs font-medium text-slate-600 dark:text-slate-300">Control panel at your old host</span>
                <select x-model="form.panel_type" required
                        class="mt-1.5 w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm">
                    <option value="cpanel_whm">cPanel / WHM</option>
                    <option value="plesk">Plesk</option>
                    <option value="directadmin">DirectAdmin</option>
                    <option value="ssh_custom">SSH / Custom</option>
                    <option value="other">Other</option>
                </select>
            </label>

            <div class="grid grid-cols-3 gap-3">
                <label class="block col-span-2">
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">Host or IP</span>
                    <input type="text" x-model="form.host" required placeholder="old-host.example.com"
                           class="mt-1.5 w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm">
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">Port</span>
                    <input type="text" x-model="form.port" inputmode="numeric" placeholder="2083"
                           class="mt-1.5 w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm">
                </label>
            </div>

            <label class="block">
                <span class="text-xs font-medium text-slate-600 dark:text-slate-300">Username</span>
                <input type="text" x-model="form.username" required autocomplete="off"
                       class="mt-1.5 w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm">
            </label>

            <label class="block">
                <span class="text-xs font-medium text-slate-600 dark:text-slate-300">Password</span>
                <input type="password" x-model="form.password" autocomplete="new-password"
                       class="mt-1.5 w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm">
            </label>

            <div class="sm:col-span-2">
                <button type="button" @click="showKey = !showKey"
                        class="text-xs font-medium text-primary hover:underline">
                    <span x-text="showKey ? 'Hide SSH key field' : 'Use an SSH key instead'"></span>
                </button>
                <label class="block mt-2" x-show="showKey" x-cloak>
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">SSH private key</span>
                    <textarea x-model="form.ssh_key" rows="4" spellcheck="false"
                              class="mt-1.5 w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm font-mono"></textarea>
                </label>
            </div>

            <label class="block sm:col-span-2">
                <span class="text-xs font-medium text-slate-600 dark:text-slate-300">Anything we should know?</span>
                <textarea x-model="form.client_notes" rows="3" placeholder="e.g. WordPress site, the database user is separate"
                          class="mt-1.5 w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm"></textarea>
            </label>

            <div class="sm:col-span-2 flex items-center justify-between gap-4 pt-2">
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Your credentials are encrypted before they are stored and are only readable by our migration staff.
                    We recommend changing this password at your old host once the migration is complete.
                </p>
                <button type="submit" :disabled="submitting"
                        class="flex-shrink-0 px-5 py-2.5 rounded-lg text-sm font-semibold text-white disabled:opacity-50"
                        style="background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));">
                    <span x-text="submitting ? 'Sending…' : 'Send to migration team'"></span>
                </button>
            </div>
        </form>
    </section>
</div>
{% endblock %}
```

- [ ] **Step 2: Add the sidebar nav link**

In `src/themes/meroserver/html/layout_default.html.twig`, find the "My Services" link block (starts at the `{# ===== My Services (simple link) ===== #}` comment, around line 349) and insert this block immediately **after** that link's closing `</a>`:

```twig
            {# ===== Migration Center (module-gated) ===== #}
            {% if client and guest.extension_is_on({'mod': 'migrationcenter'}) %}
            <a href="{{ '/migrationcenter'|url }}" title="Migration Center"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-150
                      {% if 'migrationcenter' in request.uri %}bg-violet-50 text-primary border-l-[3px] border-l-primary rounded-r-md rounded-tl-none rounded-bl-none{% else %}text-slate-500 hover:bg-violet-50 hover:text-primary{% endif %}"
               :class="sidebarCollapsed ? 'justify-center px-2' : ''">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                </svg>
                <span :class="sidebarCollapsed ? 'opacity-0 w-0 overflow-hidden' : 'opacity-100'" class="transition-all duration-200 whitespace-nowrap">Migration Center</span>
            </a>
            {% endif %}
```

- [ ] **Step 3: Add the per-service entry point**

In `src/themes/meroserver/html/mod_servicehosting_manage.html.twig`, inside the button row that begins `<div class="flex flex-wrap items-center gap-2 flex-shrink-0">` (around line 41), insert this block immediately **before** the `{% if order.unpaid_invoice_id|default(false) %}` block:

```twig
      {% if guest.extension_is_on({'mod': 'migrationcenter'}) %}
      <a href="{{ '/migrationcenter'|url({'order_id': order.id}) }}"
         class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg border border-slate-200 text-sm font-medium text-slate-600 hover:bg-slate-50 hover:border-slate-300 transition-all hm-btn-outline">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
        Migrate from another host
      </a>
      {% endif %}
```

- [ ] **Step 4: Rebuild assets and clear the template cache**

```bash
cd FOSSBilling
npm run build-meroserver
find src/data/cache -name "*.php" -delete
```
Expected: the build completes in roughly 2 seconds with no errors.

- [ ] **Step 5: Verify the page in a browser**

Log in as `pramodyadav826@gmail.com` and open `http://localhost:9000/migrationcenter`.

Expected:
- The "Migration Center" link appears in the sidebar under "My Services".
- The form renders, with the service dropdown populated from the client's orders.
- Submitting a valid form shows the green success message, then the page reloads and the request appears in "Your migration requests" with a "Submitted" badge and a "Withdraw" button.
- "Withdraw" moves it to "Cancelled" and the button disappears.
- Toggle dark mode — the page stays legible in both themes.

Then open an active hosting service at `/order/service/manage/<id>`:
- A "Migrate from another host" button appears in the header button row.
- Clicking it lands on `/migrationcenter?order_id=<id>` with that service **pre-selected** in the "Which service is this for?" dropdown.

- [ ] **Step 6: Commit**

```bash
git add src/modules/Migrationcenter src/themes/meroserver
git commit -m "feat(migrationcenter): add client portal page, sidebar link and service entry point"
```

---

### Task 9: Admin queue template

**Files:**
- Create: `src/modules/Migrationcenter/templates/admin/mod_migrationcenter_index.html.twig`

**Interfaces:**
- Consumes: `admin/migrationcenter/get_list`, `get`, `reveal_secret`, `update_status`, `assign`, `update_notes`, `purge_secret` (Task 7).
- Produces: the admin page at `/migrationcenter`; nothing consumes it.

The admin theme is `admin_default` (Tabler/Bootstrap 5). Icons use the sprite convention `<svg class="icon"><use xlink:href="#icon-name" /></svg>`.

**Permission dependency (deployment note):** the assignment dropdown is populated by `admin.staff_get_pairs`, and `staff/get_pairs` enforces `checkPermissions('staff', 'view')`. A staff role granted `migrationcenter.view_migrations` but **not** `staff.view` will fail to render this page. Grant migration staff `staff → view` as well, and record it in the CLAUDE.md section added in Task 11.

- [ ] **Step 1: Write the admin template**

Create `src/modules/Migrationcenter/templates/admin/mod_migrationcenter_index.html.twig`:

```twig
{% extends 'layout_default.html.twig' %}

{% block meta_title %}Migration Center{% endblock %}

{% block content %}
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Migration requests</h3>
        <div class="card-actions">
            <div class="btn-list">
                {% for status in ['', 'submitted', 'in_progress', 'completed', 'failed', 'cancelled'] %}
                <a href="{{ '/migrationcenter'|url({'status': status}) }}"
                   class="btn btn-sm {% if request.status|default('') == status %}btn-primary{% endif %}">
                    {{ status ? status|replace({'_': ' '})|title : 'All' }}
                </a>
                {% endfor %}
            </div>
        </div>
    </div>

    {% set requests = admin.migrationcenter_get_list({'status': request.status|default('')}) %}
    {% set staff_pairs = admin.staff_get_pairs %}

    <div class="table-responsive">
        <table class="table card-table table-vcenter">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Client</th>
                    <th>Previous host</th>
                    <th>Panel</th>
                    <th>Status</th>
                    <th>Assigned</th>
                    <th>Submitted</th>
                    <th class="w-1"></th>
                </tr>
            </thead>
            <tbody>
                {% for r in requests %}
                <tr>
                    <td>{{ r.id }}</td>
                    <td>
                        {% set c = admin.client_get({'id': r.client_id}) %}
                        <a href="{{ '/client/manage'|alink }}/{{ r.client_id }}">{{ c.first_name }} {{ c.last_name }}</a>
                    </td>
                    <td><code>{{ r.username }}@{{ r.host }}{% if r.port %}:{{ r.port }}{% endif %}</code></td>
                    <td>{{ r.panel_type_label }}</td>
                    <td>
                        <span class="badge
                            {% if r.status == 'completed' %}bg-green
                            {% elseif r.status == 'failed' %}bg-red
                            {% elseif r.status == 'in_progress' %}bg-yellow
                            {% elseif r.status == 'cancelled' %}bg-secondary
                            {% else %}bg-blue{% endif %}">
                            {{ r.status|replace({'_': ' '})|title }}
                        </span>
                    </td>
                    <td class="text-muted">
                        {% if r.assigned_staff_id and staff_pairs[r.assigned_staff_id] is defined %}
                            {{ staff_pairs[r.assigned_staff_id] }}
                        {% else %}
                            <span class="text-secondary">Unassigned</span>
                        {% endif %}
                    </td>
                    <td class="text-muted">{{ r.created_at|format_date }}</td>
                    <td>
                        <button class="btn btn-sm" onclick="mcOpen({{ r.id }})">Open</button>
                    </td>
                </tr>
                {% else %}
                <tr><td colspan="8" class="text-center text-muted py-4">No migration requests yet.</td></tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
</div>

{# ── Detail modal ─────────────────────────────────────────────────── #}
<div class="modal" id="mc-modal" tabindex="-1">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Migration request <span id="mc-id"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Previous host</label>
            <input class="form-control" id="mc-host" readonly>
          </div>
          <div class="col-md-6">
            <label class="form-label">Username</label>
            <input class="form-control" id="mc-username" readonly>
          </div>
          <div class="col-md-6">
            <label class="form-label">Password</label>
            <input class="form-control font-monospace" id="mc-password" readonly>
          </div>
          <div class="col-md-6">
            <label class="form-label">Status</label>
            <select class="form-select" id="mc-status">
              <option value="submitted">Submitted</option>
              <option value="in_progress">In progress</option>
              <option value="completed">Completed</option>
              <option value="failed">Failed</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Assigned to</label>
            <select class="form-select" id="mc-assignee">
              <option value="0">Unassigned</option>
              {% for id, name in staff_pairs %}
              <option value="{{ id }}">{{ name }}</option>
              {% endfor %}
            </select>
          </div>
          <div class="col-md-6"></div>
          <div class="col-12" id="mc-sshkey-wrap" style="display:none">
            <label class="form-label">SSH key</label>
            <textarea class="form-control font-monospace" id="mc-sshkey" rows="4" readonly></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">Client notes</label>
            <textarea class="form-control" id="mc-client-notes" rows="2" readonly></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">Internal staff notes</label>
            <textarea class="form-control" id="mc-staff-notes" rows="3"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-danger me-auto" id="mc-purge" onclick="mcPurge()">Purge credentials</button>
        <button class="btn" data-bs-dismiss="modal">Close</button>
        <button class="btn btn-primary" onclick="mcSave()">Save changes</button>
      </div>
    </div>
  </div>
</div>
{% endblock %}

{% block js %}
<script>
let mcCurrentId = null;

function mcOpen(id) {
    mcCurrentId = id;
    document.getElementById('mc-id').textContent = '#' + id;
    document.getElementById('mc-password').value = '';
    document.getElementById('mc-sshkey').value = '';
    document.getElementById('mc-sshkey-wrap').style.display = 'none';

    FOSSBilling.api.admin.post('migrationcenter/get', { id: id }, function (r) {
        document.getElementById('mc-host').value = r.host + (r.port ? ':' + r.port : '');
        document.getElementById('mc-username').value = r.username;
        document.getElementById('mc-status').value = r.status;
        document.getElementById('mc-assignee').value = r.assigned_staff_id || 0;
        document.getElementById('mc-client-notes').value = r.client_notes || '';
        document.getElementById('mc-staff-notes').value = r.staff_notes || '';
        document.getElementById('mc-purge').style.display = r.can_purge_secret ? '' : 'none';

        if (r.has_secret) {
            // Only staff holding manage_migrations get past this call; a
            // view_migrations-only account simply sees the fields stay empty.
            FOSSBilling.api.admin.post('migrationcenter/reveal_secret', { id: id }, function (s) {
                document.getElementById('mc-password').value = s.password;
                if (s.ssh_key) {
                    document.getElementById('mc-sshkey').value = s.ssh_key;
                    document.getElementById('mc-sshkey-wrap').style.display = '';
                }
            }, function () {
                document.getElementById('mc-password').placeholder = 'You do not have permission to view credentials';
            });
        } else {
            document.getElementById('mc-password').placeholder = r.secret_purged_at ? 'Purged on ' + r.secret_purged_at : 'No credentials stored';
        }

        new bootstrap.Modal(document.getElementById('mc-modal')).show();
    });
}

function mcSave() {
    const id = mcCurrentId;
    const status = document.getElementById('mc-status').value;
    const notes = document.getElementById('mc-staff-notes').value;
    const staffId = parseInt(document.getElementById('mc-assignee').value, 10) || 0;

    // Chained rather than parallel so a rejected status transition surfaces
    // its error instead of racing a page reload.
    FOSSBilling.api.admin.post('migrationcenter/update_notes', { id: id, staff_notes: notes }, function () {
        FOSSBilling.api.admin.post('migrationcenter/assign', { id: id, staff_id: staffId }, function () {
            FOSSBilling.api.admin.post('migrationcenter/update_status', { id: id, status: status }, function () {
                window.location.reload();
            });
        });
    });
}

function mcPurge() {
    if (!confirm('Permanently erase the stored credentials for this request? This cannot be undone.')) { return; }
    FOSSBilling.api.admin.post('migrationcenter/purge_secret', { id: mcCurrentId }, function () {
        window.location.reload();
    });
}
</script>
{% endblock %}
```

- [ ] **Step 2: Clear the template cache**

```bash
find FOSSBilling/src/data/cache -name "*.php" -delete
```

- [ ] **Step 3: Verify the admin page in a browser**

Open `http://localhost:9000/bb-admin/migrationcenter` (logged in as admin).

Expected:
- "Migration Center" appears in the admin sidebar under the Support group.
- The queue table lists the requests created in earlier tasks, with client name, host, panel and status badge.
- The status filter buttons narrow the list.
- "Open" shows the modal with the host, username and the **decrypted** password auto-filled.
- The "Assigned to" dropdown lists staff; picking one and saving shows that name in the queue's Assigned column.
- Changing the status and clicking "Save changes" persists the status, the assignee and the staff notes.
- On a completed/failed/cancelled request, "Purge credentials" is visible; on a submitted one it is hidden.

- [ ] **Step 4: Commit**

```bash
git add src/modules/Migrationcenter
git commit -m "feat(migrationcenter): add admin queue and request detail modal"
```

---

### Task 10: Email notifications

**Files:**
- Create: `src/modules/Migrationcenter/templates/email/mod_migrationcenter_request_received.html.twig`
- Create: `src/modules/Migrationcenter/templates/email/mod_migrationcenter_staff_new_request.html.twig`
- Create: `src/modules/Migrationcenter/templates/email/mod_migrationcenter_status_changed.html.twig`
- Modify: `src/modules/Migrationcenter/Service.php` (send from `createRequest()` and `updateStatus()`)

**Interfaces:**
- Consumes: `$di['mod_service']('email')->sendTemplate(array)` with keys `to_client` (int) or `to_staff` (true), `code` (string), plus arbitrary template variables.
- Produces: three email templates, auto-registered by code on first send.

**Rule:** no email may contain the password or SSH key. The staff email carries only identifiers and a link into the admin queue.

- [ ] **Step 1: Write the client confirmation email**

Create `src/modules/Migrationcenter/templates/email/mod_migrationcenter_request_received.html.twig`:

```twig
{% block subject %}[{{ guest.system_company.name }}] We received your migration request{% endblock %}
{% block content %}
<p>Hi {{ c.first_name }},</p>

<p>
    We have received your request to migrate from <strong>{{ request.host }}</strong> and our
    migration team has been notified. We will start work shortly and email you again when the
    status changes.
</p>

<p>
    <strong>Request:</strong> #{{ request.id }}<br>
    <strong>Previous host:</strong> {{ request.username }}@{{ request.host }}<br>
    <strong>Control panel:</strong> {{ request.panel_type_label }}
</p>

<p>
    For your security we never include the credentials you sent us in email. You can review the
    status of this request at any time from the Migration Center in your client area.
</p>

<p>
    Once the migration is complete we recommend changing the password at your previous host.
</p>

<p>— {{ guest.system_company.name }}</p>
{% endblock %}
```

- [ ] **Step 2: Write the staff notification email**

Create `src/modules/Migrationcenter/templates/email/mod_migrationcenter_staff_new_request.html.twig`:

```twig
{% block subject %}[{{ guest.system_company.name }}] New migration request #{{ request.id }}{% endblock %}
{% block content %}
<p>A client has submitted a new migration request.</p>

<p>
    <strong>Request:</strong> #{{ request.id }}<br>
    <strong>Client:</strong> {{ client_name }} (#{{ request.client_id }})<br>
    <strong>Previous host:</strong> {{ request.username }}@{{ request.host }}{% if request.port %}:{{ request.port }}{% endif %}<br>
    <strong>Control panel:</strong> {{ request.panel_type_label }}
</p>

{% if request.client_notes %}
<p><strong>Client notes:</strong><br>{{ request.client_notes }}</p>
{% endif %}

<p>
    The credentials are stored encrypted and are visible in the Migration Center queue in the
    admin area. They are deliberately not included in this email.
</p>
{% endblock %}
```

- [ ] **Step 3: Write the status change email**

Create `src/modules/Migrationcenter/templates/email/mod_migrationcenter_status_changed.html.twig`:

```twig
{% block subject %}[{{ guest.system_company.name }}] Migration request #{{ request.id }} is now {{ request.status|replace({'_': ' '}) }}{% endblock %}
{% block content %}
<p>Hi {{ c.first_name }},</p>

<p>
    The status of your migration from <strong>{{ request.host }}</strong> has changed to
    <strong>{{ request.status|replace({'_': ' '})|title }}</strong>.
</p>

{% if request.status == 'completed' %}
<p>
    Your migration is complete. Please check your site and let us know if anything looks wrong.
    We recommend changing the password at your previous host now.
</p>
{% elseif request.status == 'failed' %}
<p>
    We were not able to complete this migration. Our team will be in touch with the details, or
    you can open a support ticket and we will pick it up from there.
</p>
{% endif %}

<p>— {{ guest.system_company.name }}</p>
{% endblock %}
```

- [ ] **Step 4: Wire the notifications into the service**

In `src/modules/Migrationcenter/Service.php`, add this private method just above `private function repository()`:

```php
    /**
     * Email sending must never break the operation that triggered it, and no
     * email ever carries the submitted credentials.
     */
    private function sendEmail(array $payload): void
    {
        try {
            $this->di['mod_service']('email')->sendTemplate($payload);
        } catch (\Exception $e) {
            $this->di['logger']->setChannel('email')->error('Failed to send migration center email', [
                'code' => $payload['code'] ?? '',
                'exception' => $e->getMessage(),
            ]);
        }
    }
```

In `createRequest()`, replace the closing `return $id;` with:

```php
        $apiArray = $request->toApiArray();
        $client = $this->di['mod_service']('client')->get(['id' => $clientId]);

        $this->sendEmail([
            'to_client' => $clientId,
            'code' => 'mod_migrationcenter_request_received',
            'request' => $apiArray,
        ]);

        $this->sendEmail([
            'to_staff' => true,
            'code' => 'mod_migrationcenter_staff_new_request',
            'request' => $apiArray,
            'client_name' => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')),
        ]);

        return $id;
```

In `updateStatus()`, replace the closing `return true;` with:

```php
        $this->sendEmail([
            'to_client' => $request->getClientId(),
            'code' => 'mod_migrationcenter_status_changed',
            'request' => $request->toApiArray(),
        ]);

        return true;
```

- [ ] **Step 5: Verify the emails render and carry no secret**

Submit a fresh request as the test client (browser console, as in Task 6 Step 4), then:

```bash
curl -s -X POST "http://localhost:9000/api/admin/email/email_get_list" \
  --user "admin:3c8btj3fNL8Vu7V5ieXGEiZ7pbk6LMqJ" -d "per_page=5" | head -c 2000
```
Expected: the two new emails appear (client confirmation + staff notification), and neither body contains the submitted password.

```bash
mysql -h 127.0.0.1 -u merovps -pmerovps merovps \
  -e "SELECT COUNT(*) AS leaked FROM activity_client_email WHERE content_html LIKE '%sup3rsecret%' OR content_text LIKE '%sup3rsecret%';"
```
Expected: `leaked` is `0`.

- [ ] **Step 6: Format and commit**

```bash
composer cs:fix -- src/modules/Migrationcenter
git add src/modules/Migrationcenter
git commit -m "feat(migrationcenter): notify client and staff on submission and status change"
```

---

### Task 11: Automation adapter reservation, docs, and full verification

**Files:**
- Create: `src/modules/Migrationcenter/Adapter/MigrationAdapterInterface.php`
- Create: `src/modules/Migrationcenter/Adapter/MigrationSnapshot.php`
- Create: `src/modules/Migrationcenter/Adapter/ManualAdapter.php`
- Modify: `CLAUDE.md` (repo root — add the RateLimiter patch row and a Migration Center section)

**Interfaces:**
- Consumes: nothing.
- Produces: `MigrationAdapterInterface` with `connect(array $credentials): void`, `snapshot(): MigrationSnapshot`, `transfer(MigrationSnapshot $snapshot): void`, `verify(): bool`; `ManualAdapter` implementing it by throwing.

**Why it throws rather than no-ops:** a silent no-op would let a future "Run transfer" button appear to succeed while doing nothing. Throwing makes the unimplemented path impossible to mistake for a working one. Nothing in v1 calls the adapter — `method` is always `manual`.

- [ ] **Step 1: Write the adapter interface and snapshot**

Create `src/modules/Migrationcenter/Adapter/MigrationSnapshot.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration Center — description of what was found on a previous host.
 *
 * Reserved for the automated-transfer work (v2). Its shape is deliberately
 * left to that design pass; today it exists only so the adapter interface
 * has a return type to name.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Adapter;

final class MigrationSnapshot
{
}
```

Create `src/modules/Migrationcenter/Adapter/MigrationAdapterInterface.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration Center — contract for an automated migration backend.
 *
 * Reserved for v2. Version 1 of this module is entirely manual: staff read
 * the submitted credentials out of the admin queue and migrate by hand, and
 * every request carries method = 'manual'. A future adapter (for example a
 * cPanel-to-cPanel transfer) implements this interface, and requests opt in
 * by setting method = 'automated' — without changing the table, the client
 * form, or the staff queue.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Adapter;

interface MigrationAdapterInterface
{
    /**
     * Authenticate against the previous host.
     *
     * @param array{password: string, ssh_key: string} $credentials
     *
     * @throws \FOSSBilling\Exception on authentication failure
     */
    public function connect(array $credentials): void;

    /**
     * Enumerate what exists on the previous host.
     */
    public function snapshot(): MigrationSnapshot;

    /**
     * Pull the snapshot's contents onto this server.
     */
    public function transfer(MigrationSnapshot $snapshot): void;

    /**
     * Post-transfer sanity check.
     */
    public function verify(): bool;
}
```

Create `src/modules/Migrationcenter/Adapter/ManualAdapter.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration Center — the only adapter shipped in v1.
 *
 * Every method throws. Migration Center v1 is staff-operated: nothing in the
 * module calls an adapter, and this class exists so the v2 interface has a
 * concrete implementation without pretending that automation works.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Migrationcenter\Adapter;

final class ManualAdapter implements MigrationAdapterInterface
{
    private const string MESSAGE = 'Migrations are performed manually by support staff. Automated transfer is not implemented.';

    public function connect(array $credentials): void
    {
        throw new \FOSSBilling\InformationException(self::MESSAGE);
    }

    public function snapshot(): MigrationSnapshot
    {
        throw new \FOSSBilling\InformationException(self::MESSAGE);
    }

    public function transfer(MigrationSnapshot $snapshot): void
    {
        throw new \FOSSBilling\InformationException(self::MESSAGE);
    }

    public function verify(): bool
    {
        throw new \FOSSBilling\InformationException(self::MESSAGE);
    }
}
```

- [ ] **Step 2: Document the core-file patch in CLAUDE.md**

In `/Users/promod/devtools/meropanel/CLAUDE.md`, in the "Local Core Patches" table, find the existing `src/library/FOSSBilling/Security/RateLimiter.php` row and append this sentence to the end of its Patch cell:

```
**Migration Center policies added 2026-09-12:** `migrationcenter_request_ip` (10/h) + `migrationcenter_request_client` (5/h) throttle the client-facing migration credential intake form — each submission stores third-party credentials and emails staff, so an unbounded form is both a spam vector and a way to bulk-probe order ids. Re-add after upgrades.
```

- [ ] **Step 3: Document the module in CLAUDE.md**

In `/Users/promod/devtools/meropanel/CLAUDE.md`, add this section immediately before the "## Checkout Gotcha" heading:

```markdown
## Migration Center module

`src/modules/Migrationcenter/` — client-facing intake for a **previous host's** credentials plus a staff queue to work the migration. Not to be confused with `Whmcsmigration`, which is an admin-only importer for WHMCS *database records*.

- Table `mod_migrationcenter_request` (created by `Service::install()`, Doctrine entity + repository).
- Secrets: password + SSH key bundled into one JSON blob, encrypted with `$di['crypt']` (`Box_Crypt`) via `Support/SecretCodec.php`. **`toApiArray()` and `toAdminApiArray()` must never carry the secret** — `admin/migrationcenter/reveal_secret` is the single decryption endpoint and requires `manage_migrations`.
- Permissions: `view_migrations` (queue + metadata) vs `manage_migrations` (credentials, status, assignment, purge). Every `Api/Admin.php` method calls `checkPermissions()` explicitly — module-level access must not imply secret access. `tests/Unit/AdminApiPermissionsTest.php` structurally enforces this after every change. **Migration staff also need `staff → view`**, because the admin page's assignment dropdown calls `staff/get_pairs`.
- Status workflow lives in `Support/RequestStatus.php`; `submitted` is the only client-cancellable state, and credentials can only be purged from `completed`/`failed`/`cancelled`.
- Client page `/migrationcenter` (meroserver template in the module), sidebar link in `layout_default.html.twig` guarded by `guest.extension_is_on({'mod': 'migrationcenter'})`.
- `Adapter/MigrationAdapterInterface.php` is a **v2 reservation**; `ManualAdapter` throws on every method and nothing calls it. `method` is always `'manual'` in v1.
- Design spec: `FOSSBilling/docs/superpowers/specs/2026-09-12-migrationcenter-design.md`.
```

- [ ] **Step 4: Run the full unit suite**

```bash
cd FOSSBilling
composer test
```
Expected: all Migration Center tests pass. The 3 pre-existing failures documented in `CLAUDE.md` (2 in `ExceptionResponseFactoryTest`, plus `StrictVariablesTest` findings) may still fail — those are known and unrelated. **No new failures.**

- [ ] **Step 5: Run static analysis and formatting**

```bash
composer phpstan 2>&1 | grep -i migrationcenter
composer cs:check 2>&1 | grep -i migrationcenter
```
Expected: no output from either (no findings in the new module).

- [ ] **Step 6: Run the smoke test**

```bash
cd FOSSBilling
node .claude/skills/run-meropanel/driver.mjs
```
Expected: 11/11 checks pass. Screenshots land in `/tmp/meropanel-shots/`.

- [ ] **Step 7: Final end-to-end verification**

Confirm, in one pass:
1. As the test client: submit a request at `/migrationcenter` → success message, request appears as "Submitted".
2. As admin at `/bb-admin/migrationcenter`: the request is in the queue; "Open" reveals the correct password.
3. Move it to "In progress", save → the client's page shows "In progress"; a status-change email was queued.
4. Move it to "Completed" → "Purge credentials" becomes available; purge it.
5. Re-open → password field shows "Purged on …".
6. Confirm no plaintext anywhere:
```bash
mysql -h 127.0.0.1 -u merovps -pmerovps merovps \
  -e "SELECT COUNT(*) AS leaked FROM mod_migrationcenter_request WHERE secret_encrypted LIKE '%sup3rsecret%';"
grep -ri "sup3rsecret" FOSSBilling/src/data/log/ 2>/dev/null | head
```
Expected: `leaked` is `0` and the log grep returns nothing.

- [ ] **Step 8: Commit**

```bash
git add src/modules/Migrationcenter
git commit -m "feat(migrationcenter): reserve automation adapter interface"
cd /Users/promod/devtools/meropanel
git -C FOSSBilling add -A
git -C FOSSBilling commit -m "docs(migrationcenter): document module and rate limiter patch"
```

*(Note: `CLAUDE.md` lives in `meropanel/`, which is not a git repository — edit it in place; only the `FOSSBilling/` changes get committed.)*

---

## Post-implementation notes

Things deliberately left for a future pass, all recorded in the spec:

- **Automated transfer (v2)** — needs its own brainstorm/spec cycle. The interface and `method` column are the only groundwork here.
- **Credential TTL / auto-expiry** — the approved encryption decision was the simple app-level model with a *manual* purge. If the threat model tightens later, adding a TTL is a cron job plus one query over `secret_purged_at IS NULL`.
- **Audit logging of credential reveals** — explicitly not selected during brainstorming. If wanted later, `Api/Admin::reveal_secret()` is the single chokepoint.
