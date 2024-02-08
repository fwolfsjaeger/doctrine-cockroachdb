<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\ORM\Listener;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use DoctrineCockroachDB\ORM\Id\SerialGenerator;

/**
 * Adds "DEFAULT unique_rowid()" to identifiers for classes with SerialGenerator.
 */
final class AddDefaultToSerialGeneratorListener
{
    public const DEFAULT_STATEMENT = 'unique_rowid()';

    public function loadClassMetadata(LoadClassMetadataEventArgs $eventArgs): void
    {
        $classMetadata = $eventArgs->getClassMetadata();
        if (!$classMetadata->idGenerator instanceof SerialGenerator) {
            return;
        }

        foreach ($classMetadata->identifier as $identifier) {
            $fieldMapping = $classMetadata->getFieldMapping($identifier);
            $options = \array_merge(['default' => self::DEFAULT_STATEMENT], $fieldMapping['options'] ?? []);
            $fieldMapping['options'] = $options;
            $classMetadata->setAttributeOverride($identifier, $fieldMapping);
        }
    }
}
