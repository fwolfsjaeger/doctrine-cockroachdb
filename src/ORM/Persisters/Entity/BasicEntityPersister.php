<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\ORM\Persisters\Entity;

use Doctrine\DBAL\Exception as DoctrineDbalException;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Persisters\Entity\BasicEntityPersister as DoctrineBasicEntityPersister;
use Doctrine\ORM\Utility\PersisterHelper;
use DoctrineCockroachDB\ORM\Id\SerialGenerator;
use DoctrineCockroachDB\Platforms\CockroachDBPlatform;

use function array_unique;
use function implode;
use function in_array;

/**
 * Adds insert support for {@link SerialGenerator}, otherwise identical functionality to {@link BasicEntityPersister}.
 *
 * @psalm-import-type AssociationMapping from ClassMetadata
 */
final class BasicEntityPersister extends DoctrineBasicEntityPersister
{
    private ?string $insertStmt = null;

    /**
     * {@inheritDoc}
     * @throws DoctrineDbalException
     */
    public function executeInserts(): void
    {
        if (!($this->platform instanceof CockroachDBPlatform)) {
            parent::executeInserts();

            return;
        }

        if (!$this->queuedInserts) {
            return;
        }

        $uow = $this->em->getUnitOfWork();
        $idGenerator = $this->class->idGenerator;
        $isPostInsertId = $idGenerator->isPostInsertGenerator();
        $stmt = $this->conn->prepare($this->getInsertSQL());
        $tableName = $this->class->getTableName();

        foreach ($this->queuedInserts as $key => $entity) {
            $insertData = $this->prepareInsertData($entity);

            if (isset($insertData[$tableName])) {
                $paramIndex = 1;

                foreach ($insertData[$tableName] as $column => $value) {
                    $stmt->bindValue($paramIndex++, $value, $this->columnTypes[$column]);
                }
            }

            $insertResult = $stmt->executeQuery();

            if ($isPostInsertId) {
                if ($idGenerator instanceof SerialGenerator) {
                    $generatedId = $insertResult->fetchOne();
                } else {
                    $generatedId = $idGenerator->generateId($this->em, $entity);
                }

                $id = [$this->class->identifier[0] => $generatedId];

                $uow->assignPostInsertId($entity, $generatedId);
            } else {
                $id = $this->class->getIdentifierValues($entity);
            }

            if ($this->class->requiresFetchAfterChange) {
                $this->assignDefaultVersionAndUpsertableValues($entity, $id);
            }

            // Unset this queued insert, so that the prepareUpdateData() method knows right away
            // (for the next entity already) that the current entity has been written to the database
            // and no extra updates need to be scheduled to refer to it.
            //
            // In \Doctrine\ORM\UnitOfWork::executeInserts(), the UoW already removed entities
            // from its own list (\Doctrine\ORM\UnitOfWork::$entityInsertions) right after they
            // were given to our addInsert() method.
            unset($this->queuedInserts[$key]);
        }
    }

