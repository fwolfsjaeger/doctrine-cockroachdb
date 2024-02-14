<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Tests;

use Doctrine\DBAL;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\API\PostgreSQL\ExceptionConverter as PostgreSQLExceptionConverter;
use Doctrine\DBAL\Driver\Exception as DoctrineDriverException;
use Doctrine\DBAL\Driver\PDO;
use DoctrineCockroachDB\Platforms\CockroachDBPlatform;
use DoctrineCockroachDB\Schema\CockroachDBSchemaManager;
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

    /**
     * @throws ReflectionException
     * @throws MockException
     */
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
}
