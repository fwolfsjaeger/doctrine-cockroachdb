<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Tests;

use Doctrine\DBAL;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Exception as DoctrineDriverException;
use Doctrine\DBAL\Driver\PDO;
use DoctrineCockroachDB\Driver\API\ExceptionConverter as CockroachDBExceptionConverter;
use DoctrineCockroachDB\Platforms\CockroachDBPlatform;
use PHPUnit\Framework\MockObject\Exception as MockException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

/**
 * @requires extension pdo_pgsql
 */
class DriverTest extends TestCase
{
    protected ConnectionHelper $connectionHelper;
    protected Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::isCockroachDBDriver()) {
            static::markTestSkipped('Test enabled only when using the CockroachDB driver');
        }

        $this->connectionHelper = new ConnectionHelper();
        $this->driver = $this->connectionHelper->createDriver();
    }

    private static function isCockroachDBDriver(): bool
    {
        return 'DoctrineCockroachDB\Driver\CockroachDBDriver' ===
            ConnectionHelper::getConnectionParameters()['driver_class'];
    }

    /**
     * @throws DoctrineDriverException
     */
    public function testConnectionDisablesPrepares(): void
    {
        $connection = $this->connectionHelper->connect();

        self::assertInstanceOf(PDO\Connection::class, $connection);
        self::assertTrue(
            $connection->getNativeConnection()->getAttribute(\PDO::PGSQL_ATTR_DISABLE_PREPARES),
        );
    }

    /**
     * @throws DoctrineDriverException
     */
    public function testConnectionDoesNotDisablePreparesWhenAttributeDefined(): void
    {
        $connection = $this->connectionHelper->connect(
            [\PDO::PGSQL_ATTR_DISABLE_PREPARES => false],
        );

        self::assertInstanceOf(PDO\Connection::class, $connection);
        self::assertNotTrue(
            $connection->getNativeConnection()->getAttribute(\PDO::PGSQL_ATTR_DISABLE_PREPARES),
        );
    }

    public function testReturnsDatabasePlatform(): void
    {
        $serverVersionProvider = new DBAL\Connection\StaticServerVersionProvider('1');
        self::assertEquals($this->createPlatform(), $this->driver->getDatabasePlatform($serverVersionProvider));
    }

    /**
     * @return DBAL\Connection&MockObject
     * @throws MockException
     */
    protected function getConnectionMock(): DBAL\Connection
    {
        return $this->createMock(DBAL\Connection::class);
    }

    public function testReturnsExceptionConverter(): void
    {
        self::assertEquals($this->createExceptionConverter(), $this->driver->getExceptionConverter());
    }

    protected function createPlatform(): CockroachDBPlatform
    {
        return new CockroachDBPlatform();
    }

    protected function createExceptionConverter(): ExceptionConverter
    {
        return new CockroachDBExceptionConverter();
    }
}
