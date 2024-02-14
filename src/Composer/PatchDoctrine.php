<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Composer;

use Composer\InstalledVersions;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

class PatchDoctrine
{
    private const DOCTRINE_BASIC_ENTITY_PERSISTER = 'use Doctrine\ORM\Persisters\Entity\BasicEntityPersister';

    public static function overrideBasicEntityPersister(): void
    {
        $doctrineOrmPath = InstalledVersions::getInstallPath('doctrine/orm');

        if (
            null === $doctrineOrmPath
            || !file_exists($doctrineOrmPath)
        ) {
            return;
        }

        $finder = new Finder();
        $finder
            ->files()
            ->in([$doctrineOrmPath])
            ->exclude([
                'Decorator',
                'Event',
                'Id',
                'Internal',
                'Proxy',
                'Query',
                'Tools',
                'Repository',
                'Tools',
                'Utility',
            ]);

        /**
         * @var SplFileInfo $fileInfo
         */
        foreach ($finder as $fileInfo) {
            $data = file_get_contents($fileInfo->getRealPath());

            if (!is_string($data) || !str_contains($data, self::DOCTRINE_BASIC_ENTITY_PERSISTER)) {
                continue;
            }

            $data = str_replace(
                search: self::DOCTRINE_BASIC_ENTITY_PERSISTER,
                replace: 'use DoctrineCockroachDB\ORM\Persisters\Entity\BasicEntityPersister',
                subject: $data,
            );

            file_put_contents($fileInfo->getRealPath(), $data);
        }
    }
}
