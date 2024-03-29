<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Tests\ORM\Listener;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use DoctrineCockroachDB\ORM\Listener\AddDefaultToSerialGeneratorListener;
use DoctrineCockroachDB\ORM\Listener\RemoveDefaultFromForeignKeysListener;
use DoctrineCockroachDB\Tests\ORM\EntityManagerMockTrait;
use DoctrineCockroachDB\Tests\ORM\Persisters\Entity\TestEntity;
use DoctrineCockroachDB\Tests\ORM\TestEntityClassMetadataTrait;
use PHPUnit\Framework\MockObject\Exception as MockObjectException;
use PHPUnit\Framework\TestCase;

/**
 * Tests {@link AddDefaultToSerialGeneratorListener}.
 */
final class RemoveDefaultFromForeignKeysListenerTest extends TestCase
{
    use EntityManagerMockTrait;
    use TestEntityClassMetadataTrait;

    /**
     * @throws MappingException
     * @throws MockObjectException
     */
    public function testLoadClassMetadataDoesNothingWithoutAssociationMapping(): void
    {
        $removeDefaultFromForeignKeysListener = new RemoveDefaultFromForeignKeysListener();
        $classMetadata = new ClassMetadata(TestEntity::class);
        $originalClassMetadata = clone $classMetadata;
        $eventArgs = new LoadClassMetadataEventArgs($classMetadata, $this->getEntityManagerMock(expectAtLeast: 0));

        $removeDefaultFromForeignKeysListener->loadClassMetadata($eventArgs);
        self::assertEquals(
            $originalClassMetadata,
            $eventArgs->getClassMetadata(),
            'nothing should have changed as we don\'t have SerialGenerator',
        );
    }

    /**
     * @throws MappingException
     * @throws MockObjectException
     */
    public function testLoadClassMetadataRemovesDefaultFromForeignKey(): void
    {
        $removeDefaultFromForeignKeysListener = new RemoveDefaultFromForeignKeysListener();
        $classMetadata = $this->getTestEntityClassMetadata();
        $classMetadata->associationMappings = [
            'selfReference' => [
                'isOwningSide' => true,
                'targetEntity' => TestEntity::class,
                'fieldName' => 'selfReference',
                'type' => Types::INTEGER,
                'joinColumns' => [
                    [
                        'referencedColumnName' => 'id',
                    ],
                ],
            ],
        ];
        self::assertArrayNotHasKey(
            'options',
            $classMetadata->associationMappings['selfReference']['joinColumns'][0],
        );
        $originalClassMetadata = clone $classMetadata;

        $targetClassMetadata = $this->getTestEntityClassMetadata();
        $targetClassMetadata->setAttributeOverride(
            'id',
            ['options' => ['default' => 'unique_rowid()', 'unsigned' => true]],
        );
        $entityManagerMock = $this->getEntityManagerMock(expectAtLeast: 0);
        $entityManagerMock
            ->expects(self::once())
            ->method('getClassMetadata')
            ->with(TestEntity::class)
            ->willReturn($targetClassMetadata);
        $eventArgs = new LoadClassMetadataEventArgs($classMetadata, $entityManagerMock);

        $removeDefaultFromForeignKeysListener->loadClassMetadata($eventArgs);
        self::assertNotEquals(
            $originalClassMetadata,
            $eventArgs->getClassMetadata(),
            'with AssociationMappings with default, we should have changed ClassMetadata',
        );
        $joinColumns = $eventArgs->getClassMetadata()->associationMappings['selfReference']['joinColumns'];
        self::assertArrayHasKey(
            'options',
            $joinColumns[0] ?? null,
        );
        self::assertArrayHasKey(
            'default',
            $joinColumns[0]['options'] ?? null,
        );
        self::assertNull(
            $joinColumns[0]['options']['default'] ?? null,
        );
    }
}
