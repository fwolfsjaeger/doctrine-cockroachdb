<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Platforms;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DateIntervalUnit;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\SQL\Builder\DefaultSelectSQLBuilder;
use Doctrine\DBAL\SQL\Builder\SelectSQLBuilder;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use DoctrineCockroachDB\Schema\CockroachDBSchemaManager;
use UnexpectedValueException;

use function array_merge;
use function array_unique;
use function array_values;
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
     */
    public function setUseBooleanTrueFalseStrings(bool $flag): void
    {
        $this->useBooleanTrueFalseStrings = $flag;
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
    public function getLocateExpression(string $string, string $substring, ?string $start = null): string
    {
        if (null !== $start) {
            $string = $this->getSubstringExpression($string, $start);

            return '
                CASE
                    WHEN (POSITION(' . $substring . ' IN ' . $string . ') = 0) THEN 0
                    ELSE (POSITION(' . $substring . ' IN ' . $string . ') + ' . $start . ' - 1)
                END';
        }

        return sprintf('POSITION(%s IN %s)', $substring, $string);
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
    public function getDateDiffExpression(string $date1, string $date2): string
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
     */
    public function supportsCommentOnStatement(): bool
    {
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
     */
    public function getListSequencesSQL(string $database): string
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

        $query .= parent::getAdvancedForeignKeyOptionsSQL($foreignKey);

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
        $table = $diff->getOldTable();
        $tableNameSQL = $table->getQuotedName($this);

        foreach ($diff->getAddedColumns() as $addedColumn) {
            $query = 'ADD ' . $this->getColumnDeclarationSQL(
                $addedColumn->getQuotedName($this),
                $addedColumn->toArray(),
            );

            $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ' . $query;

            $comment = $addedColumn->getComment();

            if ('' === $comment) {
                continue;
            }

            $commentsSQL[] = $this->getCommentOnColumnSQL(
                $tableNameSQL,
                $addedColumn->getQuotedName($this),
                $comment,
            );
        }

        foreach ($diff->getDroppedColumns() as $droppedColumn) {
            $query = 'DROP ' . $droppedColumn->getQuotedName($this);
            $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ' . $query;
        }

        foreach ($diff->getModifiedColumns() as $columnDiff) {
            $oldColumn = $columnDiff->getOldColumn();
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
                    $query = 'ADD GENERATED BY DEFAULT AS IDENTITY';
                } else {
                    $query = 'DROP IDENTITY';
                }

                $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ALTER ' . $oldColumnName . ' ' . $query;
            }

            $newComment = $newColumn->getComment();
            $oldComment = $columnDiff->getOldColumn()->getComment();

            if ($oldComment !== $newComment || $columnDiff->hasCommentChanged()) {
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
            $oldColumnName = new Identifier($oldColumnName);

            $sql[] = 'ALTER TABLE ' . $tableNameSQL
                . ' RENAME COLUMN ' . $oldColumnName->getQuotedName($this)
                . ' TO ' . $column->getQuotedName($this);
        }

        return array_merge(
            $this->getPreAlterTableIndexForeignKeySQL($diff),
            $sql,
            $commentsSQL,
            $this->getPostAlterTableIndexForeignKeySQL($diff),
            $columnSql,
        );
    }

    /**
     * {@inheritDoc}
     */
    protected function getRenameIndexSQL(string $oldIndexName, Index $index, string $tableName): array
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
    public function getDropSequenceSQL(string $name): string
    {
        return parent::getDropSequenceSQL($name) . ' CASCADE';
    }

    /**
     * {@inheritDoc}
     */
    public function getDropForeignKeySQL(string $foreignKey, string $table): string
    {
        return $this->getDropConstraintSQL($foreignKey, $table);
    }

    /**
     * {@inheritDoc}
     */
    public function getDropIndexSQL(string $name, string $table): string
    {
        if ('"primary"' === $name) {
            $constraintName = $table . '_pkey';

            return $this->getDropConstraintSQL($constraintName, $table);
        }

        return parent::getDropIndexSQL($name, $table);
    }

    /**
     * {@inheritDoc}
     *
     * @see https://www.cockroachlabs.com/docs/stable/create-table.html
     */
    protected function _getCreateTableSQL(string $name, array $columns, array $options = []): array
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

        throw new UnexpectedValueException(sprintf(
            'Unrecognized boolean literal, %s given.',
            $value,
        ));
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
     * CockroachDB wants boolean values converted to the strings 'true'/'false'.
     */
    public function convertBooleans(mixed $item): mixed
    {
        if (!$this->useBooleanTrueFalseStrings) {
            return parent::convertBooleans($item);
        }

        return $this->doConvertBooleans(
            $item,
            static function (mixed $value): string {
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
    public function convertBooleansToDatabaseValue(mixed $item): mixed
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
    public function convertFromBoolean(mixed $item): ?bool
    {
        if (
            is_string($item)
            && in_array($item, $this->booleanLiterals['false'], true)
        ) {
            return false;
        }

        return parent::convertFromBoolean($item);
    }

    public function getSequenceNextValSQL(string $sequence): string
    {
        return "SELECT NEXTVAL('" . $sequence . "')";
    }

    /**
     * {@inheritDoc}
     */
    public function getSetTransactionIsolationSQL(TransactionIsolationLevel $level): string
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
        $type = !empty($column['autoincrement']) ? 'SERIAL4' : 'INT4';

        return $type . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function getBigIntTypeDeclarationSQL(array $column): string
    {
        $type = !empty($column['autoincrement']) ? 'SERIAL8' : 'INT8';

        return $type . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function getSmallIntTypeDeclarationSQL(array $column): string
    {
        $type = !empty($column['autoincrement']) ? 'SERIAL2' : 'INT2';

        return $type . $this->_getCommonIntegerTypeDeclarationSQL($column);
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
        if (!empty($column['autoincrement'])) {
            return ' GENERATED BY DEFAULT AS IDENTITY';
        }

        return '';
    }

    /**
     * {@inheritDoc}
     */
    protected function getVarcharTypeDeclarationSQLSnippet(?int $length): string
    {
        $sql = 'VARCHAR';

        if (null !== $length) {
            $sql .= sprintf('(%d)', $length);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    protected function getBinaryTypeDeclarationSQLSnippet(?int $length): string
    {
        return 'BYTEA';
    }

    /**
     * {@inheritDoc}
     */
    protected function getVarbinaryTypeDeclarationSQLSnippet(?int $length): string
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
    public function getDateTimeTzFormatString(): string
    {
        return 'Y-m-d H:i:sO';
    }

    /**
     * {@inheritDoc}
     */
    public function getEmptyIdentityInsertSQL(string $quotedTableName, string $quotedIdentifierColumnName): string
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
    public function getTruncateTableSQL(string $tableName, bool $cascade = false): string
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
     */
    protected function createReservedKeywordsList(): KeywordList
    {
        return new Keywords\CockroachDBKeywords();
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
    public function getDefaultValueDeclarationSQL(array $column): string
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
