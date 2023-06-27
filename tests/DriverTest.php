<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Tests;

use Doctrine\DBAL;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\API\PostgreSQL\ExceptionConverter as PostgreSQLExceptionConverter;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Driver\PDO;
use DoctrineCockroachDB\Driver\CockroachDBDriver;
use DoctrineCockroachDB\Platforms\CockroachDBPlatform;
use DoctrineCockroachDB\Schema\CockroachDBSchemaManager;
use PHPUnit\Framework\MockObject\Exception as MockException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * @requires extension pdo_pgsql
 */
class DriverTest extends TestCase
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

    protected Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::isCockroachDBDriver()) {
            static::markTestSkipped('Test enabled only when using the CockroachDB driver');
        }

        $this->driver = $this->createDriver();
    }

    /**
     * @return array<string,mixed>
     */
    private static function getConnectionParameters(): array
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

    private static function isCockroachDBDriver(): bool
    {
        return 'DoctrineCockroachDB\Driver\CockroachDBDriver' === self::getConnectionParameters()['driver_class'];
    }

    public function testConnectionDisablesPrepares(): void
    {
        $connection = $this->connect();

        self::assertInstanceOf(PDO\Connection::class, $connection);
        self::assertTrue(
            $connection->getNativeConnection()->getAttribute(\PDO::PGSQL_ATTR_DISABLE_PREPARES),
        );
    }

    public function testConnectionDoesNotDisablePreparesWhenAttributeDefined(): void
    {
        $connection = $this->connect(
            [\PDO::PGSQL_ATTR_DISABLE_PREPARES => false],
        );

        self::assertInstanceOf(PDO\Connection::class, $connection);
        self::assertNotTrue(
            $connection->getNativeConnection()->getAttribute(\PDO::PGSQL_ATTR_DISABLE_PREPARES),
        );
    }

    public function testReturnsDatabasePlatform(): void
    {
        self::assertEquals($this->createPlatform(), $this->driver->getDatabasePlatform());
    }

    /**
     * @return DBAL\Connection&MockObject
     * @throws MockException
     */
    protected function getConnectionMock(): DBAL\Connection
    {
        return $this->createMock(DBAL\Connection::class);
    }

    public function testReturnsSchemaManager(): void
    {
        $connection = $this->getConnectionMock();
        $schemaManager = $this->driver->getSchemaManager(
            conn: $connection,
            platform: $this->createPlatform(),
        );

        self::assertEquals($this->createSchemaManager($connection), $schemaManager);

        $re = new ReflectionProperty($schemaManager, '_conn');
        $re->setAccessible(true);

        self::assertSame($connection, $re->getValue($schemaManager));
    }

    public function testReturnsExceptionConverter(): void
    {
        self::assertEquals($this->createExceptionConverter(), $this->driver->getExceptionConverter());
    }

    protected function createDriver(): DBAL\Driver
    {
        return new CockroachDBDriver();
    }

    protected function createPlatform(): CockroachDBPlatform
    {
        return new CockroachDBPlatform();
    }

    protected function createSchemaManager(DBAL\Connection $connection): CockroachDBSchemaManager
    {
        return new CockroachDBSchemaManager(
            $connection,
            $this->createPlatform(),
        );
    }

    protected function createExceptionConverter(): ExceptionConverter
    {
        return new PostgreSQLExceptionConverter();
    }

    /**
     * @param array<int,mixed> $driverOptions
     * @returns Connection
     * @throws Exception
     */
    private function connect(array $driverOptions = []): Connection
    {
        return $this->createDriver()->connect(
            array_merge(
                self::getConnectionParameters(),
                ['driverOptions' => $driverOptions],
            ),
        );
    }
}
