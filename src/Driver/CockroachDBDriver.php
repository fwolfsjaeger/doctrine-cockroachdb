<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Driver;

use Doctrine\DBAL;
use Doctrine\DBAL\Driver\PDO\Connection;
use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use DoctrineCockroachDB\Platforms\CockroachDBPlatform;
use DoctrineCockroachDB\Schema\CockroachDBSchemaManager;
use Doctrine\Deprecations\Deprecation;
use PDO;

class CockroachDBDriver extends AbstractPostgreSQLDriver
{
    public function connect(array $params): Connection
    {
        $driverOptions = $params['driverOptions'] ?? [];

        if (!empty($params['persistent'])) {
            $driverOptions[PDO::ATTR_PERSISTENT] = true;
        }

        // ensure the database name is set
        $params['dbname'] = $params['dbname'] ?? $params['default_dbname'] ?? 'defaultdb';

        $pdo = new PDO(
            $this->constructPdoDsn($params),
            $params['user'] ?? '',
            $params['password'] ?? '',
            $driverOptions,
        );

        if (
            !isset($driverOptions[PDO::PGSQL_ATTR_DISABLE_PREPARES])
            || $driverOptions[PDO::PGSQL_ATTR_DISABLE_PREPARES] === true
        ) {
            $pdo->setAttribute(PDO::PGSQL_ATTR_DISABLE_PREPARES, true);
        }

        $connection = new Connection($pdo);

        /*
         * define client_encoding via SET NAMES to avoid inconsistent DSN support
         * - passing client_encoding via the 'options' param breaks pgbouncer support
         */
        if (isset($params['charset'])) {
            $connection->exec('SET NAMES \'' . $params['charset'] . '\'');
        }

        return $connection;
    }

    /**
     * @param array<string, mixed> $params
     * @return string
     */
    private function constructPdoDsn(array $params): string
    {
        $dsn = 'pgsql:';

        if (isset($params['host']) && $params['host'] !== '') {
            $dsn .= 'host=' . $params['host'] . ';';
        }

        if (isset($params['port']) && $params['port'] !== '') {
            $dsn .= 'port=' . $params['port'] . ';';
        }

        $dsn .= 'dbname=' . $params['dbname'] . ';';

        if (isset($params['sslmode'])) {
            $dsn .= 'sslmode=' . $params['sslmode'] . ';';
        }

        if (isset($params['sslrootcert'])) {
            $dsn .= 'sslrootcert=' . $params['sslrootcert'] . ';';
        }

        if (isset($params['sslcert'])) {
            $dsn .= 'sslcert=' . $params['sslcert'] . ';';
        }

        if (isset($params['sslkey'])) {
            $dsn .= 'sslkey=' . $params['sslkey'] . ';';
        }

        if (isset($params['sslcrl'])) {
            $dsn .= 'sslcrl=' . $params['sslcrl'] . ';';
        }

        if (isset($params['application_name'])) {
            $dsn .= 'application_name=' . $params['application_name'] . ';';
        }

        return $dsn;
    }

    public function getDatabasePlatform(): CockroachDBPlatform
    {
        return new CockroachDBPlatform();
    }

    public function createDatabasePlatformForVersion($version): CockroachDBPlatform
    {
        return new CockroachDBPlatform();
    }

    /**
     * @deprecated
     * @link CockroachDBSchemaManager::createSchemaManager()
     */
    public function getSchemaManager(
        DBAL\Connection  $conn,
        AbstractPlatform $platform,
    ): CockroachDBSchemaManager {
        Deprecation::triggerIfCalledFromOutside(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/pull/5458',
            'CockroachDBPlatform::getSchemaManager() is deprecated.'
            . ' Use CockroachDBPlatform::createSchemaManager() instead.',
        );

        assert($platform instanceof CockroachDBPlatform);

        return new CockroachDBSchemaManager($conn, $platform);
    }
}
