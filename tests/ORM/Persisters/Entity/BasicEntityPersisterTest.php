<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Tests\ORM\Persisters\Entity;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use DoctrineCockroachDB\ORM\Id\SerialGenerator;
use DoctrineCockroachDB\Platforms\CockroachDBPlatform;
use DoctrineCockroachDB\Tests\ConnectionHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function array_merge;

/**
 * Tests {@link BasicEntityPersister}.
 */
final class BasicEntityPersisterTest extends TestCase
{
    public function testGetInsertSQL(): void
    {
        $classMetadata = $this->getTestClassMetadata();
        $entityManagerMock = $this->getEntityManagerMock();

        $entityPersister = new BasicEntityPersister(
            $entityManagerMock,
            $classMetadata
        );
        self::assertSame(
            'INSERT INTO TestEntity (an_identifier) VALUES (DEFAULT) RETURNING an_identifier',
            $entityPersister->getInsertSQL(),
            'assert that empty columns work and that we return the first identifier'
        );

        $classMetadata->fieldMappings = array_merge(
            $classMetadata->fieldMappings,
            [
                'string' => ['type' => Types::STRING, 'fieldName' => 'string', 'columnName' => 'a_string_column'],
            ]
        );
        $classMetadata->wakeupReflection(new RuntimeReflectionService());
        $entityPersister = new BasicEntityPersister(
            $entityManagerMock,
            $classMetadata
        );
        self::assertSame(
            'INSERT INTO TestEntity (a_string_column) VALUES (?) RETURNING an_identifier,second_identifier',
            $entityPersister->getInsertSQL(),
            'assert that only non-identifier columns are inserted into and that we return the identifiers'
        );
    }

    public function testExecuteInserts(): void
    {
        $classMetadata = $this->getTestClassMetadata();
        $connectionHelper = new ConnectionHelper();
        $entityManagerMock = $this->getEntityManagerMock(
            new Connection($connectionHelper::getConnectionParameters(), $connectionHelper->createDriver())
        );
        $classMetadata->fieldMappings = array_merge(
            $classMetadata->fieldMappings,
            [
                'string' => ['type' => Types::STRING, 'fieldName' => 'string', 'columnName' => 'a_string_column'],
            ]
        );
        $classMetadata->wakeupReflection(new RuntimeReflectionService());
        $entityManagerMock
            ->expects(self::atLeastOnce())
            ->method('getClassMetadata')
            ->with(TestEntity::class)
            ->willReturn($classMetadata);

        $entityPersister = new BasicEntityPersister(
            $entityManagerMock,
            $classMetadata
        );
        $testEntity = new TestEntity('test-test');
        $entityPersister->addInsert($testEntity);
        $entityManagerMock->getUnitOfWork()->scheduleForInsert($testEntity);
        $entityManagerMock->getUnitOfWork()->computeChangeSets();

        self::assertNull($testEntity->getId(), 'we should have no ID before insertion');
        $entityPersister->executeInserts();
        self::assertIsInt($testEntity->getId(), 'we should have got ID automatically from database');
    }

    private function getEntityManagerMock(?Connection $connection = null): MockObject|EntityManagerInterface
    {
        $cockroachDBPlatform = new CockroachDBPlatform();
        if ($connection === null) {
            $connection = self::createMock(Connection::class);
            $connection
                ->expects(self::atLeastOnce())
                ->method('getDatabasePlatform')
                ->willReturn($cockroachDBPlatform);
        }

        $entityManagerMock = self::createMock(EntityManagerInterface::class);
        $entityManagerMock
            ->expects(self::atLeastOnce())
            ->method('getConnection')
            ->willReturn($connection);
        $entityManagerMock
            ->expects(self::atLeastOnce())
            ->method('getConfiguration')
            ->willReturn(new Configuration());
        $entityManagerMock
            ->expects(self::atLeastOnce())
            ->method('getMetadataFactory')
            ->willReturn(new ClassMetadataFactory());
        $eventManagerMock = self::createMock(EventManager::class);
        $entityManagerMock
            ->expects(self::atLeastOnce())
            ->method('getEventManager')
            ->willReturn($eventManagerMock);
        $unitOfWork = new UnitOfWork($entityManagerMock);
        $entityManagerMock
            ->expects(self::atLeastOnce())
            ->method('getUnitOfWork')
            ->willReturn($unitOfWork);

        return $entityManagerMock;
    }

    private function getTestClassMetadata(): ClassMetadata
    {
        $classMetadata = new ClassMetadata(TestEntity::class);
        $classMetadata->initializeReflection(new RuntimeReflectionService());
        $classMetadata->identifier = ['id', 'id2'];
        $classMetadata->generatorType = ClassMetadata::GENERATOR_TYPE_CUSTOM;
        $classMetadata->idGenerator = new SerialGenerator();
        $classMetadata->customGeneratorDefinition = ['class' => SerialGenerator::class];
        $commonFieldMapping = [
                'type' => Types::INTEGER,
                'id' => true,
                'options' => ['unsigned' => true],
            ];
        $classMetadata->fieldMappings = [
            'id' => array_merge($commonFieldMapping, ['fieldName' => 'id', 'columnName' => 'an_identifier']),
            'id2' => array_merge($commonFieldMapping, ['fieldName' => 'id', 'columnName' => 'second_identifier']),
        ];
        $classMetadata->fieldNames = ['an_identifier' => 'id', 'second_identifier' => 'id2'];

        return $classMetadata;
    }
}
