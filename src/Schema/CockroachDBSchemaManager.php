<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Schema;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\View;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Deprecations\Deprecation;
use DoctrineCockroachDB\Platforms\CockroachDBPlatform;

/**
 * CockroachDB Schema Manager.
 *
 * @extends AbstractSchemaManager<CockroachDBPlatform>
 */
class CockroachDBSchemaManager extends AbstractSchemaManager
{
    /** @var string[]|null */
    private ?array $existingSchemaPaths = null;

    /**
     * {@inheritDoc}
     */
    public function listTableNames(): array
    {
        return $this->doListTableNames();
    }

    /**
     * {@inheritDoc}
     */
    public function listTables(): array
    {
        return $this->doListTables();
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated use {@see introspectTable()} instead
     */
    public function listTableDetails($name): Table
    {
        Deprecation::trigger(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/pull/5595',
            '%s is deprecated. Use introspectTable() instead.',
            __METHOD__,
        );

        return $this->doListTableDetails($name);
    }

    /**
     * {@inheritDoc}
     */
    public function listTableColumns($table, $database = null): array
    {
        return $this->doListTableColumns($table, $database);
    }

    /**
     * {@inheritDoc}
     */
    public function listTableIndexes($table): array
    {
        return $this->doListTableIndexes($table);
    }

    /**
     * {@inheritDoc}
     */
    public function listTableForeignKeys($table, $database = null): array
    {
        return $this->doListTableForeignKeys($table, $database);
    }

    /**
     * Gets all the existing schema names.
     *
     * @return string[]
     * @throws Exception
     * @deprecated use {@see listSchemaNames()} instead
     */
    public function getSchemaNames(): array
    {
        Deprecation::trigger(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/issues/4503',
            'PostgreSQLSchemaManager::getSchemaNames() is deprecated,'
            . ' use PostgreSQLSchemaManager::listSchemaNames() instead.',
        );

        return $this->listNamespaceNames();
    }

    /**
     * {@inheritDoc}
     */
    public function listSchemaNames(): array
    {
        return $this->_conn->fetchFirstColumn("
            SELECT
                schema_name AS nspname
            FROM
               information_schema.schemata
            WHERE
                schema_name NOT LIKE 'pg\_%'
                AND schema_name != 'information_schema'
                AND schema_name != 'crdb_internal'
        ");
    }

    /**
     * {@inheritDoc}
     *
     * @deprecated
     */
    public function getSchemaSearchPaths(): array
    {
        Deprecation::triggerIfCalledFromOutside(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/pull/4821',
            'CockroachDBSchemaManager::getSchemaSearchPaths() is deprecated.',
        );

        $params = $this->_conn->getParams();

        $searchPaths = $this->_conn->fetchOne('SHOW search_path');
        assert(false !== $searchPaths);

        $schema = explode(',', $searchPaths);

        if (isset($params['user'])) {
            $schema = str_replace('"$user"', $params['user'], $schema);
        }

        return array_map('trim', $schema);
    }

    /**
     * Gets names of all existing schemas in the current users search path.
     *
     * @return string[]
     * @throws Exception
     */
    public function getExistingSchemaSearchPaths(): array
    {
        if (null === $this->existingSchemaPaths) {
            $this->determineExistingSchemaSearchPaths();
        }

        assert(null !== $this->existingSchemaPaths);

        return $this->existingSchemaPaths;
    }

    /**
     * Returns the name of the current schema.
     *
     * @return string|null
     * @throws Exception
     */
    protected function getCurrentSchema(): ?string
    {
        $schemas = $this->getExistingSchemaSearchPaths();

        return array_shift($schemas);
    }

    /**
     * Sets or resets the order of the existing schemas in the current search path of the user.
     *
     * @return void
     * @throws Exception
     */
    public function determineExistingSchemaSearchPaths(): void
    {
        $names = $this->listSchemaNames();
        $paths = $this->getSchemaSearchPaths();

        $this->existingSchemaPaths = array_filter($paths, static function ($v) use ($names): bool {
            return in_array($v, $names, true);
        });
    }

    /**
     * {@inheritdoc}
     */
    protected function _getPortableTableForeignKeyDefinition($tableForeignKey): ForeignKeyConstraint
    {
        $onUpdate = null;
        $onDelete = null;

        if (
            preg_match(
                '(ON UPDATE ([a-zA-Z0-9]+( (NULL|ACTION|DEFAULT))?))',
                $tableForeignKey['condef'],
                $match,
            ) === 1
        ) {
            $onUpdate = $match[1];
        }

        if (
            preg_match(
                '(ON DELETE ([a-zA-Z0-9]+( (NULL|ACTION|DEFAULT))?))',
                $tableForeignKey['condef'],
                $match,
            ) === 1
        ) {
            $onDelete = $match[1];
        }

        $result = preg_match('/FOREIGN KEY \((.+)\) REFERENCES (.+)\((.+)\)/', $tableForeignKey['condef'], $values);
        assert(1 === $result);

        /*
         * CockroachDB returns identifiers that are keywords with quotes,
         * we need them later, don't get the idea to trim them here.
         */
        $localColumns = array_map('trim', explode(',', $values[1]));
        $foreignColumns = array_map('trim', explode(',', $values[3]));
        $foreignTable = $values[2];

        return new ForeignKeyConstraint(
            $localColumns,
            $foreignTable,
            $foreignColumns,
            $tableForeignKey['conname'],
            ['onUpdate' => $onUpdate, 'onDelete' => $onDelete],
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function _getPortableViewDefinition($view): View
    {
        return new View($view['schemaname'] . '.' . $view['viewname'], $view['definition']);
    }

    /**
     * {@inheritdoc}
     */
    protected function _getPortableTableDefinition($table): string
    {
        $currentSchema = $this->getCurrentSchema();

        if ($table['schema_name'] === $currentSchema) {
            return $table['table_name'];
        }

        return $table['schema_name'] . '.' . $table['table_name'];
    }

    /**
     * {@inheritdoc}
     */
    protected function _getPortableTableIndexesList($tableIndexes, $tableName = null): array
    {
        $buffer = [];
        foreach ($tableIndexes as $row) {
            $colNumbers = array_map('intval', explode(' ', $row['indkey']));
            $columnNameSql = sprintf(
                'SELECT attnum, attname FROM pg_attribute WHERE attrelid=%d AND attnum IN (%s) ORDER BY attnum ASC',
                $row['indrelid'],
                implode(', ', $colNumbers),
            );

            $indexColumns = $this->_conn->fetchAllAssociative($columnNameSql);

            // required for getting the order of the columns right.
            foreach ($colNumbers as $colNum) {
                foreach ($indexColumns as $colRow) {
                    if ($colNum !== $colRow['attnum']) {
                        continue;
                    }

                    $buffer[] = [
                        'key_name' => $row['relname'],
                        'column_name' => trim($colRow['attname']),
                        'non_unique' => !$row['indisunique'],
                        'primary' => $row['indisprimary'],
                        'where' => $row['where'],
                    ];
                }
            }
        }

        return parent::_getPortableTableIndexesList($buffer, $tableName);
    }

    /**
     * {@inheritdoc}
     */
    protected function _getPortableDatabaseDefinition($database): string
    {
        return $database['datname'];
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated use {@see listSchemaNames()} instead
     */
    protected function getPortableNamespaceDefinition(array $namespace): string
    {
        Deprecation::triggerIfCalledFromOutside(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/issues/4503',
            'CockroachDBSchemaManager::getPortableNamespaceDefinition() is deprecated,'
            . ' use CockroachDBSchemaManager::listSchemaNames() instead.',
        );

        return $namespace['nspname'];
    }

    /**
     * {@inheritdoc}
     */
    protected function _getPortableSequenceDefinition($sequence): Sequence
    {
        if ('public' !== $sequence['schemaname']) {
            $sequenceName = $sequence['schemaname'] . '.' . $sequence['relname'];
        } else {
            $sequenceName = $sequence['relname'];
        }

        if (!isset($sequence['increment_by'], $sequence['min_value'])) {
            $sequence['min_value'] = 0;
            $sequence['increment_by'] = 0;

            $data = $this->_conn->fetchAssociative('SHOW CREATE ' . $this->_platform->quoteIdentifier($sequenceName));

            if (!empty($data['create_statement'])) {
                preg_match_all('/ -?\d+/', $data['create_statement'], $matches);

                if (!empty($matches[0])) {
                    $matches = array_map('trim', $matches[0]);
                    $sequence['min_value'] = $matches[0];
                    $sequence['increment_by'] = $matches[2];
                }
            }
        }

        return new Sequence($sequenceName, (int)$sequence['increment_by'], (int)$sequence['min_value']);
    }

    /**
     * {@inheritdoc}
     */
    protected function _getPortableTableColumnDefinition($tableColumn): Column
    {
        $tableColumn = array_change_key_case($tableColumn, CASE_LOWER);

        if (strtolower($tableColumn['type']) === 'varchar' || strtolower($tableColumn['type']) === 'bpchar') {
            // get length from varchar definition
            $length = preg_replace('/.*\((\d*)\).*/', '$1', $tableColumn['complete_type']);
            $tableColumn['length'] = $length;
        }

        $matches = [];
        $autoincrement = false;

        if (null !== $tableColumn['default']) {
            if (preg_match("/^(?:nextval\('(.*)'(::.*)?\)|unique_rowid\(\))$/", $tableColumn['default'], $matches)) {
                $tableColumn['sequence'] = $matches[1] ?? null;
                $tableColumn['default'] = null;
                $autoincrement = true;
            } elseif (preg_match("/^['(](.*)[')]::/", $tableColumn['default'], $matches)) {
                $tableColumn['default'] = $matches[1];
            } elseif (str_starts_with($tableColumn['default'], 'NULL::')) {
                $tableColumn['default'] = null;
            }
        }

        $length = $tableColumn['length'] ?? null;

        if ('-1' === $length && isset($tableColumn['atttypmod'])) {
            $length = $tableColumn['atttypmod'] - 4;
        }

        if ((int)$length <= 0) {
            $length = null;
        }

        $fixed = null;

        if (!isset($tableColumn['name'])) {
            $tableColumn['name'] = '';
        }

        $precision = null;
        $scale = null;
        $jsonb = null;
        $dbType = strtolower($tableColumn['type']);

        if (
            null !== $tableColumn['domain_type']
            && '' !== $tableColumn['domain_type']
            && !$this->_platform->hasDoctrineTypeMappingFor($tableColumn['type'])
        ) {
            $dbType = strtolower($tableColumn['domain_type']);
            $tableColumn['complete_type'] = $tableColumn['domain_complete_type'];
        }

        $type = $this->_platform->getDoctrineTypeMapping($dbType);
        $type = $this->extractDoctrineTypeFromComment($tableColumn['comment'], $type);
        $tableColumn['comment'] = $this->removeDoctrineTypeFromComment($tableColumn['comment'], $type);

        switch ($dbType) {
            case 'serial2':
            case 'serial4':
            case 'serial8':
                $autoincrement = true;
                // no break
            case 'smallint':
            case 'int2':
            case 'int':
            case 'int4':
            case 'integer':
            case 'bigint':
            case 'int8':
                $tableColumn['default'] = $this->fixNegativeNumericDefaultValue($tableColumn['default']);
                $length = null;
                break;

            case 'bool':
            case 'boolean':
                if ('true' === $tableColumn['default']) {
                    $tableColumn['default'] = true;
                }

                if ('false' === $tableColumn['default']) {
                    $tableColumn['default'] = false;
                }

                $length = null;
                break;

            case 'text':
            case '_varchar':
            case 'varchar':
                $tableColumn['default'] = $this->parseDefaultExpression($tableColumn['default']);
                $fixed = false;
                break;
            case 'interval':
                $fixed = false;
                break;

            case 'char':
            case 'bpchar':
                $fixed = true;
                break;

            case 'float':
            case 'float4':
            case 'float8':
            case 'double':
            case 'double precision':
            case 'real':
            case 'decimal':
            case 'money':
            case 'numeric':
                $tableColumn['default'] = $this->fixNegativeNumericDefaultValue($tableColumn['default']);

                if (
                    preg_match(
                        '/[A-Za-z]+\((\d+),(\d+)\)/',
                        $tableColumn['complete_type'],
                        $match,
                    )
                ) {
                    [, $precision, $scale] = $match;
                    $length = null;
                }

                break;

            case 'year':
                $length = null;
                break;

            case 'jsonb':
                $jsonb = true;
                break;
        }

        if (
            is_string($tableColumn['default'])
            && preg_match("('([^']+)'::)", $tableColumn['default'], $match)
        ) {
            $tableColumn['default'] = $match[1];
        }

        $options = [
            'length' => $length,
            'notnull' => (bool)$tableColumn['isnotnull'],
            'default' => $tableColumn['default'],
            'precision' => $precision,
            'scale' => $scale,
            'fixed' => $fixed,
            'autoincrement' => $autoincrement,
            'comment' => $tableColumn['comment'] ?? null,
        ];

        $column = new Column($tableColumn['field'], Type::getType($type), $options);

        if (!empty($tableColumn['collation'])) {
            $column->setPlatformOption('collation', $tableColumn['collation']);
        }

        if ($column->getType()->getName() === Types::JSON) {
            if (!$column->getType() instanceof JsonType) {
                Deprecation::trigger(
                    'doctrine/dbal',
                    'https://github.com/doctrine/dbal/pull/5049',
                    '%s not extending %s while being named %s is deprecated'
                    . ' and will lead to jsonb never to being used in 4.0',
                    get_class($column->getType()),
                    JsonType::class,
                    Types::JSON,
                );
            }

            $column->setPlatformOption('jsonb', $jsonb);
        }

        return $column;
    }

    /**
     * CockroachDB puts parentheses around negative numeric default values that need to be stripped eventually.
     */
    private function fixNegativeNumericDefaultValue(?string $defaultValue): ?string
    {
        if (null !== $defaultValue && str_starts_with($defaultValue, '(')) {
            return trim($defaultValue, '()');
        }

        return $defaultValue;
    }

    /**
     * Parses a default value expression as given by CockroachDB.
     */
    private function parseDefaultExpression(?string $default): ?string
    {
        if (null === $default) {
            return null;
        }

        return str_replace("''", "'", $default);
    }

    protected function selectTableNames(string $databaseName): Result
    {
        $sql = "
            SELECT
                quote_ident(table_name) AS table_name,
                table_schema AS schema_name
            FROM
                information_schema.tables
            WHERE
                table_catalog = ?
                AND table_schema NOT LIKE 'pg\_%'
                AND table_schema != 'information_schema'
                AND table_schema != 'crdb_internal'
                AND table_name != 'geometry_columns'
                AND table_name != 'spatial_ref_sys'
                AND table_type = 'BASE TABLE'";

        return $this->_conn->executeQuery($sql, [$databaseName]);
    }

    protected function selectTableColumns(string $databaseName, ?string $tableName = null): Result
    {
        $columns = [];
        $columns[] = 'a.attnum';
        $columns[] = 'quote_ident(a.attname) AS field';
        $columns[] = 't.typname AS type';
        $columns[] = 'format_type(a.atttypid, a.atttypmod) AS complete_type';
        $columns[] = '(SELECT tc.collcollate FROM pg_catalog.pg_collation tc WHERE tc.oid = a.attcollation) AS collation';
        $columns[] = '(SELECT t1.typname FROM pg_catalog.pg_type t1 WHERE t1.oid = t.typbasetype) AS domain_type';
        $columns[] = "(SELECT format_type(t2.typbasetype, t2.typtypmod) FROM pg_catalog.pg_type t2 WHERE t2.typtype = 'd' AND t2.oid = a.atttypid) AS domain_complete_type, a.attnotnull AS isnotnull";
        $columns[] = "(SELECT 't' FROM pg_index WHERE c.oid = pg_index.indrelid AND pg_index.indkey[0] = a.attnum AND pg_index.indisprimary = 't') AS pri";
        $columns[] = '(SELECT pg_get_expr(adbin, adrelid) FROM pg_attrdef WHERE c.oid = pg_attrdef.adrelid AND pg_attrdef.adnum=a.attnum) AS default';
        $columns[] = '(SELECT pg_description.description FROM pg_description WHERE pg_description.objoid = c.oid AND a.attnum = pg_description.objsubid) AS comment';

        if (null === $tableName) {
            $columns[] = 'c.relname AS table_name';
            $columns[] = 'n.nspname AS schema_name';
        }

        $conditions = ['a.attnum > 0', 'a.attisdropped = false', "c.relkind = 'r'", 'd.refobjid IS NULL'];
        $conditions = array_merge($conditions, $this->buildQueryConditions($tableName));

        $sql = '
            SELECT
                ' . implode(', ', $columns) . "
            FROM
                pg_attribute AS a
                INNER JOIN pg_class AS c ON (
                    c.oid = a.attrelid
                )
                INNER JOIN pg_type AS t ON (
                    t.oid = a.atttypid
                )
                INNER JOIN pg_namespace AS n ON (
                    n.oid = c.relnamespace
                )
                LEFT JOIN pg_depend AS d ON (
                    d.objid = c.oid
                    AND d.deptype = 'e'
                    AND d.classid = (SELECT oid FROM pg_class WHERE relname = 'pg_class')
                )
            WHERE
                " . implode(' AND ', $conditions) . '
            ORDER BY
                a.attnum ASC';

        return $this->_conn->executeQuery($sql);
    }

    protected function selectIndexColumns(string $databaseName, ?string $tableName = null): Result
    {
        $columns = [];
        $columns[] = 'quote_ident(ic.relname) AS relname';
        $columns[] = 'i.indisunique';
        $columns[] = 'i.indisprimary';
        $columns[] = 'i.indkey';
        $columns[] = 'i.indrelid';
        $columns[] = 'pg_get_expr(indpred, indrelid) AS "where"';

        if (null === $tableName) {
            $columns[] = 'tc.relname AS table_name';
            $columns[] = 'tn.nspname AS schema_name';
        }

        $conditions = ['c.oid = i.indrelid', 'c.relnamespace = n.oid'];
        $conditions = array_merge($conditions, $this->buildQueryConditions($tableName));

        $sql = '
            SELECT
                ' . implode(', ', $columns) . '
            FROM
                pg_index AS i
                JOIN pg_class AS tc ON (
                    tc.oid = i.indrelid
                )
                JOIN pg_namespace tn ON (
                    tn.oid = tc.relnamespace
                )
                JOIN pg_class AS ic ON (
                    ic.oid = i.indexrelid
                )
            WHERE
                ic.oid IN (
                    SELECT
                        indexrelid
                    FROM
                        pg_index i,
                        pg_class c,
                        pg_namespace n
                    WHERE
                        ' . implode(' AND ', $conditions) . '
                )';

        return $this->_conn->executeQuery($sql);
    }

    protected function selectForeignKeyColumns(string $databaseName, ?string $tableName = null): Result
    {
        $columns = ['quote_ident(r.conname) AS conname', 'pg_get_constraintdef(r.oid, true) AS condef'];

        if (null === $tableName) {
            $columns[] = 'tc.relname AS table_name';
            $columns[] = 'tn.nspname AS schema_name';
        }

        $conditions = ['n.oid = c.relnamespace'];
        $conditions = array_merge($conditions, $this->buildQueryConditions($tableName));

        $sql = '
            SELECT
                ' . implode(', ', $columns) . '
            FROM
                pg_constraint AS r
                JOIN pg_class AS tc ON (
                    tc.oid = r.conrelid
                )
                JOIN pg_namespace AS tn ON (
                    tn.oid = tc.relnamespace
                )
            WHERE
                r.conrelid IN (
                    SELECT
                        c.oid
                    FROM
                        pg_class c,
                        pg_namespace n
                    WHERE
                        ' . implode(' AND ', $conditions) . "
                )
                AND r.contype = 'f'";

        return $this->_conn->executeQuery($sql);
    }

    /**
     * {@inheritDoc}
     */
    protected function fetchTableOptionsByTable(string $databaseName, ?string $tableName = null): array
    {
        $conditions = ["c.relkind = 'r'"];
        $conditions = array_merge($conditions, $this->buildQueryConditions($tableName));

        $sql = "
            SELECT
                c.relname,
                obj_description(c.oid, 'pg_class') AS comment
            FROM
                pg_class AS c
                INNER JOIN pg_namespace AS n ON (
                    n.oid = c.relnamespace
                )
            WHERE
                " . implode(' AND ', $conditions);

        return $this->_conn->fetchAllAssociativeIndexed($sql);
    }

    /**
     * @param string|null $tableName
     * @return list<string>
     */
    protected function buildQueryConditions(?string $tableName): array
    {
        $conditions = [];

        if (null !== $tableName) {
            if (str_contains($tableName, '.')) {
                [$schemaName, $tableName] = explode('.', $tableName);
                $conditions[] = 'n.nspname = ' . $this->_platform->quoteStringLiteral($schemaName);
            } else {
                $conditions[] = 'n.nspname = ANY(current_schemas(false))';
            }

            $identifier = new Identifier($tableName);
            $conditions[] = 'c.relname = ' . $this->_platform->quoteStringLiteral($identifier->getName());
        }

        $conditions[] = "n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast', 'pg_extension', 'crdb_internal')";

        return $conditions;
    }
}
