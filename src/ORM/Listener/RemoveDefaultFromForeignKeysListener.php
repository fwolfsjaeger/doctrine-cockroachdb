<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\ORM\Listener;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;

/**
 * Removes DEFAULT from foreign key columns which was most likely added by {@link AddDefaultToSerialGeneratorListener}.
 */
final class RemoveDefaultFromForeignKeysListener
{
    public function loadClassMetadata(LoadClassMetadataEventArgs $eventArgs): void
    {
        $classMetadata = $eventArgs->getClassMetadata();

        foreach ($classMetadata->getAssociationMappings() as $associationMapping) {
            if ($associationMapping['isOwningSide'] === false) {
                return;
            }

            $targetClassMetaData = $eventArgs->getEntityManager()
                ->getClassMetadata($associationMapping['targetEntity']);
            foreach ($associationMapping['joinColumns'] as &$joinColumn) {
                $referencedFieldName = $targetClassMetaData->getFieldName($joinColumn['referencedColumnName']);
                if (
                    $targetClassMetaData->getFieldMapping($referencedFieldName)['options']['default'] ===
                    AddDefaultToSerialGeneratorListener::DEFAULT_STATEMENT
                ) {
                    $fieldMapping = $classMetadata->getAssociationMapping($associationMapping['fieldName']);
                    if (!isset($fieldMapping['options']['default']) && !isset($joinColumn['options']['default'])) {
                        $joinColumn['options']['default'] = null;
                    }
                }
            }

            $classMetadata->setAssociationOverride(
                $associationMapping['fieldName'],
                ['joinColumns' => $associationMapping['joinColumns']]
            );
        }
    }
}
