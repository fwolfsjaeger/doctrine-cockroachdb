<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Schema;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\View;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\Type;
use DoctrineCockroachDB\Platforms\CockroachDBPlatform;

use function array_change_key_case;
use function array_filter;
use function array_map;
use function array_merge;
use function array_shift;
use function assert;
use function explode;
use function get_class;
use function implode;
use function in_array;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_replace;
use function strtolower;
use function trim;

use const CASE_LOWER;

/**
 * CockroachDB Schema Manager.
 *
 * @extends AbstractSchemaManager<CockroachDBPlatform>
 */
class CockroachDBSchemaManager extends AbstractSchemaManager
{
    private ?string $currentSchema = null;

    /**
     * {@inheritDoc}
     */
    public function listSchemaNames(): array
    {
        return $this->connection->fetchFirstColumn("
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
     */
    public function createSchemaConfig(): SchemaConfig
    {
        $config = parent::createSchemaConfig();

        $config->setName($this->getCurrentSchema());

        return $config;
    }

    /**
     * Returns the name of the current schema.
     *
     * @throws Exception
     */
    protected function getCurrentSchema(): ?string
    {
        return $this->currentSchema ??= $this->determineCurrentSchema();
    }

    /**
     * Determines the name of the current schema.
     *
     * @throws Exception
     */
    protected function determineCurrentSchema(): string
    {
        $currentSchema = $this->connection->fetchOne('SELECT current_schema()');
        assert(is_string($currentSchema));

        return $currentSchema;
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableTableForeignKeyDefinition(array $tableForeignKey): ForeignKeyConstraint
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
     * {@inheritDoc}
     */
    protected function _getPortableViewDefinition(array $view): View
    {
        return new View($view['schemaname'] . '.' . $view['viewname'], $view['definition']);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableTableDefinition(array $table): string
    {
        $currentSchema = $this->getCurrentSchema();

        if ($table['schema_name'] === $currentSchema) {
            return $table['table_name'];
        }

        return $table['schema_name'] . '.' . $table['table_name'];
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableTableIndexesList(array $tableIndexes, string $tableName): array
    {
        $buffer = [];
        foreach ($tableIndexes as $row) {
            $colNumbers = array_map('intval', explode(' ', $row['indkey']));
            $columnNameSql = sprintf(
                'SELECT attnum, attname FROM pg_attribute WHERE attrelid = %d AND attnum IN (%s) ORDER BY attnum ASC',
                $row['indrelid'],
                implode(', ', $colNumbers),
            );

            $indexColumns = $this->connection->fetchAllAssociative($columnNameSql);

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
     * {@inheritDoc}
     */
    protected function _getPortableDatabaseDefinition(array $database): string
    {
        return $database['datname'];
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableSequenceDefinition(array $sequence): Sequence
    {
        if ('public' !== $sequence['schemaname']) {
            $sequenceName = $sequence['schemaname'] . '.' . $sequence['relname'];
        } else {
            $sequenceName = $sequence['relname'];
        }

        if (!isset($sequence['increment_by'], $sequence['min_value'])) {
            $sequence['min_value'] = 0;
            $sequence['increment_by'] = 0;

            $data = $this->connection->fetchAssociative('SHOW CREATE ' . $this->platform->quoteIdentifier($sequenceName));

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
     * {@inheritDoc}
     */
    protected function _getPortableTableColumnDefinition(array $tableColumn): Column
    {
        $tableColumn = array_change_key_case($tableColumn, CASE_LOWER);

        $length = null;

        if (
            preg_match('/\((\d*)\)/', $tableColumn['complete_type'], $matches) === 1
            && in_array(strtolower($tableColumn['type']), ['varchar', 'bpchar'], true)
        ) {
            $length = (int)$matches[1];
        }

        $autoincrement = 'd' === $tableColumn['attidentity'];
        $matches = [];

        assert(array_key_exists('default', $tableColumn));
        assert(array_key_exists('complete_type', $tableColumn));

        if (null !== $tableColumn['default']) {
            if (preg_match("/^(?:nextval\('([^']+)'(::.*)?\)|unique_rowid\(\))$/", $tableColumn['default'], $matches) === 1) {
                $tableColumn['default'] = null;
                $autoincrement = true;
            } elseif (preg_match("/^['(](.*)[')]::/", $tableColumn['default'], $matches) === 1) {
                $tableColumn['default'] = $matches[1];
            } elseif (str_starts_with($tableColumn['default'], 'NULL::')) {
                $tableColumn['default'] = null;
            }
        }

        if (-1 === $length && isset($tableColumn['atttypmod'])) {
            $length = $tableColumn['atttypmod'] - 4;
        }

        if ((int)$length <= 0) {
            $length = null;
        }

        $fixed = false;

        if (!isset($tableColumn['name'])) {
            $tableColumn['name'] = '';
        }

        $precision = null;
        $scale = 0;
        $jsonb = null;
        $dbType = strtolower($tableColumn['type']);

        if (
            null !== $tableColumn['domain_type']
            && '' !== $tableColumn['domain_type']
            && !$this->platform->hasDoctrineTypeMappingFor($tableColumn['type'])
        ) {
            $dbType = strtolower($tableColumn['domain_type']);
            $tableColumn['complete_type'] = $tableColumn['domain_complete_type'];
        }

        $type = $this->platform->getDoctrineTypeMapping($dbType);

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
                if (
                    preg_match(
                        '([A-Za-z]+\((\d+),(\d+)\))',
                        $tableColumn['complete_type'],
                        $match,
                    ) === 1
                ) {
                    $precision = (int)$match[1];
                    $scale = (int)$match[2];
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
            && preg_match(
                "('([^']+)'::)",
                $tableColumn['default'],
                $match,
            ) === 1
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
        ];

        if (isset($tableColumn['comment'])) {
            $options['comment'] = $tableColumn['comment'];
        }

        $column = new Column($tableColumn['field'], Type::getType($type), $options);

        if (!empty($tableColumn['collation'])) {
            $column->setPlatformOption('collation', $tableColumn['collation']);
        }

        if ($column->getType() instanceof JsonType) {
            $column->setPlatformOption('jsonb', $jsonb);
        }

        return $column;
    }

    /**
     * Parses a default value expression as given by CockroachDB
     */
    private function parseDefaultExpression(?string $default): ?string
    {
        if (null === $default) {
            return null;
        }

        return str_replace("''", "'", $default);
    }

    /**
     * {@inheritDoc}
     */
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

        return $this->connection->executeQuery($sql, [$databaseName]);
    }

    /**
     * {@inheritDoc}
     */
    protected function selectTableColumns(string $databaseName, ?string $tableName = null): Result
    {
        $sql = 'SELECT';

        if (null === $tableName) {
            $sql .= '
                c.relname AS table_name,
                n.nspname AS schema_name,';
        }

        $conditions = ['a.attnum > 0', 'a.attisdropped = false', "c.relkind = 'r'", 'd.refobjid IS NULL'];
        $conditions = array_merge($conditions, $this->buildQueryConditions($tableName));

        $sql = '
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
            a.attidentity,
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
                pg_attribute a
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
                    AND d.classid = (
                        SELECT
                            oid
                        FROM
                            pg_class
                        WHERE
                            relname = 'pg_class'
                    )
                )";

        $conditions = array_merge([
            'a.attnum > 0',
            "c.relkind = 'r'",
            'd.refobjid IS NULL',
        ], $this->buildQueryConditions($tableName));

        $sql .= ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY a.attnum';

        return $this->connection->executeQuery($sql);
    }

    protected function selectIndexColumns(string $databaseName, ?string $tableName = null): Result
    {
        $sql = 'SELECT';

        if (null === $tableName) {
            $sql .= '
                tc.relname AS table_name,
                tn.nspname AS schema_name,';
        }

        $sql .= '
                quote_ident(ic.relname) AS relname,
                i.indisunique,
                i.indisprimary,
                i.indkey,
                i.indrelid,
                pg_get_expr(indpred, indrelid) AS "where"
            FROM
                pg_index i
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
                        pg_index AS i,
                        pg_class AS c,
                        pg_namespace AS n';

        $conditions = array_merge([
            'c.oid = i.indrelid',
            'c.relnamespace = n.oid',
        ], $this->buildQueryConditions($tableName));

        $sql .= ' WHERE ' . implode(' AND ', $conditions) . ')';

        return $this->connection->executeQuery($sql);
    }

    protected function selectForeignKeyColumns(string $databaseName, ?string $tableName = null): Result
    {
        $sql = 'SELECT';

        if (null === $tableName) {
            $sql .= '
                tc.relname AS table_name,
                tn.nspname AS schema_name,';
        }

        $sql .= '
                quote_ident(r.conname) AS conname,
                pg_get_constraintdef(r.oid, true) AS condef
            FROM
                pg_constraint AS r
                JOIN pg_class AS tc ON (
                    tc.oid = r.conrelid
                )
                JOIN pg_namespace tn ON (
                    tn.oid = tc.relnamespace
                )
            WHERE
                r.conrelid IN (
                    SELECT
                        c.oid
                    FROM
                        pg_class AS c,
                        pg_namespace AS n';

        $conditions = array_merge(['n.oid = c.relnamespace'], $this->buildQueryConditions($tableName));

        $sql .= ' WHERE ' . implode(' AND ', $conditions) . ") AND r.contype = 'f'";

        return $this->connection->executeQuery($sql);
    }

    /**
     * {@inheritDoc}
     */
    protected function fetchTableOptionsByTable(string $databaseName, ?string $tableName = null): array
    {
        $sql = "
            SELECT
                c.relname,
                CASE c.relpersistence
                    WHEN 'u' THEN true ELSE false
                END AS unlogged,
                obj_description(c.oid, 'pg_class') AS comment
            FROM
                pg_class AS c
                INNER JOIN pg_namespace AS n ON (
                    n.oid = c.relnamespace
                )";

        $conditions = array_merge(["c.relkind = 'r'"], $this->buildQueryConditions($tableName));

        $sql .= ' WHERE ' . implode(' AND ', $conditions);

        return $this->connection->fetchAllAssociativeIndexed($sql);
    }

    /**
     * @return list<string>
     */
    protected function buildQueryConditions(?string $tableName): array
    {
        $conditions = [];

        if (null !== $tableName) {
            if (str_contains($tableName, '.')) {
                [$schemaName, $tableName] = explode('.', $tableName);
                $conditions[] = 'n.nspname = ' . $this->platform->quoteStringLiteral($schemaName);
            } else {
                $conditions[] = 'n.nspname = ANY(current_schemas(false))';
            }

            $identifier = new Identifier($tableName);
            $conditions[] = 'c.relname = ' . $this->platform->quoteStringLiteral($identifier->getName());
        }

        $conditions[] = "n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast', 'pg_extension', 'crdb_internal')";

        return $conditions;
    }
}
