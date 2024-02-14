<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\ORM\Listener;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\MappingException;
use DoctrineCockroachDB\ORM\Id\SerialGenerator;

use function array_merge;

/**
 * Adds "DEFAULT unique_rowid()" to identifiers for classes with SerialGenerator.
 */
final class AddDefaultToSerialGeneratorListener
{
    public const DEFAULT_STATEMENT = 'unique_rowid()';

    /**
     * @throws MappingException
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $eventArgs): void
    {
        $classMetadata = $eventArgs->getClassMetadata();

        if (!$classMetadata->idGenerator instanceof SerialGenerator) {
            return;
        }

        foreach ($classMetadata->identifier as $identifier) {
            $fieldMapping = $classMetadata->getFieldMapping($identifier);
            $fieldMapping['options'] = array_merge(
                ['default' => self::DEFAULT_STATEMENT],
                $fieldMapping['options'] ?? [],
            );

            $classMetadata->setAttributeOverride($identifier, $fieldMapping);
        }
    }
}
