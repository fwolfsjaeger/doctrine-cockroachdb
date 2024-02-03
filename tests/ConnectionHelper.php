<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Tests;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Exception;
use DoctrineCockroachDB\Driver\CockroachDBDriver;

/**
 * Provides easy-to-use functions for connecting to CockroachDB during tests.
 */
final class ConnectionHelper
{
    private const PARAMETER_NAMES = [
        'driver',
        'driver_class',
        'user',
        'password',
        'host',
        'dbname',
        'memory',
        'port',
        'server',
        'ssl_key',
        'ssl_cert',
        'ssl_ca',
        'ssl_capath',
        'ssl_cipher',
        'unix_socket',
        'path',
        'charset',
    ];

    /**
     * @param array<int,mixed> $driverOptions
     *
     * @returns Connection
     * @throws Exception
     */
    public function connect(array $driverOptions = []): Connection
    {
        return $this->createDriver()->connect(
            array_merge(
                self::getConnectionParameters(),
                ['driverOptions' => $driverOptions],
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function getConnectionParameters(): array
    {
        $parameters = [];
        $prefix = 'db_';

        foreach (self::PARAMETER_NAMES as $parameter) {
            $prefixedParameter = $prefix . $parameter;

            if (!isset($GLOBALS[$prefixedParameter])) {
                continue;
            }

            $parameters[$parameter] = $GLOBALS[$prefixedParameter];
        }

        foreach ($GLOBALS as $param => $value) {
            if (!str_starts_with($param, $prefix . 'driver_option_')) {
                continue;
            }

            $length = strlen($prefix . 'driver_option_');
            $driverOption = substr($param, $length);
            $parameters['driverOptions'][$driverOption] = $value;
        }

        return $parameters;
    }

    public function createDriver(): Driver
    {
        return new CockroachDBDriver();
    }
}
