<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Tests\ORM;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\UnitOfWork;
use DoctrineCockroachDB\Platforms\CockroachDBPlatform;
use PHPUnit\Framework\MockObject\Exception as MockException;
use PHPUnit\Framework\MockObject\MockObject;

trait EntityManagerMockTrait
{
    /**
     * @throws MockException
     */
    private function getEntityManagerMock(
        ?Connection $connection = null,
        int $expectAtLeast = 1,
    ): EntityManagerInterface|MockObject {
        if ($expectAtLeast < 1) {
            return $this->createStub(EntityManagerInterface::class);
        }

        $expect = $this->atLeast($expectAtLeast);
        $entityManagerMock = $this->createMock(EntityManagerInterface::class);

        if (null === $connection) {
            $connection = $this->createMock(Connection::class);
            $connection
                ->expects($expect)
                ->method('getDatabasePlatform')
                ->willReturn(new CockroachDBPlatform());
        }

        $entityManagerMock
            ->expects($expect)
            ->method('getConnection')
            ->willReturn($connection);

        $entityManagerMock
            ->expects($expect)
            ->method('getConfiguration')
            ->willReturn(new Configuration());

        $entityManagerMock
            ->expects($expect)
            ->method('getMetadataFactory')
            ->willReturn(new ClassMetadataFactory());

        $entityManagerMock
            ->expects($expect)
            ->method('getEventManager')
            ->willReturn($this->createStub(EventManager::class));

        $entityManagerMock
            ->expects($expect)
            ->method('getUnitOfWork')
            ->willReturn(new UnitOfWork($entityManagerMock));

        return $entityManagerMock;
    }
}
