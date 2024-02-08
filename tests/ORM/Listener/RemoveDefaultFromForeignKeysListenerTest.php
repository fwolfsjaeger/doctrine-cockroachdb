<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Tests\ORM\Listener;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use DoctrineCockroachDB\ORM\Listener\AddDefaultToSerialGeneratorListener;
use DoctrineCockroachDB\ORM\Listener\RemoveDefaultFromForeignKeysListener;
use DoctrineCockroachDB\Tests\ORM\EntityManagerMockTrait;
use DoctrineCockroachDB\Tests\ORM\Persisters\Entity\TestEntity;
use DoctrineCockroachDB\Tests\ORM\TestEntityClassMetadataTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests {@link AddDefaultToSerialGeneratorListener}.
 */
final class RemoveDefaultFromForeignKeysListenerTest extends TestCase
{
    use EntityManagerMockTrait;
    use TestEntityClassMetadataTrait;

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
            'nothing should have changed as we don\'t have SerialGenerator'
        );
    }

    public function testLoadClassMetadataRemovesDefaultFromForeignKey(): void
    {
        $removeDefaultFromForeignKeysListener = new RemoveDefaultFromForeignKeysListener();
        $classMetadata = $this->getTestEntityClassMetadata();
        $classMetadata->associationMappings = [
            'selfReference' => [
                'isOwningSide' => true,
                'targetEntity' => TestEntity::class,
                'fieldName' => 'selfReference',
                'joinColumns' => [
                    [
                        'referencedColumnName' => 'id',
                    ],
                ],
            ],
        ];
        self::assertArrayNotHasKey(
            'options',
            $classMetadata->associationMappings['selfReference']['joinColumns'][0]
        );
        $originalClassMetadata = clone $classMetadata;

        $targetClassMetadata = $this->getTestEntityClassMetadata();
        $targetClassMetadata->setAttributeOverride(
            'id',
            ['options' => ['default' => 'unique_rowid()', 'unsigned' => true]]
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
            'with AssociationMappings with default, we should have changed ClassMetadata'
        );
        self::assertArrayHasKey(
            'options',
            $eventArgs->getClassMetadata()->associationMappings['selfReference']['joinColumns'][0]
        );
        self::assertArrayHasKey(
            'default',
            $eventArgs->getClassMetadata()->associationMappings['selfReference']['joinColumns'][0]['options']
        );
        self::assertNull(
            $eventArgs->getClassMetadata()->associationMappings['selfReference']['joinColumns'][0]['options']['default']
        );
    }
}
