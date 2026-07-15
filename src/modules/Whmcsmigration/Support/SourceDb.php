<?php

declare(strict_types=1);

/**
 * Read-only PDO connection to the source WHMCS database.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Whmcsmigration\Support;

class SourceDb
{
    private ?\PDO $pdo = null;

    public function __construct(private array $config)
    {
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['db_host']) && !empty($this->config['db_name']) && !empty($this->config['db_user']);
    }

    private function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if (!$this->isConfigured()) {
            throw new \FOSSBilling\InformationException('WHMCS database connection is not configured. Save the connection settings first.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config['db_host'],
            (int) ($this->config['db_port'] ?: 3306),
            $this->config['db_name'],
        );

        try {
            $this->pdo = new \PDO($dsn, $this->config['db_user'], $this->config['db_password'] ?? '', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 10,
            ]);
        } catch (\PDOException $e) {
            error_log('[whmcsmigration] source DB connection failed: ' . $e->getMessage());

            throw new \FOSSBilling\InformationException('Could not connect to the WHMCS database. Check host, credentials and that the DB user has SELECT access.');
        }

        return $this->pdo;
    }

    /**
     * @return array{ok: bool, whmcs_version: ?string, has_tblusers: bool}
     */
    public function testConnection(): array
    {
        $pdo = $this->pdo();

        $version = null;
        try {
            $stmt = $pdo->prepare("SELECT value FROM tblconfiguration WHERE setting = 'Version'");
            $stmt->execute();
            $version = $stmt->fetchColumn() ?: null;
        } catch (\PDOException) {
            throw new \FOSSBilling\InformationException('Connected, but this does not look like a WHMCS database (tblconfiguration missing).');
        }

        $hasUsers = (bool) $pdo->query("SHOW TABLES LIKE 'tblusers'")->fetchColumn();

        return [
            'ok' => true,
            'whmcs_version' => is_string($version) ? $version : null,
            'has_tblusers' => $hasUsers,
        ];
    }

    /**
     * Guard used by analyze(): only the WHMCS 8.x schema (tblusers split) is supported.
     */
    public function assertSupportedSchema(): void
    {
        $info = $this->testConnection();
        if (!$info['has_tblusers']) {
            throw new \FOSSBilling\InformationException('This WHMCS database uses the pre-8.0 schema (no tblusers table), which is not supported yet. Upgrade WHMCS to 8.x before migrating.');
        }
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function fetchBatch(string $sql, array $params, int $offset, int $limit): array
    {
        // LIMIT/OFFSET cannot be bound as regular params in emulated-prepare-off mode;
        // both values are forced int and inlined.
        $sql .= sprintf(' LIMIT %d OFFSET %d', max(1, $limit), max(0, $offset));

        return $this->fetchAll($sql, $params);
    }

    public function count(string $sql, array $params = []): int
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
