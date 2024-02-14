<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Tests\ORM\Listener;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use DoctrineCockroachDB\ORM\Listener\AddDefaultToSerialGeneratorListener;
use DoctrineCockroachDB\Tests\ORM\EntityManagerMockTrait;
use DoctrineCockroachDB\Tests\ORM\Persisters\Entity\TestEntity;
use DoctrineCockroachDB\Tests\ORM\TestEntityClassMetadataTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests {@link AddDefaultToSerialGeneratorListener}.
 */
final class AddDefaultToSerialGeneratorListenerTest extends TestCase
{
    use EntityManagerMockTrait;
    use TestEntityClassMetadataTrait;

    /**
     * @throws MappingException
     */
    public function testLoadClassMetadataNotSerialGenerator(): void
    {
        $addDefaultToSerialGeneratorListener = new AddDefaultToSerialGeneratorListener();
        $classMetadata = new ClassMetadata(TestEntity::class);
        $originalClassMetadata = clone $classMetadata;
        $eventArgs = new LoadClassMetadataEventArgs($classMetadata, $this->getEntityManagerMock(expectAtLeast: 0));

        $addDefaultToSerialGeneratorListener->loadClassMetadata($eventArgs);
        self::assertEquals(
            $originalClassMetadata,
            $eventArgs->getClassMetadata(),
            'nothing should have changed as we don\'t have SerialGenerator',
        );
    }

    /**
     * @throws MappingException
     */
    public function testLoadClassMetadataWhenSerialGeneratorChangesDefaultForIdentifier(): void
    {
        $addDefaultToSerialGeneratorListener = new AddDefaultToSerialGeneratorListener();
        $classMetadata = $this->getTestEntityClassMetadata();
        $originalClassMetadata = clone $classMetadata;
        $eventArgs = new LoadClassMetadataEventArgs(
            $classMetadata,
            $this->getEntityManagerMock(expectAtLeast: 0),
        );
        $addDefaultToSerialGeneratorListener->loadClassMetadata($eventArgs);
        self::assertNotEquals(
            $originalClassMetadata,
            $eventArgs->getClassMetadata(),
            'with SerialGenerator, we should have changed ClassMetadata',
        );
        foreach ($eventArgs->getClassMetadata()->getFieldNames() as $fieldName) {
            $fieldMapping = $eventArgs->getClassMetadata()->getFieldMapping($fieldName);
            self::assertSame(
                ['default' => 'unique_rowid()', 'unsigned' => true],
                $fieldMapping['options'],
                'field mapping should add default, keeping existing unsigned option',
            );
        }
    }
}
