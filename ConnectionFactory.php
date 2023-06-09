<?php

declare(strict_types=1);

namespace DoctrineCockroachDB;

use DoctrineCockroachDB\Driver\CockroachDBDriver;
use Doctrine\Bundle\DoctrineBundle;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Tools\DsnParser;

class ConnectionFactory
{
    private const CRDB_DRIVER_ALIASES = ['crdb', 'pdo-crdb'];
    private const DRIVER_SCHEME_ALIASES = [
        'db2' => 'ibm_db2',
        'mssql' => 'pdo_sqlsrv',
        'mysql' => 'pdo_mysql',
        'mysql2' => 'pdo_mysql', // Amazon RDS, for some weird reason
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'pgsql' => 'pdo_pgsql',
        'sqlite' => 'pdo_sqlite',
        'sqlite3' => 'pdo_sqlite',
    ];

    public function __construct(
        private DoctrineBundle\ConnectionFactory $decorated,
    ) {
        // just for constructor property promotion
    }

    public function createConnection(
        array $params,
        ?Configuration $config = null,
        ?EventManager $eventManager = null,
        array $mappingTypes = [],
    ): Connection {
        $dsnParser = new DsnParser(self::DRIVER_SCHEME_ALIASES);

        if (!empty($params['url'])) {
            $params = array_replace($params, $dsnParser->parse($params['url']));
            unset($params['url']);
        }

        if (in_array($params['driver'], self::CRDB_DRIVER_ALIASES, true)) {
            $params['driver'] = 'pdo_pgsql';
            $params['driverClass'] = CockroachDBDriver::class;
        }

        return $this->decorated->createConnection($params, $config, $eventManager, $mappingTypes);
    }
}
