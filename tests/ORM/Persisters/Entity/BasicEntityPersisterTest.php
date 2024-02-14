<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Tests\ORM\Persisters\Entity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DoctrineDbalException;
use Doctrine\DBAL\Types\Types;
use DoctrineCockroachDB\ORM\Persisters\Entity\BasicEntityPersister;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use DoctrineCockroachDB\Tests\ConnectionHelper;
use DoctrineCockroachDB\Tests\ORM\EntityManagerMockTrait;
use DoctrineCockroachDB\Tests\ORM\TestEntityClassMetadataTrait;
use PHPUnit\Framework\TestCase;

use function array_merge;

/**
 * Tests {@link BasicEntityPersister}.
 */
final class BasicEntityPersisterTest extends TestCase
{
    use EntityManagerMockTrait;
    use TestEntityClassMetadataTrait;

    public function testGetInsertSQL(): void
    {
        $classMetadata = $this->getTestEntityClassMetadata();
        $entityManagerMock = $this->getEntityManagerMock();

        $entityPersister = new BasicEntityPersister(
            $entityManagerMock,
            $classMetadata,
        );
        self::assertSame(
            'INSERT INTO TestEntity (an_identifier) VALUES (DEFAULT) RETURNING an_identifier',
            $entityPersister->getInsertSQL(),
            'assert that empty columns work and that we return the first identifier',
        );

        $classMetadata->fieldMappings = array_merge(
            $classMetadata->fieldMappings,
            [
                'string' => ['type' => Types::STRING, 'fieldName' => 'string', 'columnName' => 'a_string_column'],
            ],
        );
        $classMetadata->wakeupReflection(new RuntimeReflectionService());
        $entityPersister = new BasicEntityPersister(
            $entityManagerMock,
            $classMetadata,
        );
        self::assertSame(
            'INSERT INTO TestEntity (a_string_column) VALUES (?) RETURNING an_identifier,second_identifier',
            $entityPersister->getInsertSQL(),
            'assert that only non-identifier columns are inserted into and that we return the identifiers',
        );
    }

    /**
     * @throws DoctrineDbalException
     */
    public function testExecuteInserts(): void
    {
        $classMetadata = $this->getTestEntityClassMetadata();
        $connectionHelper = new ConnectionHelper();
        $entityManagerMock = $this->getEntityManagerMock(
            new Connection($connectionHelper::getConnectionParameters(), $connectionHelper->createDriver()),
        );
        $classMetadata->fieldMappings = array_merge(
            $classMetadata->fieldMappings,
            [
                'string' => ['type' => Types::STRING, 'fieldName' => 'string', 'columnName' => 'a_string_column'],
            ],
        );
        $classMetadata->wakeupReflection(new RuntimeReflectionService());
        $entityManagerMock
            ->expects(self::atLeastOnce())
            ->method('getClassMetadata')
            ->with(TestEntity::class)
            ->willReturn($classMetadata);

        $entityPersister = new BasicEntityPersister(
            $entityManagerMock,
            $classMetadata,
        );
        $testEntity = new TestEntity('test-test');
        $entityPersister->addInsert($testEntity);
        $entityManagerMock->getUnitOfWork()->scheduleForInsert($testEntity);
        $entityManagerMock->getUnitOfWork()->computeChangeSets();

        self::assertNull($testEntity->getId(), 'we should have no ID before insertion');
        $entityPersister->executeInserts();
        self::assertIsInt($testEntity->getId(), 'we should have got ID automatically from database');
    }
}
