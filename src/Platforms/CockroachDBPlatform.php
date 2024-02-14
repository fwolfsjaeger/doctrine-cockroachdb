<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Platforms;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DateIntervalUnit;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\SQL\Builder\DefaultSelectSQLBuilder;
use Doctrine\DBAL\SQL\Builder\SelectSQLBuilder;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\Mapping\ClassMetadata;
use DoctrineCockroachDB\Schema\CockroachDBSchemaManager;
use UnexpectedValueException;

use function array_diff;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Provides the behavior, features and SQL dialect of the CockroachDB platform.
 */
class CockroachDBPlatform extends AbstractPlatform
{
    private bool $useBooleanTrueFalseStrings = true;

    /**
     * @var string[][]
     */
    private array $booleanLiterals = [
        'true' => [
            't',
            'true',
            'y',
            'yes',
            'on',
            '1',
        ],
        'false' => [
            'f',
            'false',
            'n',
            'no',
            'off',
            '0',
        ],
    ];

    /**
     * CockroachDB has different behavior with some drivers
     * with regard to how booleans have to be handled.
     *
     * Enables use of 'true'/'false' or otherwise 1 and 0 instead.
     *
     * @param bool $flag
     * @return void
     */
    public function setUseBooleanTrueFalseStrings(bool $flag): void
    {
        $this->useBooleanTrueFalseStrings = (bool)$flag;
    }

