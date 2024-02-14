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
        $cockroachDBPlatform = new CockroachDBPlatform();
        if (null === $connection) {
            $connection = self::createMock(Connection::class);
            $connection
                ->expects(self::atLeast($expectAtLeast))
                ->method('getDatabasePlatform')
                ->willReturn($cockroachDBPlatform);
        }

        $entityManagerMock = self::createMock(EntityManagerInterface::class);
        $entityManagerMock
            ->expects(self::atLeast($expectAtLeast))
            ->method('getConnection')
            ->willReturn($connection);
        $entityManagerMock
            ->expects(self::atLeast($expectAtLeast))
            ->method('getConfiguration')
            ->willReturn(new Configuration());
        $entityManagerMock
            ->expects(self::atLeast($expectAtLeast))
            ->method('getMetadataFactory')
            ->willReturn(new ClassMetadataFactory());
        $eventManagerMock = self::createMock(EventManager::class);
        $entityManagerMock
            ->expects(self::atLeast($expectAtLeast))
            ->method('getEventManager')
            ->willReturn($eventManagerMock);
        $unitOfWork = new UnitOfWork($entityManagerMock);
        $entityManagerMock
            ->expects(self::atLeast($expectAtLeast))
            ->method('getUnitOfWork')
            ->willReturn($unitOfWork);

        return $entityManagerMock;
    }
}
