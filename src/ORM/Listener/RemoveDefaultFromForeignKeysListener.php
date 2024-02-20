<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\ORM\Listener;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\MappingException;

/**
 * Removes DEFAULT from foreign key columns which was most likely added by {@link AddDefaultToSerialGeneratorListener}.
 */
final class RemoveDefaultFromForeignKeysListener
{
    /**
     * @throws MappingException
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $eventArgs): void
    {
        $classMetadata = $eventArgs->getClassMetadata();

        foreach ($classMetadata->getAssociationMappings() as $associationMapping) {
            if (
                !isset(
                    $associationMapping->targetEntity,
                    $associationMapping->joinColumns,
                    $associationMapping->fieldName,
                )
                || !is_array($associationMapping->joinColumns)
            ) {
                continue;
            }

            if (!$associationMapping->isOwningSide()) {
                return;
            }

            $em = $eventArgs->getEntityManager();
            $targetClassMetaData = $em->getClassMetadata($associationMapping->targetEntity);

            foreach ($associationMapping->joinColumns as &$joinColumn) {
                if (!isset($joinColumn['referencedColumnName'])) {
                    continue;
                }

                $referencedFieldName = $targetClassMetaData->getFieldName($joinColumn['referencedColumnName']);
                $targetFieldMapping = $targetClassMetaData->getFieldMapping($referencedFieldName);
                $targetFieldMappingDefault = $targetFieldMapping['options']['default'] ?? null;

                if (AddDefaultToSerialGeneratorListener::DEFAULT_STATEMENT !== $targetFieldMappingDefault) {
                    continue;
                }

                $fieldMapping = $classMetadata->getAssociationMapping($associationMapping->fieldName);

                if (!isset($fieldMapping['options']['default']) && isset($joinColumn['options']['default'])) {
                    $joinColumn['options']['default'] = null;
                }
            }

            unset($joinColumn);

            $classMetadata->setAssociationOverride(
                $associationMapping->fieldName,
                ['joinColumns' => $associationMapping->joinColumns],
            );
        }
    }
}
