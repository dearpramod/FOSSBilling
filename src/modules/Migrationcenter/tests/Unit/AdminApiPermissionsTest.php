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
                ->toContain("\$this->checkPermissions('migrationcenter'");
        }
    });

    test('endpoints that read or change credentials require manage_migrations', function (): void {
        $privileged = ['reveal_secret', 'update_status', 'assign', 'update_notes', 'purge_secret'];

        foreach ($privileged as $method) {
            expect(migrationAdminMethodSource($method))
                ->toContain("'manage_migrations'");
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
                ->not->toContain('revealSecret');
        }
    });
});