    /**
     * {@inheritDoc}
     */
    public function getSubstringExpression($string, $start, $length = null): string
    {
        if (null === $length) {
            return 'SUBSTRING(' . $string . ' FROM ' . $start . ')';
        }

        return 'SUBSTRING(' . $string . ' FROM ' . $start . ' FOR ' . $length . ')';
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated generate dates within the application
     */
    public function getNowExpression(): string
    {
        Deprecation::trigger(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/pull/4753',
            'CockroachDBPlatform::getNowExpression() is deprecated. Generate dates within the application.',
        );

        return 'LOCALTIMESTAMP(0)';
    }

    /**
     * {@inheritDoc}
     */
    public function getRegexpExpression(): string
    {
        return 'SIMILAR TO';
    }

    /**
     * {@inheritDoc}
     */
    public function getLocateExpression($str, $substr, $startPos = false): string
    {
        if (false !== $startPos) {
            $str = $this->getSubstringExpression($str, $startPos);

            return '
                CASE
                    WHEN (POSITION(' . $substr . ' IN ' . $str . ') = 0) THEN 0
                    ELSE (POSITION(' . $substr . ' IN ' . $str . ') + ' . $startPos . ' - 1)
                END';
        }

        return 'POSITION(' . $substr . ' IN ' . $str . ')';
    }

    /**
     * {@inheritDoc}
     */
    protected function getDateArithmeticIntervalExpression($date, $operator, $interval, $unit): string
    {
        if (DateIntervalUnit::QUARTER === $unit) {
            $interval *= 3;
            $unit = DateIntervalUnit::MONTH;
        }

        return '(' . $date . ' ' . $operator . ' (' . $interval . " || ' " . $unit . "')::interval)";
    }

    /**
     * {@inheritDoc}
     */
    public function getDateDiffExpression($date1, $date2): string
    {
        return '(DATE(' . $date1 . ') - DATE(' . $date2 . '))';
    }

    public function getCurrentDatabaseExpression(): string
    {
        return 'CURRENT_DATABASE()';
    }

    /**
     * {@inheritDoc}
     */
    public function supportsSequences(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsSchemas(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated
     */
    public function getDefaultSchemaName(): string
    {
        Deprecation::trigger(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/pull/5513',
            '%s is deprecated.',
            __METHOD__,
        );

        return 'defaultdb';
    }

    /**
     * {@inheritDoc}
     */
    public function supportsIdentityColumns(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsPartialIndexes(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated
     */
    public function usesSequenceEmulatedIdentityColumns(): bool
    {
        Deprecation::trigger(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/pull/5513',
            '%s is deprecated.',
            __METHOD__,
        );

        return true;
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated
     */
    public function getIdentitySequenceName($tableName, $columnName): string
    {
        Deprecation::triggerIfCalledFromOutside(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/pull/5513',
            '%s is deprecated.',
            __METHOD__,
        );

        return $tableName . '_' . $columnName . '_seq';
    }

    /**
     * {@inheritDoc}
     */
    public function supportsCommentOnStatement(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated
     */
    public function hasNativeGuidType(): bool
    {
        Deprecation::triggerIfCalledFromOutside(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/pull/5509',
            '%s is deprecated.',
            __METHOD__,
        );

        return true;
    }

    public function createSelectSQLBuilder(): SelectSQLBuilder
    {
        return new DefaultSelectSQLBuilder($this, 'FOR UPDATE', null);
    }

    /**
     * {@inheritDoc}
     */
    public function getListDatabasesSQL(): string
    {
        return 'SELECT datname FROM pg_database';
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated use {@see CockroachDBSchemaManager::listSchemaNames()} instead
     */
    public function getListNamespacesSQL(): string
    {
        Deprecation::triggerIfCalledFromOutside(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/issues/4503',
            'CockroachDBPlatform::getListNamespacesSQL() is deprecated,'
            . ' use CockroachDBSchemaManager::listSchemaNames() instead.',
        );

        return "
            SELECT
                schema_name AS nspname
            FROM
                information_schema.schemata
            WHERE
                schema_name NOT LIKE 'pg\_%'
                AND schema_name != 'information_schema'";
    }

    /**
     * {@inheritDoc}
     */
    public function getListSequencesSQL($database): string
    {
        return '
            SELECT
                sequence_name AS relname,
                sequence_schema AS schemaname,
                minimum_value AS min_value,
                increment AS increment_by
            FROM
                information_schema.sequences
            WHERE
                sequence_catalog = ' . $this->quoteStringLiteral($database) . "
                AND sequence_schema NOT LIKE 'pg\_%'
                AND sequence_schema != 'information_schema'";
    }

    /**
     * @deprecated The SQL used for schema introspection is an implementation detail and should not be relied upon.
     *
     * {@inheritDoc}
     */
    public function getListTablesSQL(): string
    {
        return "
            SELECT
                quote_ident(table_name) AS table_name,
                table_schema AS schema_name
            FROM
                information_schema.tables
            WHERE
                table_schema NOT LIKE 'pg\_%'
                AND table_schema != 'information_schema'
                AND table_name != 'geometry_columns'
                AND table_name != 'spatial_ref_sys'
                AND table_type != 'VIEW'";
    }

    /**
     * {@inheritDoc}
     */
    public function getListViewsSQL($database): string
    {
        return '
            SELECT
                quote_ident(table_name) AS viewname,
                table_schema AS schemaname,
                view_definition AS definition
            FROM
                information_schema.views
            WHERE
                view_definition IS NOT NULL';
    }

    /**
     * @param string $table
     * @param string|null $database
     * @return string
     * @deprecated the SQL used for schema introspection is an implementation detail and should not be relied upon
     */
    public function getListTableForeignKeysSQL($table, ?string $database = null): string
    {
        return '
            SELECT
                quote_ident(r.conname) AS conname,
                pg_catalog.pg_get_constraintdef(r.oid, true) AS condef
            FROM
                pg_catalog.pg_constraint r
            WHERE
                r.conrelid = (
                    SELECT
                        c.oid
                    FROM
                        pg_catalog.pg_class c,
                        pg_catalog.pg_namespace n
                    WHERE
                        ' . $this->getTableWhereClause($table) . "
                        AND n.oid = c.relnamespace
                )
                AND r.contype = 'f'";
    }

    /**
     * @deprecated
     *
     * {@inheritDoc}
     */
    public function getListTableConstraintsSQL($table): string
    {
        $tableIdentifier = new Identifier($table);
        $tableIdentifier = $this->quoteStringLiteral($tableIdentifier->getName());
        $query = "
            SELECT
                quote_ident(relname) AS relname
            FROM
                pg_class
            WHERE
                oid IN (
                    SELECT
                        indexrelid
                    FROM
                        pg_index,
                        pg_class
                    WHERE
                        pg_class.relname = %s
                        AND pg_class.oid = pg_index.indrelid
                        AND (indisunique = 't' OR indisprimary = 't')
                )";

        return sprintf($query, $tableIdentifier);
    }

    /**
     * @deprecated the SQL used for schema introspection is an implementation detail and should not be relied upon.
     *
     * {@inheritDoc}
     */
    public function getListTableIndexesSQL($table, $database = null): string
    {
        return '
            SELECT
                quote_ident(relname) AS relname,
                pg_index.indisunique,
                pg_index.indisprimary,
                pg_index.indkey,
                pg_index.indrelid,
                pg_get_expr(indpred, indrelid) AS where
            FROM
                pg_class,
                pg_index
            WHERE
                oid IN (
                    SELECT
                        indexrelid
                    FROM
                        pg_index si,
                        pg_class sc,
                        pg_namespace sn
                    WHERE
                        ' . $this->getTableWhereClause($table, 'sc', 'sn') . '
                        AND sc.oid = si.indrelid
                        AND sc.relnamespace = sn.oid
                )
                AND pg_index.indexrelid = oid';
    }

    /**
     * @param string $table
     * @param string $classAlias
     * @param string $namespaceAlias
     * @return string
     */
    private function getTableWhereClause(string $table, string $classAlias = 'c', string $namespaceAlias = 'n'): string
    {
        $whereClause = $namespaceAlias . '.nspname NOT IN (';
        $whereClause .= "'pg_catalog', 'information_schema', 'pg_toast', 'pg_extension', 'crdb_internal'";
        $whereClause .= ') AND ';

        if (str_contains($table, '.')) {
            [$schema, $table] = explode('.', $table);
            $schema = $this->quoteStringLiteral($schema);
        } else {
            $schema = 'ANY(current_schemas(false))';
        }

        $tableIdentifier = new Identifier($table);
        $tableIdentifier = $this->quoteStringLiteral($tableIdentifier->getName());

        return $whereClause . sprintf(
            '%s.relname = %s AND %s.nspname = %s',
            $classAlias,
            $tableIdentifier,
            $namespaceAlias,
            $schema,
        );
    }

    /**
     * @deprecated The SQL used for schema introspection is an implementation detail and should not be relied upon.
     *
     * {@inheritDoc}
     */
    public function getListTableColumnsSQL($table, $database = null): string
    {
        return "
            SELECT
                a.attnum,
                quote_ident(a.attname) AS field,
                t.typname AS type,
                format_type(a.atttypid, a.atttypmod) AS complete_type,
                (
                    SELECT
                        tc.collcollate
                    FROM
                        pg_catalog.pg_collation AS tc
                    WHERE
                        tc.oid = a.attcollation
                ) AS collation,
                (
                    SELECT
                        t1.typname
                    FROM
                        pg_catalog.pg_type AS t1
                    WHERE
                        t1.oid = t.typbasetype
                ) AS domain_type,
                (
                    SELECT
                        format_type(t2.typbasetype, t2.typtypmod)
                    FROM
                        pg_catalog.pg_type AS t2
                    WHERE
                        t2.typtype = 'd'
                        AND t2.oid = a.atttypid
                ) AS domain_complete_type,
                a.attnotnull AS isnotnull,
                (
                    SELECT
                        't'
                    FROM
                        pg_index
                    WHERE
                        c.oid = pg_index.indrelid
                        AND pg_index.indkey[0] = a.attnum
                        AND pg_index.indisprimary = 't'
                ) AS pri,
                (
                    SELECT
                        pg_get_expr(adbin, adrelid)
                    FROM
                        pg_attrdef
                    WHERE
                        c.oid = pg_attrdef.adrelid
                        AND pg_attrdef.adnum=a.attnum
                ) AS default,
                (
                    SELECT
                        pg_description.description
                    FROM
                        pg_description
                    WHERE
                        pg_description.objoid = c.oid
                        AND a.attnum = pg_description.objsubid
                ) AS comment
            FROM
                pg_attribute a,
                pg_class c,
                pg_type t,
                pg_namespace n
            WHERE
                " . $this->getTableWhereClause($table, 'c', 'n') . '
                AND a.attnum > 0
                AND a.attrelid = c.oid
                AND a.atttypid = t.oid
                AND n.oid = c.relnamespace
            ORDER BY
                a.attnum';
    }

    /**
     * DEFERRABLE, DEFERRED and IMMEDIATE are not supported by CockroachDB
     *
     * {@inheritDoc}
     *
     * @see https://github.com/cockroachdb/cockroach/issues/31632
     * @see https://www.cockroachlabs.com/docs/v23.1/foreign-key#foreign-key-actions
     */
    public function getAdvancedForeignKeyOptionsSQL(ForeignKeyConstraint $foreignKey): string
    {
        $query = '';

        if ($foreignKey->hasOption('match')) {
            $query .= ' MATCH ' . $foreignKey->getOption('match');
        }

        if ($foreignKey->hasOption('onUpdate')) {
            $query .= ' ON UPDATE ' . $this->getForeignKeyReferentialActionSQL($foreignKey->getOption('onUpdate'));
        }

        if ($foreignKey->hasOption('onDelete')) {
            $query .= ' ON DELETE ' . $this->getForeignKeyReferentialActionSQL($foreignKey->getOption('onDelete'));
        }

        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function getAlterTableSQL(TableDiff $diff): array
    {
        $sql = [];
        $commentsSQL = [];
        $columnSql = [];
        $table = $diff->getOldTable() ?? $diff->getName($this);
        $tableNameSQL = $table->getQuotedName($this);

        foreach ($diff->getAddedColumns() as $addedColumn) {
            if ($this->onSchemaAlterTableAddColumn($addedColumn, $diff, $columnSql)) {
                continue;
            }

            $query = 'ADD ' . $this->getColumnDeclarationSQL(
                $addedColumn->getQuotedName($this),
                $addedColumn->toArray(),
            );

            $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ' . $query;
            $comment = $this->getColumnComment($addedColumn);

            if (null === $comment || '' === $comment) {
                continue;
            }

            $commentsSQL[] = $this->getCommentOnColumnSQL(
                $tableNameSQL,
                $addedColumn->getQuotedName($this),
                $comment,
            );
        }

        foreach ($diff->getDroppedColumns() as $droppedColumn) {
            if ($this->onSchemaAlterTableRemoveColumn($droppedColumn, $diff, $columnSql)) {
                continue;
            }

            $query = 'DROP ' . $droppedColumn->getQuotedName($this);
            $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ' . $query;
        }

        foreach ($diff->getModifiedColumns() as $columnDiff) {
            if ($this->onSchemaAlterTableChangeColumn($columnDiff, $diff, $columnSql)) {
                continue;
            }

            if ($this->isUnchangedBinaryColumn($columnDiff)) {
                continue;
            }

            $oldColumn = $columnDiff->getOldColumn() ?? $columnDiff->getOldColumnName();
            $newColumn = $columnDiff->getNewColumn();
            $oldColumnName = $oldColumn->getQuotedName($this);

            if (
                $columnDiff->hasTypeChanged()
                || $columnDiff->hasPrecisionChanged()
                || $columnDiff->hasScaleChanged()
                || $columnDiff->hasFixedChanged()
            ) {
                $type = $newColumn->getType();

                // SERIAL/BIGSERIAL are not "real" types, we can't alter a column to that type
                $columnDefinition = $newColumn->toArray();
                $columnDefinition['autoincrement'] = false;

                // here was a server version check before, but DBAL API does not support this anymore.
                $query = 'ALTER ' . $oldColumnName . ' TYPE ' . $type->getSQLDeclaration($columnDefinition, $this);
                $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ' . $query;
            }

            if ($columnDiff->hasDefaultChanged()) {
                $defaultClause = $newColumn->getDefault() === null
                    ? ' DROP DEFAULT'
                    : ' SET' . $this->getDefaultValueDeclarationSQL($newColumn->toArray());

                $query = 'ALTER ' . $oldColumnName . $defaultClause;
                $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ' . $query;
            }

            if ($columnDiff->hasNotNullChanged()) {
                $query = 'ALTER ' . $oldColumnName . ' ' . ($newColumn->getNotnull() ? 'SET' : 'DROP') . ' NOT NULL';
                $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ' . $query;
            }

            if ($columnDiff->hasAutoIncrementChanged()) {
                if ($newColumn->getAutoincrement()) {
                    // add autoincrement
                    $seqName = $this->getIdentitySequenceName(
                        $table->getName(),
                        $oldColumnName,
                    );

                    $sql[] = 'CREATE SEQUENCE ' . $seqName;
                    $sql[] = "SELECT setval(
                        '" . $seqName . "',
                        (SELECT MAX(" . $oldColumnName . ') FROM ' . $tableNameSQL . ')
                    )';

                    $query = 'ALTER ' . $oldColumnName . " SET DEFAULT nextval('" . $seqName . "')";
                } else {
                    // Drop autoincrement, but do NOT drop the sequence. It might be re-used by other tables or have
                    $query = 'ALTER ' . $oldColumnName . ' DROP DEFAULT';
                }

                $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ' . $query;
            }

            $oldComment = $this->getOldColumnComment($columnDiff);
            $newComment = $this->getColumnComment($newColumn);

            if (
                $columnDiff->hasCommentChanged()
                || ($columnDiff->getOldColumn() !== null && $oldComment !== $newComment)
            ) {
                $commentsSQL[] = $this->getCommentOnColumnSQL(
                    $tableNameSQL,
                    $newColumn->getQuotedName($this),
                    $newComment,
                );
            }

            if (!$columnDiff->hasLengthChanged()) {
                continue;
            }

            $sql[] = 'ALTER TABLE ' . $tableNameSQL
                . ' ALTER ' . $oldColumnName
                . ' TYPE ' . $newColumn->getType()->getSQLDeclaration($newColumn->toArray(), $this);
        }

        foreach ($diff->getRenamedColumns() as $oldColumnName => $column) {
            if ($this->onSchemaAlterTableRenameColumn($oldColumnName, $column, $diff, $columnSql)) {
                continue;
            }

            $oldColumnName = new Identifier($oldColumnName);

            $sql[] = 'ALTER TABLE ' . $tableNameSQL
                . ' RENAME COLUMN ' . $oldColumnName->getQuotedName($this)
                . ' TO ' . $column->getQuotedName($this);
        }

        $tableSql = [];

        if (!$this->onSchemaAlterTable($diff, $tableSql)) {
            $sql = array_merge($sql, $commentsSQL);

            $newName = $diff->getNewName();

            if (false !== $newName) {
                Deprecation::trigger(
                    'doctrine/dbal',
                    'https://github.com/doctrine/dbal/pull/5663',
                    'Generation of "rename table" SQL using %s is deprecated. Use getRenameTableSQL() instead.',
                    __METHOD__,
                );

                $sql[] = sprintf(
                    'ALTER TABLE %s RENAME TO %s',
                    $tableNameSQL,
                    $newName->getQuotedName($this),
                );
            }

            $sql = array_merge(
                $this->getPreAlterTableIndexForeignKeySQL($diff),
                $sql,
                $this->getPostAlterTableIndexForeignKeySQL($diff),
            );
        }

        return array_merge($sql, $tableSql, $columnSql);
    }

    /**
     * Checks whether a given column diff is a logically unchanged binary type column.
     *
     * Used to determine whether a column alteration for a binary type column can be skipped.
     * Doctrine's {@see BinaryType} and {@see BlobType} are mapped to the same database column type on this platform
     * as this platform does not have a native VARBINARY/BINARY column type. Therefor the comparator
     * might detect differences for binary type columns which do not have to be propagated
     * to database as there actually is no difference at database level.
     */
    private function isUnchangedBinaryColumn(ColumnDiff $columnDiff): bool
    {
        $newColumnType = $columnDiff->getNewColumn()->getType();

        if (!$newColumnType instanceof BinaryType && !$newColumnType instanceof BlobType) {
            return false;
        }

        $oldColumn = $columnDiff->getOldColumn() instanceof Column ? $columnDiff->getOldColumn() : null;

        if (null !== $oldColumn) {
            $oldColumnType = $oldColumn->getType();

            if (!$oldColumnType instanceof BinaryType && !$oldColumnType instanceof BlobType) {
                return false;
            }

            return count(array_diff($columnDiff->changedProperties, ['type', 'length', 'fixed'])) === 0;
        }

        if ($columnDiff->hasTypeChanged()) {
            return false;
        }

        return count(array_diff($columnDiff->changedProperties, ['length', 'fixed'])) === 0;
    }

    /**
     * {@inheritDoc}
     */
    protected function getRenameIndexSQL($oldIndexName, Index $index, $tableName): array
    {
        if (str_contains($tableName, '.')) {
            [$schema] = explode('.', $tableName);
            $oldIndexName = $schema . '.' . $oldIndexName;
        }

        return ['ALTER INDEX ' . $oldIndexName . ' RENAME TO ' . $index->getQuotedName($this)];
    }

    /**
     * {@inheritDoc}
     */
    public function getCommentOnColumnSQL($tableName, $columnName, $comment): string
    {
        $tableNameIdentifier = new Identifier($tableName);
        $columnNameIdentifier = new Identifier($columnName);
        $comment = null === $comment ? 'NULL' : $this->quoteStringLiteral($comment);

        return sprintf(
            'COMMENT ON COLUMN %s.%s IS %s',
            $tableNameIdentifier->getQuotedName($this),
            $columnNameIdentifier->getQuotedName($this),
            $comment,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getCreateSequenceSQL(Sequence $sequence): string
    {
        return 'CREATE SEQUENCE ' . $sequence->getQuotedName($this) .
            ' INCREMENT BY ' . $sequence->getAllocationSize() .
            ' MINVALUE ' . $sequence->getInitialValue() .
            ' START ' . $sequence->getInitialValue() .
            $this->getSequenceCacheSQL($sequence);
    }

    /**
     * {@inheritDoc}
     */
    public function getAlterSequenceSQL(Sequence $sequence): string
    {
        return 'ALTER SEQUENCE ' . $sequence->getQuotedName($this) .
            ' INCREMENT BY ' . $sequence->getAllocationSize() .
            $this->getSequenceCacheSQL($sequence);
    }

    /**
     * Cache definition for sequences
     */
    private function getSequenceCacheSQL(Sequence $sequence): string
    {
        if ($sequence->getCache() > 1) {
            return ' CACHE ' . $sequence->getCache();
        }

        return '';
    }

    /**
     * {@inheritDoc}
     */
    public function getDropSequenceSQL($sequence): string
    {
        return parent::getDropSequenceSQL($sequence) . ' CASCADE';
    }

    /**
     * {@inheritDoc}
     */
    public function getDropForeignKeySQL($foreignKey, $table): string
    {
        return $this->getDropConstraintSQL($foreignKey, $table);
    }

    /**
     * {@inheritDoc}
     */
    public function getDropIndexSQL($index, $table = null): string
    {
        if (
            null !== $table
            && $index instanceof Index
            && $index->isPrimary()
        ) {
            $constraintName = $index->getName() === 'primary' ? $this->tableName($table) . '_pkey' : $index->getName();

            return $this->getDropConstraintSQL($constraintName, $table);
        }

        if ('"primary"' === $index && null !== $table) {
            $constraintName = $this->tableName($table) . '_pkey';

            return $this->getDropConstraintSQL($constraintName, $table);
        }

        return parent::getDropIndexSQL($index, $table);
    }

    private function tableName(null|string|Table $table): string
    {
        return $table instanceof Table ? $table->getName() : (string)$table;
    }

    /**
     * @param Table|string|null $table
     *
     * @see https://www.cockroachlabs.com/docs/stable/create-table.html
     */
    protected function _getCreateTableSQL($name, array $columns, array $options = []): array
    {
        $queryFields = $this->getColumnDeclarationListSQL($columns);

        if (!empty($options['primary'])) {
            $keyColumns = array_unique(array_values($options['primary']));
            $queryFields .= ', PRIMARY KEY(' . implode(', ', $keyColumns) . ')';
        }

        // The options LOCAL, GLOBAL, and UNLOGGED are no-ops, allowed by the parser for PostgreSQL compatibility.
        $query = 'CREATE TABLE ' . $name . ' (' . $queryFields . ')';

        $sql = [$query];

        if (!empty($options['indexes'])) {
            foreach ($options['indexes'] as $index) {
                $sql[] = $this->getCreateIndexSQL($index, $name);
            }
        }

        if (isset($options['uniqueConstraints'])) {
            foreach ($options['uniqueConstraints'] as $uniqueConstraint) {
                $sql[] = $this->getCreateUniqueConstraintSQL($uniqueConstraint, $name);
            }
        }

        if (isset($options['foreignKeys'])) {
            foreach ($options['foreignKeys'] as $definition) {
                $sql[] = $this->getCreateForeignKeySQL($definition, $name);
            }
        }

        return $sql;
    }

    /**
     * Converts a single boolean value.
     *
     * First converts the value to its native PHP boolean type
     * and passes it to the given callback function to be reconverted
     * into any custom representation.
     *
     * @param mixed $value the value to convert
     * @param callable $callback the callback function to use for converting the real boolean value
     * @return mixed
     * @throws UnexpectedValueException
     */
    private function convertSingleBooleanValue(mixed $value, callable $callback): mixed
    {
        if (null === $value) {
            return $callback(null);
        }

        if (is_bool($value) || is_numeric($value)) {
            return $callback((bool)$value);
        }

        if (!is_string($value)) {
            return $callback(true);
        }

        // Better safe than sorry: http://php.net/in_array#106319
        if (in_array(strtolower(trim($value)), $this->booleanLiterals['false'], true)) {
            return $callback(false);
        }

        if (in_array(strtolower(trim($value)), $this->booleanLiterals['true'], true)) {
            return $callback(true);
        }

        throw new UnexpectedValueException(sprintf("Unrecognized boolean literal '%s'", $value));
    }

    /**
     * Converts one or multiple boolean values.
     *
     * First converts the value(s) to their native PHP boolean type
     * and passes them to the given callback function to be reconverted
     * into any custom representation.
     *
     * @param mixed $item the value(s) to convert
     * @param callable $callback the callback function to use for converting the real boolean value(s)
     * @return mixed
     */
    private function doConvertBooleans(mixed $item, callable $callback): mixed
    {
        if (is_array($item)) {
            foreach ($item as $key => $value) {
                $item[$key] = $this->convertSingleBooleanValue($value, $callback);
            }

            return $item;
        }

        return $this->convertSingleBooleanValue($item, $callback);
    }

    /**
     * {@inheritDoc}
     *
     * Postgres wants boolean values converted to the strings 'true'/'false'.
     */
    public function convertBooleans($item): mixed
    {
        if (!$this->useBooleanTrueFalseStrings) {
            return parent::convertBooleans($item);
        }

        return $this->doConvertBooleans(
            $item,
            static function (mixed $value) {
                if (null === $value) {
                    return 'NULL';
                }

                return true === $value ? 'true' : 'false';
            },
        );
    }

    /**
     * {@inheritDoc}
     */
    public function convertBooleansToDatabaseValue($item): mixed
    {
        if (!$this->useBooleanTrueFalseStrings) {
            return parent::convertBooleansToDatabaseValue($item);
        }

        return $this->doConvertBooleans(
            $item,
            static function (mixed $value): ?int {
                return null === $value ? null : (int)$value;
            },
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param T $item
     * @return (T is null ? null : bool)
     * @template T
     */
    public function convertFromBoolean($item): ?bool
    {
        if (
            is_string($item)
            && in_array(strtolower($item), $this->booleanLiterals['false'], true)
        ) {
            return false;
        }

        return parent::convertFromBoolean($item);
    }

    /**
     * {@inheritDoc}
     */
    public function getSequenceNextValSQL($sequence): string
    {
        return "SELECT NEXTVAL('" . $sequence . "')";
    }

    /**
     * {@inheritDoc}
     */
    public function getSetTransactionIsolationSQL($level): string
    {
        return 'SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL '
            . $this->_getTransactionIsolationLevelSQL($level);
    }

    /**
     * {@inheritDoc}
     */
    public function getBooleanTypeDeclarationSQL(array $column): string
    {
        return 'BOOLEAN';
    }

    /**
     * {@inheritDoc}
     */
    public function getIntegerTypeDeclarationSQL(array $column): string
    {
        if (!empty($column['autoincrement'])) {
            return 'SERIAL4';
        }

        return 'INT4';
    }

    /**
     * {@inheritDoc}
     */
    public function getBigIntTypeDeclarationSQL(array $column): string
    {
        if (!empty($column['autoincrement'])) {
            return 'SERIAL8';
        }

        return 'INT8';
    }

    /**
     * {@inheritDoc}
     */
    public function getSmallIntTypeDeclarationSQL(array $column): string
    {
        if (!empty($column['autoincrement'])) {
            return 'SERIAL2';
        }

        return 'INT2';
    }

    /**
     * {@inheritDoc}
     */
    public function getGuidTypeDeclarationSQL(array $column): string
    {
        return 'UUID';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTypeDeclarationSQL(array $column): string
    {
        return 'TIMESTAMP';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTzTypeDeclarationSQL(array $column): string
    {
        return 'TIMESTAMPTZ';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTypeDeclarationSQL(array $column): string
    {
        return 'DATE';
    }

    /**
     * {@inheritDoc}
     */
    public function getTimeTypeDeclarationSQL(array $column): string
    {
        return 'TIME';
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCommonIntegerTypeDeclarationSQL(array $column): string
    {
        return '';
    }

    /**
     * {@inheritDoc}
     */
    protected function getVarcharTypeDeclarationSQLSnippet($length, $fixed): string
    {
        if ($fixed) {
            return $length > 0 ? 'CHAR(' . $length . ')' : 'CHAR(255)';
        }

        return $length > 0 ? 'VARCHAR(' . $length . ')' : 'VARCHAR(255)';
    }

    /**
     * {@inheritDoc}
     */
    protected function getBinaryTypeDeclarationSQLSnippet($length, $fixed): string
    {
        return 'BYTEA';
    }

    /**
     * {@inheritDoc}
     */
    public function getClobTypeDeclarationSQL(array $column): string
    {
        return 'TEXT';
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        Deprecation::triggerIfCalledFromOutside(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/issues/4749',
            'CockroachDBPlatform::getName() is deprecated. Identify platforms by their class.',
        );

        return 'crdb';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTzFormatString(): string
    {
        return 'Y-m-d H:i:sO';
    }

    /**
     * {@inheritDoc}
     */
    public function getEmptyIdentityInsertSQL($quotedTableName, $quotedIdentifierColumnName): string
    {
        return 'INSERT INTO '
            . $quotedTableName
            . ' ('
            . $quotedIdentifierColumnName .
            ') VALUES (DEFAULT)'
            . ' RETURNING ' . $quotedIdentifierColumnName;
    }

    /**
     * {@inheritDoc}
     */
    public function getTruncateTableSQL($tableName, $cascade = false): string
    {
        $tableIdentifier = new Identifier($tableName);
        $sql = 'TRUNCATE ' . $tableIdentifier->getQuotedName($this);

        if ($cascade) {
            $sql .= ' CASCADE';
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function getReadLockSQL(): string
    {
        return 'FOR UPDATE';
    }

    /**
     * {@inheritDoc}
     */
    protected function initializeDoctrineTypeMappings(): void
    {
        $this->doctrineTypeMapping = [
            'bigint' => Types::BIGINT,
            'bigserial' => Types::BIGINT,
            'bool' => Types::BOOLEAN,
            'boolean' => Types::BOOLEAN,
            'bpchar' => Types::STRING,
            'bytea' => Types::BLOB,
            'char' => Types::STRING,
            'date' => Types::DATE_MUTABLE,
            'datetime' => Types::DATETIME_MUTABLE,
            'decimal' => Types::DECIMAL,
            'double' => Types::FLOAT,
            'double precision' => Types::FLOAT,
            'float' => Types::FLOAT,
            'float4' => Types::FLOAT,
            'float8' => Types::FLOAT,
            'inet' => Types::STRING,
            'int' => Types::INTEGER,
            'int2' => Types::SMALLINT,
            'int4' => Types::INTEGER,
            'int8' => Types::BIGINT,
            'integer' => Types::INTEGER,
            'interval' => Types::STRING,
            'json' => Types::JSON,
            'jsonb' => Types::JSON,
            'money' => Types::DECIMAL,
            'numeric' => Types::DECIMAL,
            'serial' => Types::INTEGER,
            'serial4' => Types::INTEGER,
            'serial8' => Types::BIGINT,
            'real' => Types::FLOAT,
            'smallint' => Types::SMALLINT,
            'text' => Types::TEXT,
            'time' => Types::TIME_MUTABLE,
            'timestamp' => Types::DATETIME_MUTABLE,
            'timestamptz' => Types::DATETIMETZ_MUTABLE,
            'timetz' => Types::TIME_MUTABLE,
            'tsvector' => Types::TEXT,
            'uuid' => Types::GUID,
            'varchar' => Types::STRING,
            'year' => Types::DATE_MUTABLE,
            '_varchar' => Types::STRING,
            'int2vector' => Types::JSON,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated
     */
    public function getVarcharMaxLength(): int
    {
        Deprecation::triggerIfCalledFromOutside(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/issues/3263',
            'CockroachDBPlatform::getVarcharMaxLength() is deprecated.',
        );

        return 65535;
    }

    /**
     * {@inheritDoc}
     */
    public function getBinaryMaxLength(): int
    {
        Deprecation::triggerIfCalledFromOutside(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/issues/3263',
            'CockroachDBPlatform::getBinaryMaxLength() is deprecated.',
        );

        return 0;
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated
     */
    public function getBinaryDefaultLength(): int
    {
        Deprecation::trigger(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/issues/3263',
            'Relying on the default binary column length is deprecated, specify the length explicitly.',
        );

        return 0;
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated
     */
    public function hasNativeJsonType(): bool
    {
        Deprecation::triggerIfCalledFromOutside(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/pull/5509',
            '%s is deprecated.',
            __METHOD__,
        );

        return true;
    }

    protected function createReservedKeywordsList(): KeywordList
    {
        return new Keywords\CockroachDBKeywords();
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated implement {@see createReservedKeywordsList()} instead
     */
    protected function getReservedKeywordsClass(): string
    {
        Deprecation::triggerIfCalledFromOutside(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/issues/4510',
            'PostgreSQLPlatform::getReservedKeywordsClass() is deprecated,'
            . ' use PostgreSQLPlatform::createReservedKeywordsList() instead.',
        );

        return Keywords\CockroachDBKeywords::class;
    }

    /**
     * {@inheritDoc}
     */
    public function getBlobTypeDeclarationSQL(array $column): string
    {
        return 'BYTEA';
    }

    /**
     * {@inheritDoc}
     */
    public function getDefaultValueDeclarationSQL($column): string
    {
        if (isset($column['autoincrement']) && true === $column['autoincrement']) {
            return '';
        }

        return parent::getDefaultValueDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function supportsColumnCollation(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getJsonTypeDeclarationSQL(array $column): string
    {
        if (!empty($column['jsonb'])) {
            return 'JSONB';
        }

        return 'JSON';
    }

    private function getOldColumnComment(ColumnDiff $columnDiff): ?string
    {
        $oldColumn = $columnDiff->getOldColumn();

        if (null !== $oldColumn) {
            return $this->getColumnComment($oldColumn);
        }

        return null;
    }

    /**
     * @deprecated the SQL used for schema introspection is an implementation detail and should not be relied upon
     */
    public function getListTableMetadataSQL(string $table, ?string $schema = null): string
    {
        if (null !== $schema) {
            $table = $schema . '.' . $table;
        }

        return sprintf(
            'SELECT obj_description(%s::regclass) AS table_comment',
            $this->quoteStringLiteral($table),
        );
    }

    public function createSchemaManager(Connection $connection): CockroachDBSchemaManager
    {
        return new CockroachDBSchemaManager($connection, $this);
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     */
    public function getInsertPostfix(ClassMetadata $classMetadata): string
    {
        return sprintf('RETURNING %s', implode(',', $classMetadata->getIdentifierColumnNames()));
    }
}