    /**
     * {@inheritDoc}
     * @throws EntityNotFoundException
     * @throws MappingException
     */
    protected function prepareUpdateData($entity, bool $isInsert = false): array
    {
        if (!($this->platform instanceof CockroachDBPlatform)) {
            return parent::prepareUpdateData($entity, $isInsert);
        }

        $versionField = null;
        $result = [];
        $uow = $this->em->getUnitOfWork();
        $versioned = $this->class->isVersioned;

        if (false !== $versioned) {
            $versionField = $this->class->versionField;
        }

        foreach ($uow->getEntityChangeSet($entity) as $field => $change) {
            if (isset($versionField) && $versionField === $field) {
                continue;
            }

            if (isset($this->class->embeddedClasses[$field])) {
                continue;
            }

            $newVal = $change[1];

            if (!isset($this->class->associationMappings[$field])) {
                $fieldMapping = $this->class->fieldMappings[$field];
                $columnName = $fieldMapping['columnName'];

                // If field is part of primary key and idGenerator is set to SerialGenerator,
                // we will get data from database instead
                if (
                    isset($fieldMapping['id'])
                    && $this->class->idGenerator instanceof SerialGenerator
                ) {
                    continue;
                }

                if (!$isInsert && isset($fieldMapping['notUpdatable'])) {
                    continue;
                }

                if ($isInsert && isset($fieldMapping['notInsertable'])) {
                    continue;
                }

                $this->columnTypes[$columnName] = $fieldMapping['type'];

                $result[$this->getOwningTable($field)][$columnName] = $newVal;

                continue;
            }

            $assoc = $this->class->associationMappings[$field];

            // Only owning side of x-1 associations can have a FK column.
            if (!$assoc['isOwningSide'] || !($assoc['type'] & ClassMetadataInfo::TO_ONE)) {
                continue;
            }

            if (null !== $newVal) {
                $oid = spl_object_id($newVal);

                // If the associated entity $newVal is not yet persisted and/or does not yet have
                // an ID assigned, we must set $newVal = null. This will insert a null value and
                // schedule an extra update on the UnitOfWork.
                //
                // This gives us extra time to a) possibly obtain a database-generated identifier
                // value for $newVal, and b) insert $newVal into the database before the foreign
                // key reference is being made.
                //
                // When looking at $this->queuedInserts and $uow->isScheduledForInsert, be aware
                // of the implementation details that our own executeInserts() method will remove
                // entities from the former as soon as the insert statement has been executed and
                // a post-insert ID has been assigned (if necessary), and that the UnitOfWork has
                // already removed entities from its own list at the time they were passed to our
                // addInsert() method.
                //
                // Then, there is one extra exception we can make: An entity that references back to itself
                // _and_ uses an application-provided ID (the "NONE" generator strategy) also does not
                // need the extra update, although it is still in the list of insertions itself.
                // This looks like a minor optimization at first, but is the capstone for being able to
                // use non-NULLable, self-referencing associations in applications that provide IDs (like UUIDs).
                if (
                    (isset($this->queuedInserts[$oid]) || $uow->isScheduledForInsert($newVal))
                    && !($newVal === $entity && $this->class->isIdentifierNatural())
                ) {
                    $uow->scheduleExtraUpdate($entity, [$field => [null, $newVal]]);

                    $newVal = null;
                }
            }

            $newValId = null;

            if (null !== $newVal) {
                $newValId = $uow->getEntityIdentifier($newVal);
            }

            $targetClass = $this->em->getClassMetadata($assoc['targetEntity']);
            $owningTable = $this->getOwningTable($field);

            foreach ($assoc['joinColumns'] as $joinColumn) {
                $sourceColumn = $joinColumn['name'];
                $targetColumn = $joinColumn['referencedColumnName'];
                $quotedColumn = $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);

                $this->quotedColumns[$sourceColumn] = $quotedColumn;
                $this->columnTypes[$sourceColumn] = PersisterHelper::getTypeOfColumn(
                    $targetColumn,
                    $targetClass,
                    $this->em,
                );

                $result[$owningTable][$sourceColumn] = $newValId
                    ? $newValId[$targetClass->getFieldForColumn($targetColumn)]
                    : null;
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     * @throws DoctrineDbalException
     */
    public function getInsertSQL(): ?string
    {
        if (!($this->platform instanceof CockroachDBPlatform)) {
            return parent::getInsertSQL();
        }

        if (null !== $this->insertStmt) {
            return $this->insertStmt;
        }

        $columns = $this->getInsertColumnList();
        $tableName = $this->quoteStrategy->getTableName($this->class, $this->platform);

        if (empty($columns)) {
            $identityColumn = $this->quoteStrategy->getColumnName(
                $this->class->identifier[0],
                $this->class,
                $this->platform,
            );

            $this->insertStmt = $this->platform->getEmptyIdentityInsertSQL($tableName, $identityColumn);

            return $this->insertStmt;
        }

        $values = [];
        $columns = array_unique($columns);

        foreach ($columns as $column) {
            $placeholder = '?';

            if (
                isset(
                    $this->class->fieldNames[$column],
                    $this->columnTypes[$this->class->fieldNames[$column]],
                    $this->class->fieldMappings[$this->class->fieldNames[$column]]['requireSQLConversion']
                )
            ) {
                $type = Type::getType($this->columnTypes[$this->class->fieldNames[$column]]);
                $placeholder = $type->convertToDatabaseValueSQL('?', $this->platform);
            }

            $values[] = $placeholder;
        }

        $columns = implode(', ', $columns);
        $values = implode(', ', $values);

        $this->insertStmt = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) %s',
            $tableName,
            $columns,
            $values,
            $this->platform->getInsertPostfix($this->class),
        );

        return $this->insertStmt;
    }

    /**
     * {@inheritDoc}
     */
    protected function getInsertColumnList(): array
    {
        if (!($this->platform instanceof CockroachDBPlatform)) {
            return parent::getInsertColumnList();
        }

        $columns = [];

        foreach ($this->class->reflFields as $name => $field) {
            if ($this->class->isVersioned && $this->class->versionField === $name) {
                continue;
            }

            if (isset($this->class->embeddedClasses[$name])) {
                continue;
            }

            if (isset($this->class->associationMappings[$name])) {
                $assoc = $this->class->associationMappings[$name];

                if (
                    $assoc['isOwningSide']
                    && $assoc['type'] & ClassMetadataInfo::TO_ONE
                ) {
                    foreach ($assoc['joinColumns'] as $joinColumn) {
                        $columns[] = $this->quoteStrategy
                            ->getJoinColumnName($joinColumn, $this->class, $this->platform);
                    }
                }

                continue;
            }

            if (
                (!$this->class->isIdGeneratorIdentity() && !$this->class->idGenerator instanceof SerialGenerator)
                || !in_array($name, $this->class->identifier, true)
            ) {
                if (isset($this->class->fieldMappings[$name]['notInsertable'])) {
                    continue;
                }

                $columns[] = $this->quoteStrategy->getColumnName($name, $this->class, $this->platform);
                $this->columnTypes[$name] = $this->class->fieldMappings[$name]['type'];
            }
        }

        return $columns;
    }
}
