<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Tests\Schema;

use Doctrine\DBAL\DriverManager;
use DoctrineCockroachDB\Platforms\CockroachDBPlatform;
use DoctrineCockroachDB\Schema\CockroachDBSchemaManager;
use DoctrineCockroachDB\Tests\ConnectionHelper;
use PHPUnit\Framework\TestCase;

/**
 * Tests {@see CockroachDBSchemaManager}.
 */
final class CockroachDBSchemaManagerTest extends TestCase
{
    protected CockroachDBSchemaManager $cockroachDBSchemaManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cockroachDBSchemaManager = (new CockroachDBPlatform())
            ->createSchemaManager(DriverManager::getConnection(ConnectionHelper::getConnectionParameters()));
    }

    public function testListTableColumns(): void
    {
        $tables = $this->cockroachDBSchemaManager->listTables();

        self::assertCount(1, $tables, 'E2E test asserting that we don\'t crash when listing tables');
    }
}
