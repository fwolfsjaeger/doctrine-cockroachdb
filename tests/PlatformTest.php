<?php

declare(strict_types=1);

namespace DoctrineCockroachDB\Tests;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\InvalidLockMode;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Schema\UniqueConstraint;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use DoctrineCockroachDB\Platforms\CockroachDBPlatform;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

use function sprintf;

use UnexpectedValueException;

class PlatformTest extends TestCase
{
    protected CockroachDBPlatform $platform;
    private ?Type $backedUpType = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->platform = $this->createPlatform();
    }

    /**
     * @return void
     */
    public function testQuoteIdentifier(): void
    {
        $c = $this->platform->getIdentifierQuoteCharacter();
        self::assertEquals($c . 'test' . $c, $this->platform->quoteIdentifier('test'));
        self::assertEquals($c . 'test' . $c . '.' . $c . 'test' . $c, $this->platform->quoteIdentifier('test.test'));
        self::assertEquals(str_repeat($c, 4), $this->platform->quoteIdentifier($c));
    }

    /**
     * @return void
     */
    public function testQuoteSingleIdentifier(): void
    {
        $c = $this->platform->getIdentifierQuoteCharacter();
        self::assertEquals($c . 'test' . $c, $this->platform->quoteSingleIdentifier('test'));
        self::assertEquals($c . 'test.test' . $c, $this->platform->quoteSingleIdentifier('test.test'));
        self::assertEquals(str_repeat($c, 4), $this->platform->quoteSingleIdentifier($c));
    }

    /**
     * @dataProvider getReturnsForeignKeyReferentialActionSQL
     *
     * @param string $action
     * @param string $expectedSQL
     * @return void
     */
    public function testReturnsForeignKeyReferentialActionSQL(string $action, string $expectedSQL): void
    {
        self::assertSame($expectedSQL, $this->platform->getForeignKeyReferentialActionSQL($action));
    }

    /**
     * @return array<array{string}>
     */
    public static function getReturnsForeignKeyReferentialActionSQL(): array
    {
        return [
            ['CASCADE', 'CASCADE'],
            ['SET NULL', 'SET NULL'],
            ['NO ACTION', 'NO ACTION'],
            ['RESTRICT', 'RESTRICT'],
            ['SET DEFAULT', 'SET DEFAULT'],
            ['CaScAdE', 'CASCADE'],
        ];
    }

    /**
     * @return void
     */
    public function testGetInvalidForeignKeyReferentialActionSQL(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->platform->getForeignKeyReferentialActionSQL('unknown');
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testCreateWithNoColumns(): void
    {
        $table = new Table('test');

        $this->expectException(Exception::class);
        $this->platform->getCreateTableSQL($table);
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testGeneratesTableCreationSql(): void
    {
        $table = new Table('test');
        $table->addColumn('id', 'integer', ['notnull' => true, 'autoincrement' => true]);
        $table->addColumn('test', 'string', ['notnull' => false, 'length' => 255]);
        $table->setPrimaryKey(['id']);

        $sql = $this->platform->getCreateTableSQL($table);
        self::assertEquals($this->getGenerateTableSql(), $sql[0]);
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testGenerateTableWithMultiColumnUniqueIndex(): void
    {
        $table = new Table('test');
        $table->addColumn('foo', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('bar', 'string', ['notnull' => false, 'length' => 255]);
        $table->addUniqueIndex(['foo', 'bar']);

        $sql = $this->platform->getCreateTableSQL($table);
        self::assertEquals($this->getGenerateTableWithMultiColumnUniqueIndexSql(), $sql);
    }

    /**
     * @return void
     */
    public function testGeneratesIndexCreationSql(): void
    {
        $indexDef = new Index('my_idx', ['user_name', 'last_login']);

        self::assertEquals(
            $this->getGenerateIndexSql(),
            $this->platform->getCreateIndexSQL($indexDef, 'mytable'),
        );
    }

    /**
     * @return void
     */
    public function testGeneratesUniqueIndexCreationSql(): void
    {
        $indexDef = new Index('index_name', ['test', 'test2'], true);

        $sql = $this->platform->getCreateIndexSQL($indexDef, 'test');
        self::assertEquals($this->getGenerateUniqueIndexSql(), $sql);
    }

    /**
     * @return void
     */
    public function testGeneratesPartialIndexesSqlOnlyWhenSupportingPartialIndexes(): void
    {
        $where = 'test IS NULL AND test2 IS NOT NULL';
        $indexDef = new Index('name', ['test', 'test2'], false, false, [], ['where' => $where]);
        $uniqueConstraint = new UniqueConstraint('name', ['test', 'test2'], [], []);

        $expected = ' WHERE ' . $where;

        $indexes = [];
        $indexes[] = $this->platform->getIndexDeclarationSQL('name', $indexDef);

        $uniqueConstraintSQL = $this->platform->getUniqueConstraintDeclarationSQL('name', $uniqueConstraint);
        $this->assertStringEndsNotWith($expected, $uniqueConstraintSQL, 'WHERE clause should NOT be present');

        $indexes[] = $this->platform->getCreateIndexSQL($indexDef, 'table');

        foreach ($indexes as $index) {
            if ($this->platform->supportsPartialIndexes()) {
                self::assertStringEndsWith($expected, $index, 'WHERE clause should be present');
            } else {
                self::assertStringEndsNotWith($expected, $index, 'WHERE clause should NOT be present');
            }
        }
    }

    /**
     * @return void
     */
    public function testGeneratesForeignKeyCreationSql(): void
    {
        $fk = new ForeignKeyConstraint(['fk_name_id'], 'other_table', ['id'], '');
        $sql = $this->platform->getCreateForeignKeySQL($fk, 'test');
        self::assertEquals($sql, $this->getGenerateForeignKeySql());
    }

    /**
     * @return void
     */
    public function testGeneratesConstraintCreationSql(): void
    {
        $idx = new Index('constraint_name', ['test'], true, false);
        $sql = $this->platform->getCreateIndexSQL($idx, 'test');
        self::assertEquals($this->getCreateUniqueIndexSql(), $sql);

        $pk = new Index('constraint_name', ['test'], true, true);
        $sql = $this->platform->getCreateIndexSQL($pk, 'test');
        self::assertEquals($this->getGenerateConstraintPrimaryIndexSql(), $sql);

        $uc = new UniqueConstraint('constraint_name', ['test']);
        $sql = $this->platform->getCreateUniqueConstraintSQL($uc, 'test');
        self::assertEquals($this->getGenerateConstraintUniqueIndexSql(), $sql);

        $fk = new ForeignKeyConstraint(['fk_name'], 'foreign', ['id'], 'constraint_fk');
        $sql = $this->platform->getCreateForeignKeySQL($fk, 'test');
        self::assertEquals($this->getGenerateConstraintForeignKeySql($fk), $sql);
    }

    /**
     * @param string $value1
     * @param string $value2
     * @return string
     */
    protected function getBitAndComparisonExpressionSql(string $value1, string $value2): string
    {
        return '(' . $value1 . ' & ' . $value2 . ')';
    }

    /**
     * @return void
     */
    public function testGeneratesBitAndComparisonExpressionSql(): void
    {
        $sql = $this->platform->getBitAndComparisonExpression('2', '4');
        self::assertEquals($this->getBitAndComparisonExpressionSql('2', '4'), $sql);
    }

    /**
     * @param string $value1
     * @param string $value2
     * @return string
     */
    protected function getBitOrComparisonExpressionSql(string $value1, string $value2): string
    {
        return '(' . $value1 . ' | ' . $value2 . ')';
    }

    /**
     * @return void
     */
    public function testGeneratesBitOrComparisonExpressionSql(): void
    {
        $sql = $this->platform->getBitOrComparisonExpression('2', '4');
        self::assertEquals($this->getBitOrComparisonExpressionSql('2', '4'), $sql);
    }

    /**
     * @return string
     */
    public function getCreateUniqueIndexSql(): string
    {
        return 'CREATE UNIQUE INDEX constraint_name ON test (test)';
    }

    /**
     * @return string
     */
    public function getGenerateConstraintUniqueIndexSql(): string
    {
        return 'ALTER TABLE test ADD CONSTRAINT constraint_name UNIQUE (test)';
    }

    /**
     * @return string
     */
    public function getGenerateConstraintPrimaryIndexSql(): string
    {
        return 'ALTER TABLE test ADD PRIMARY KEY (test)';
    }

    /**
     * @param ForeignKeyConstraint $fk
     * @return string
     */
    public function getGenerateConstraintForeignKeySql(ForeignKeyConstraint $fk): string
    {
        $quotedForeignTable = $fk->getQuotedForeignTableName($this->platform);

        return sprintf(
            'ALTER TABLE test ADD CONSTRAINT constraint_fk FOREIGN KEY (fk_name) REFERENCES %s (id)',
            $quotedForeignTable,
        );
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testGetCustomColumnDeclarationSql(): void
    {
        self::assertEquals(
            'foo MEDIUMINT(6) UNSIGNED',
            $this->platform->getColumnDeclarationSQL('foo', ['columnDefinition' => 'MEDIUMINT(6) UNSIGNED']),
        );
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testCreateTableColumnComments(): void
    {
        $table = new Table('test');
        $table->addColumn('id', 'integer', ['comment' => 'This is a comment']);
        $table->setPrimaryKey(['id']);

        self::assertEquals($this->getCreateTableColumnCommentsSQL(), $this->platform->getCreateTableSQL($table));
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testAlterTableColumnComments(): void
    {
        $tableDiff = new TableDiff('mytable');
        $tableDiff->addedColumns['quota'] = new Column('quota', Type::getType('integer'), ['comment' => 'A comment']);
        $tableDiff->changedColumns['foo'] = new ColumnDiff(
            'foo',
            new Column(
                'foo',
                Type::getType('string'),
            ),
            ['comment'],
        );
        $tableDiff->changedColumns['bar'] = new ColumnDiff(
            'bar',
            new Column(
                'baz',
                Type::getType('string'),
                ['comment' => 'B comment'],
            ),
            ['comment'],
        );

        self::assertEquals($this->getAlterTableColumnCommentsSQL(), $this->platform->getAlterTableSQL($tableDiff));
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testCreateTableColumnTypeComments(): void
    {
        $table = new Table('test');
        $table->addColumn('id', 'integer');
        $table->addColumn('data', 'array');
        $table->setPrimaryKey(['id']);

        self::assertEquals($this->getCreateTableColumnTypeCommentsSQL(), $this->platform->getCreateTableSQL($table));
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testGetDefaultValueDeclarationSQL(): void
    {
        // non-timestamp value will get single quotes
        self::assertEquals(" DEFAULT 'non_timestamp'", $this->platform->getDefaultValueDeclarationSQL([
            'type' => Type::getType('string'),
            'default' => 'non_timestamp',
        ]));
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testGetDefaultValueDeclarationSQLDateTime(): void
    {
        // timestamps on datetime types should not be quoted
        foreach (['datetime', 'datetimetz', 'datetime_immutable', 'datetimetz_immutable'] as $type) {
            self::assertSame(
                ' DEFAULT ' . $this->platform->getCurrentTimestampSQL(),
                $this->platform->getDefaultValueDeclarationSQL([
                    'type' => Type::getType($type),
                    'default' => $this->platform->getCurrentTimestampSQL(),
                ]),
            );
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testGetDefaultValueDeclarationSQLForIntegerTypes(): void
    {
        foreach (['bigint', 'integer', 'smallint'] as $type) {
            self::assertEquals(
                ' DEFAULT 1',
                $this->platform->getDefaultValueDeclarationSQL([
                    'type' => Type::getType($type),
                    'default' => 1,
                ]),
            );
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testGetDefaultValueDeclarationSQLForDateType(): void
    {
        $currentDateSql = $this->platform->getCurrentDateSQL();
        foreach (['date', 'date_immutable'] as $type) {
            self::assertSame(
                ' DEFAULT ' . $currentDateSql,
                $this->platform->getDefaultValueDeclarationSQL([
                    'type' => Type::getType($type),
                    'default' => $currentDateSql,
                ]),
            );
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testKeywordList(): void
    {
        $keywordList = $this->platform->getReservedKeywordsList();
        self::assertInstanceOf(KeywordList::class, $keywordList);
        self::assertTrue($keywordList->isKeyword('table'));
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testQuotedColumnInPrimaryKeyPropagation(): void
    {
        $table = new Table('`quoted`');
        $table->addColumn('create', 'string');
        $table->setPrimaryKey(['create']);

        $sql = $this->platform->getCreateTableSQL($table);
        self::assertEquals($this->getQuotedColumnInPrimaryKeySQL(), $sql);
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testQuotedColumnInIndexPropagation(): void
    {
        $table = new Table('`quoted`');
        $table->addColumn('create', 'string');
        $table->addIndex(['create']);

        $sql = $this->platform->getCreateTableSQL($table);
        self::assertEquals($this->getQuotedColumnInIndexSQL(), $sql);
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testQuotedNameInIndexSQL(): void
    {
        $table = new Table('test');
        $table->addColumn('column1', 'string');
        $table->addIndex(['column1'], '`key`');

        $sql = $this->platform->getCreateTableSQL($table);
        self::assertEquals($this->getQuotedNameInIndexSQL(), $sql);
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testQuotedColumnInForeignKeyPropagation(): void
    {
        $table = new Table('`quoted`');
        $table->addColumn('create', 'string');
        $table->addColumn('foo', 'string');
        $table->addColumn('`bar`', 'string');

        // Foreign table with reserved keyword as name (needs quotation).
        $foreignTable = new Table('foreign');

        // Foreign column with reserved keyword as name (needs quotation).
        $foreignTable->addColumn('create', 'string');

        // Foreign column with non-reserved keyword as name (does not need quotation).
        $foreignTable->addColumn('bar', 'string');

        // Foreign table with special character in name (needs quotation on some platforms, e.g. Sqlite).
        $foreignTable->addColumn('`foo-bar`', 'string');

        $table->addForeignKeyConstraint(
            $foreignTable,
            ['create', 'foo', '`bar`'],
            ['create', 'bar', '`foo-bar`'],
            [],
            'FK_WITH_RESERVED_KEYWORD',
        );

        // Foreign table with non-reserved keyword as name (does not need quotation).
        $foreignTable = new Table('foo');

        // Foreign column with reserved keyword as name (needs quotation).
        $foreignTable->addColumn('create', 'string');

        // Foreign column with non-reserved keyword as name (does not need quotation).
        $foreignTable->addColumn('bar', 'string');

        // Foreign table with special character in name (needs quotation on some platforms, e.g. Sqlite).
        $foreignTable->addColumn('`foo-bar`', 'string');

        $table->addForeignKeyConstraint(
            $foreignTable,
            ['create', 'foo', '`bar`'],
            ['create', 'bar', '`foo-bar`'],
            [],
            'FK_WITH_NON_RESERVED_KEYWORD',
        );

        // Foreign table with special character in name (needs quotation on some platforms, e.g. Sqlite).
        $foreignTable = new Table('`foo-bar`');

        // Foreign column with reserved keyword as name (needs quotation).
        $foreignTable->addColumn('create', 'string');

        // Foreign column with non-reserved keyword as name (does not need quotation).
        $foreignTable->addColumn('bar', 'string');

        // Foreign table with special character in name (needs quotation on some platforms, e.g. Sqlite).
        $foreignTable->addColumn('`foo-bar`', 'string');

        $table->addForeignKeyConstraint(
            $foreignTable,
            ['create', 'foo', '`bar`'],
            ['create', 'bar', '`foo-bar`'],
            [],
            'FK_WITH_INTENDED_QUOTATION',
        );

        $sql = $this->platform->getCreateTableSQL(
            $table,
            AbstractPlatform::CREATE_INDEXES | AbstractPlatform::CREATE_FOREIGNKEYS,
        );

        self::assertEquals($this->getQuotedColumnInForeignKeySQL(), $sql);
    }

    /**
     * @return void
     */
    public function testQuotesReservedKeywordInUniqueConstraintDeclarationSQL(): void
    {
        $constraint = new UniqueConstraint('select', ['foo'], [], []);

        self::assertSame(
            $this->getQuotesReservedKeywordInUniqueConstraintDeclarationSQL(),
            $this->platform->getUniqueConstraintDeclarationSQL('select', $constraint),
        );
    }

    /**
     * @return void
     */
    public function testQuotesReservedKeywordInTruncateTableSQL(): void
    {
        self::assertSame(
            $this->getQuotesReservedKeywordInTruncateTableSQL(),
            $this->platform->getTruncateTableSQL('select'),
        );
    }

    /**
     * @return void
     */
    public function testQuotesReservedKeywordInIndexDeclarationSQL(): void
    {
        $index = new Index('select', ['foo']);

        self::assertSame(
            $this->getQuotesReservedKeywordInIndexDeclarationSQL(),
            $this->platform->getIndexDeclarationSQL('select', $index),
        );
    }

    /**
     * @return void
     */
    public function testSupportsCommentOnStatement(): void
    {
        self::assertSame($this->supportsCommentOnStatement(), $this->platform->supportsCommentOnStatement());
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testAlterTableChangeQuotedColumn(): void
    {
        $table = new Table('mytable');
        $table->addColumn('select', 'integer');

        $tableDiff = new TableDiff('mytable');
        $tableDiff->fromTable = $table;
        $tableDiff->changedColumns['select'] = new ColumnDiff(
            'select',
            new Column(
                'select',
                Type::getType('string'),
            ),
            ['type'],
        );

        self::assertStringContainsString(
            $this->platform->quoteIdentifier('select'),
            implode(';', $this->platform->getAlterTableSQL($tableDiff)),
        );
    }

    /**
     * @return void
     */
    public function testReturnsBinaryDefaultLength(): void
    {
        self::assertSame($this->getBinaryDefaultLength(), $this->platform->getBinaryDefaultLength());
    }

    /**
     * @return int
     */
    protected function getBinaryDefaultLength(): int
    {
        return 0;
    }

    /**
     * @return void
     */
    public function testReturnsBinaryMaxLength(): void
    {
        self::assertSame($this->getBinaryMaxLength(), $this->platform->getBinaryMaxLength());
    }

    /**
     * @return int
     */
    protected function getBinaryMaxLength(): int
    {
        return 0;
    }

    /**
     * @return void
     */
    public function hasNativeJsonType(): void
    {
        self::assertTrue($this->platform->hasNativeJsonType());
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testAlterTableRenameIndex(): void
    {
        $tableDiff = new TableDiff('mytable');
        $tableDiff->fromTable = new Table('mytable');
        $tableDiff->fromTable->addColumn('id', 'integer');
        $tableDiff->fromTable->setPrimaryKey(['id']);
        $tableDiff->renamedIndexes = [
            'idx_foo' => new Index('idx_bar', ['id']),
        ];

        self::assertSame(
            $this->getAlterTableRenameIndexSQL(),
            $this->platform->getAlterTableSQL($tableDiff),
        );
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testQuotesAlterTableRenameIndex(): void
    {
        $tableDiff = new TableDiff('table');
        $tableDiff->fromTable = new Table('table');
        $tableDiff->fromTable->addColumn('id', 'integer');
        $tableDiff->fromTable->setPrimaryKey(['id']);
        $tableDiff->renamedIndexes = [
            'create' => new Index('select', ['id']),
            '`foo`' => new Index('`bar`', ['id']),
        ];

        self::assertSame(
            $this->getQuotedAlterTableRenameIndexSQL(),
            $this->platform->getAlterTableSQL($tableDiff),
        );
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testQuotesAlterTableRenameColumn(): void
    {
        $fromTable = new Table('mytable');

        $fromTable->addColumn('unquoted1', 'integer', ['comment' => 'Unquoted 1']);
        $fromTable->addColumn('unquoted2', 'integer', ['comment' => 'Unquoted 2']);
        $fromTable->addColumn('unquoted3', 'integer', ['comment' => 'Unquoted 3']);

        $fromTable->addColumn('create', 'integer', ['comment' => 'Reserved keyword 1']);
        $fromTable->addColumn('table', 'integer', ['comment' => 'Reserved keyword 2']);
        $fromTable->addColumn('select', 'integer', ['comment' => 'Reserved keyword 3']);

        $fromTable->addColumn('`quoted1`', 'integer', ['comment' => 'Quoted 1']);
        $fromTable->addColumn('`quoted2`', 'integer', ['comment' => 'Quoted 2']);
        $fromTable->addColumn('`quoted3`', 'integer', ['comment' => 'Quoted 3']);

        $toTable = new Table('mytable');

        // unquoted -> unquoted
        $toTable->addColumn('unquoted', 'integer', ['comment' => 'Unquoted 1']);

        // unquoted -> reserved keyword
        $toTable->addColumn('where', 'integer', ['comment' => 'Unquoted 2']);

        // unquoted -> quoted
        $toTable->addColumn('`foo`', 'integer', ['comment' => 'Unquoted 3']);

        // reserved keyword -> unquoted
        $toTable->addColumn('reserved_keyword', 'integer', ['comment' => 'Reserved keyword 1']);

        // reserved keyword -> reserved keyword
        $toTable->addColumn('from', 'integer', ['comment' => 'Reserved keyword 2']);

        // reserved keyword -> quoted
        $toTable->addColumn('`bar`', 'integer', ['comment' => 'Reserved keyword 3']);

        // quoted -> unquoted
        $toTable->addColumn('quoted', 'integer', ['comment' => 'Quoted 1']);

        // quoted -> reserved keyword
        $toTable->addColumn('and', 'integer', ['comment' => 'Quoted 2']);

        // quoted -> quoted
        $toTable->addColumn('`baz`', 'integer', ['comment' => 'Quoted 3']);

        $diff = (new Comparator())->diffTable($fromTable, $toTable);

        self::assertNotFalse($diff);
        self::assertEquals(
            $this->getQuotedAlterTableRenameColumnSQL(),
            $this->platform->getAlterTableSQL($diff),
        );
    }

    /**
     * Returns SQL statements for {@link testQuotesAlterTableRenameColumn}.
     *
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testQuotesAlterTableChangeColumnLength(): void
    {
        $fromTable = new Table('mytable');

        $fromTable->addColumn('unquoted1', 'string', ['comment' => 'Unquoted 1', 'length' => 10]);
        $fromTable->addColumn('unquoted2', 'string', ['comment' => 'Unquoted 2', 'length' => 10]);
        $fromTable->addColumn('unquoted3', 'string', ['comment' => 'Unquoted 3', 'length' => 10]);

        $fromTable->addColumn('create', 'string', ['comment' => 'Reserved keyword 1', 'length' => 10]);
        $fromTable->addColumn('table', 'string', ['comment' => 'Reserved keyword 2', 'length' => 10]);
        $fromTable->addColumn('select', 'string', ['comment' => 'Reserved keyword 3', 'length' => 10]);

        $toTable = new Table('mytable');

        $toTable->addColumn('unquoted1', 'string', ['comment' => 'Unquoted 1', 'length' => 255]);
        $toTable->addColumn('unquoted2', 'string', ['comment' => 'Unquoted 2', 'length' => 255]);
        $toTable->addColumn('unquoted3', 'string', ['comment' => 'Unquoted 3', 'length' => 255]);

        $toTable->addColumn('create', 'string', ['comment' => 'Reserved keyword 1', 'length' => 255]);
        $toTable->addColumn('table', 'string', ['comment' => 'Reserved keyword 2', 'length' => 255]);
        $toTable->addColumn('select', 'string', ['comment' => 'Reserved keyword 3', 'length' => 255]);

        $diff = (new Comparator())->diffTable($fromTable, $toTable);
        self::assertNotFalse($diff);

        self::assertEquals(
            $this->getQuotedAlterTableChangeColumnLengthSQL(),
            $this->platform->getAlterTableSQL($diff),
        );
    }

    /**
     * Returns SQL statements for {@link testQuotesAlterTableChangeColumnLength}.
     *
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testAlterTableRenameIndexInSchema(): void
    {
        $tableDiff = new TableDiff('myschema.mytable');
        $tableDiff->fromTable = new Table('myschema.mytable');
        $tableDiff->fromTable->addColumn('id', 'integer');
        $tableDiff->fromTable->setPrimaryKey(['id']);
        $tableDiff->renamedIndexes = [
            'idx_foo' => new Index('idx_bar', ['id']),
        ];

        self::assertSame(
            $this->getAlterTableRenameIndexInSchemaSQL(),
            $this->platform->getAlterTableSQL($tableDiff),
        );
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testQuotesAlterTableRenameIndexInSchema(): void
    {
        $tableDiff = new TableDiff('`schema`.table');
        $tableDiff->fromTable = new Table('`schema`.table');
        $tableDiff->fromTable->addColumn('id', 'integer');
        $tableDiff->fromTable->setPrimaryKey(['id']);
        $tableDiff->renamedIndexes = [
            'create' => new Index('select', ['id']),
            '`foo`' => new Index('`bar`', ['id']),
        ];

        self::assertSame(
            $this->getQuotedAlterTableRenameIndexInSchemaSQL(),
            $this->platform->getAlterTableSQL($tableDiff),
        );
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testQuotesDropForeignKeySQL(): void
    {
        $tableName = 'table';
        $table = new Table($tableName);
        $foreignKeyName = 'select';
        $foreignKey = new ForeignKeyConstraint([], 'foo', [], 'select');
        $expectedSql = $this->getQuotesDropForeignKeySQL();

        self::assertSame($expectedSql, $this->platform->getDropForeignKeySQL($foreignKeyName, $tableName));
        self::assertSame($expectedSql, $this->platform->getDropForeignKeySQL($foreignKey, $table));
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testQuotesDropConstraintSQL(): void
    {
        $tableName = 'table';
        $table = new Table($tableName);
        $constraintName = 'select';
        $constraint = new ForeignKeyConstraint([], 'foo', [], 'select');
        $expectedSql = $this->getQuotesDropConstraintSQL();

        self::assertSame($expectedSql, $this->platform->getDropConstraintSQL($constraintName, $tableName));
        self::assertSame($expectedSql, $this->platform->getDropConstraintSQL($constraint, $table));
    }

    /**
     * @return string
     */
    protected function getQuotesDropConstraintSQL(): string
    {
        return 'ALTER TABLE "table" DROP CONSTRAINT "select"';
    }

    /**
     * @return string
     */
    protected function getStringLiteralQuoteCharacter(): string
    {
        return "'";
    }

    /**
     * @return void
     */
    public function testGetStringLiteralQuoteCharacter(): void
    {
        self::assertSame($this->getStringLiteralQuoteCharacter(), $this->platform->getStringLiteralQuoteCharacter());
    }

    /**
     * @return string
     */
    protected function getQuotedCommentOnColumnSQLWithoutQuoteCharacter(): string
    {
        return "COMMENT ON COLUMN mytable.id IS 'This is a comment'";
    }

    /**
     * @return void
     */
    public function testGetCommentOnColumnSQLWithoutQuoteCharacter(): void
    {
        self::assertEquals(
            $this->getQuotedCommentOnColumnSQLWithoutQuoteCharacter(),
            $this->platform->getCommentOnColumnSQL('mytable', 'id', 'This is a comment'),
        );
    }

    /**
     * @return string
     */
    protected function getQuotedCommentOnColumnSQLWithQuoteCharacter(): string
    {
        return "COMMENT ON COLUMN mytable.id IS 'It''s a quote !'";
    }

    /**
     * @return void
     */
    public function testGetCommentOnColumnSQLWithQuoteCharacter(): void
    {
        $c = $this->getStringLiteralQuoteCharacter();

        self::assertEquals(
            $this->getQuotedCommentOnColumnSQLWithQuoteCharacter(),
            $this->platform->getCommentOnColumnSQL('mytable', 'id', 'It' . $c . 's a quote !'),
        );
    }

    /**
     * @see testGetCommentOnColumnSQL
     */
    public function testGetCommentOnColumnSQL(): void
    {
        self::assertSame(
            $this->getCommentOnColumnSQL(),
            [
                $this->platform->getCommentOnColumnSQL('foo', 'bar', 'comment'), // regular identifiers
                $this->platform->getCommentOnColumnSQL('`Foo`', '`BAR`', 'comment'), // explicitly quoted identifiers
                $this->platform->getCommentOnColumnSQL('select', 'from', 'comment'), // reserved keyword identifiers
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function getGeneratesInlineColumnCommentSQL(): array
    {
        return [
            'regular comment' => ['Regular comment', static::getInlineColumnRegularCommentSQL()],
            'comment requiring escaping' => [
                sprintf(
                    'Using inline comment delimiter %s works',
                    static::getInlineColumnCommentDelimiter(),
                ),
                static::getInlineColumnCommentRequiringEscapingSQL(),
            ],
            'empty comment' => ['', static::getInlineColumnEmptyCommentSQL()],
        ];
    }

    /**
     * @return string
     */
    protected static function getInlineColumnCommentDelimiter(): string
    {
        return "'";
    }

    /**
     * @return string
     */
    protected static function getInlineColumnRegularCommentSQL(): string
    {
        return "COMMENT 'Regular comment'";
    }

    /**
     * @return string
     */
    protected static function getInlineColumnCommentRequiringEscapingSQL(): string
    {
        return "COMMENT 'Using inline comment delimiter '' works'";
    }

    /**
     * @return string
     */
    protected static function getInlineColumnEmptyCommentSQL(): string
    {
        return "COMMENT ''";
    }

    /**
     * @return string
     */
    protected function getQuotedStringLiteralWithoutQuoteCharacter(): string
    {
        return "'No quote'";
    }

    /**
     * @return string
     */
    protected function getQuotedStringLiteralWithQuoteCharacter(): string
    {
        return "'It''s a quote'";
    }

    /**
     * @return string
     */
    protected function getQuotedStringLiteralQuoteCharacter(): string
    {
        return "''''";
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testThrowsExceptionOnGeneratingInlineColumnCommentSQLIfUnsupported(): void
    {
        if ($this->platform->supportsInlineColumnComments()) {
            self::markTestSkipped(sprintf('%s supports inline column comments.', get_class($this->platform)));
        }

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(
            "Operation '" . AbstractPlatform::class . "::getInlineColumnCommentSQL' is not supported by platform.",
        );
        $this->expectExceptionCode(0);

        $this->platform->getInlineColumnCommentSQL('unsupported');
    }

    /**
     * @return void
     */
    public function testQuoteStringLiteral(): void
    {
        $c = $this->getStringLiteralQuoteCharacter();

        self::assertEquals(
            $this->getQuotedStringLiteralWithoutQuoteCharacter(),
            $this->platform->quoteStringLiteral('No quote'),
        );
        self::assertEquals(
            $this->getQuotedStringLiteralWithQuoteCharacter(),
            $this->platform->quoteStringLiteral('It' . $c . 's a quote'),
        );
        self::assertEquals(
            $this->getQuotedStringLiteralQuoteCharacter(),
            $this->platform->quoteStringLiteral($c),
        );
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testGeneratesAlterTableRenameColumnSQL(): void
    {
        $table = new Table('foo');
        $table->addColumn(
            'bar',
            'integer',
            ['notnull' => true, 'default' => 666, 'comment' => 'rename test'],
        );

        $tableDiff = new TableDiff('foo');
        $tableDiff->fromTable = $table;
        $tableDiff->renamedColumns['bar'] = new Column(
            'baz',
            Type::getType('integer'),
            ['notnull' => true, 'default' => 666, 'comment' => 'rename test'],
        );

        self::assertSame($this->getAlterTableRenameColumnSQL(), $this->platform->getAlterTableSQL($tableDiff));
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testAlterStringToFixedString(): void
    {
        $table = new Table('mytable');
        $table->addColumn('name', 'string', ['length' => 2]);

        $tableDiff = new TableDiff('mytable');
        $tableDiff->fromTable = $table;

        $tableDiff->changedColumns['name'] = new ColumnDiff(
            'name',
            new Column(
                'name',
                Type::getType('string'),
                ['fixed' => true, 'length' => 2],
            ),
            ['fixed'],
        );

        $sql = $this->platform->getAlterTableSQL($tableDiff);

        $expectedSql = $this->getAlterStringToFixedStringSQL();

        self::assertEquals($expectedSql, $sql);
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testGeneratesAlterTableRenameIndexUsedByForeignKeySQL(): void
    {
        $foreignTable = new Table('foreign_table');
        $foreignTable->addColumn('id', 'integer');
        $foreignTable->setPrimaryKey(['id']);

        $primaryTable = new Table('mytable');
        $primaryTable->addColumn('foo', 'integer');
        $primaryTable->addColumn('bar', 'integer');
        $primaryTable->addColumn('baz', 'integer');
        $primaryTable->addIndex(['foo'], 'idx_foo');
        $primaryTable->addIndex(['bar'], 'idx_bar');
        $primaryTable->addForeignKeyConstraint($foreignTable, ['foo'], ['id'], [], 'fk_foo');
        $primaryTable->addForeignKeyConstraint($foreignTable, ['bar'], ['id'], [], 'fk_bar');

        $tableDiff = new TableDiff('mytable');
        $tableDiff->fromTable = $primaryTable;
        $tableDiff->renamedIndexes['idx_foo'] = new Index('idx_foo_renamed', ['foo']);

        self::assertSame(
            $this->getGeneratesAlterTableRenameIndexUsedByForeignKeySQL(),
            $this->platform->getAlterTableSQL($tableDiff),
        );
    }

    /**
     * @dataProvider getGeneratesDecimalTypeDeclarationSQL
     *
     * @param array<string,mixed> $column
     * @param string $expectedSql
     * @return void
     */
    public function testGeneratesDecimalTypeDeclarationSQL(array $column, string $expectedSql): void
    {
        self::assertSame($expectedSql, $this->platform->getDecimalTypeDeclarationSQL($column));
    }

    /**
     * @return array<array{mixed}>
     */
    public static function getGeneratesDecimalTypeDeclarationSQL(): array
    {
        return [
            [[], 'NUMERIC(10, 0)'],
            [['unsigned' => true], 'NUMERIC(10, 0)'],
            [['unsigned' => false], 'NUMERIC(10, 0)'],
            [['precision' => 5], 'NUMERIC(5, 0)'],
            [['scale' => 5], 'NUMERIC(10, 5)'],
            [['precision' => 8, 'scale' => 2], 'NUMERIC(8, 2)'],
        ];
    }

    /**
     * @dataProvider getGeneratesFloatDeclarationSQL
     *
     * @param array<string,mixed> $column
     * @param string $expectedSql
     * @return void
     */
    public function testGeneratesFloatDeclarationSQL(array $column, string $expectedSql): void
    {
        self::assertSame($expectedSql, $this->platform->getFloatDeclarationSQL($column));
    }

    /**
     * @return array<array{mixed}>
     */
    public static function getGeneratesFloatDeclarationSQL(): array
    {
        return [
            [[], 'DOUBLE PRECISION'],
            [['unsigned' => true], 'DOUBLE PRECISION'],
            [['unsigned' => false], 'DOUBLE PRECISION'],
            [['precision' => 5], 'DOUBLE PRECISION'],
            [['scale' => 5], 'DOUBLE PRECISION'],
            [['precision' => 8, 'scale' => 2], 'DOUBLE PRECISION'],
        ];
    }

    /**
     * @return void
     */
    public function testItEscapesStringsForLike(): void
    {
        self::assertSame(
            '\_25\% off\_ your next purchase \\\\o/',
            $this->platform->escapeStringForLike('_25% off_ your next purchase \o/', '\\'),
        );
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testZeroOffsetWithoutLimitIsIgnored(): void
    {
        $query = 'SELECT * FROM user';

        self::assertSame(
            $query,
            $this->platform->modifyLimitQuery($query, null, 0),
        );
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testLimitOffsetCastToInt(): void
    {
        self::assertSame(
            $this->getLimitOffsetCastToIntExpectedQuery(),
            $this->platform->modifyLimitQuery('SELECT * FROM user', '1 BANANA', '2 APPLES'),
        );
    }

    /**
     * @return string
     */
    protected function getLimitOffsetCastToIntExpectedQuery(): string
    {
        return 'SELECT * FROM user LIMIT 1 OFFSET 2';
    }

    /**
     * @dataProvider asciiStringSqlDeclarationDataProvider
     *
     * @param array<string,mixed> $column
     */
    public function testAsciiSQLDeclaration(string $expectedSql, array $column): void
    {
        $declarationSql = $this->platform->getAsciiStringTypeDeclarationSQL($column);
        self::assertEquals($expectedSql, $declarationSql);
    }

    /**
     * @return array<int, array{string, array<string, mixed>}>
     */
    public static function asciiStringSqlDeclarationDataProvider(): array
    {
        return [
            ['VARCHAR(12)', ['length' => 12]],
            ['CHAR(12)', ['length' => 12, 'fixed' => true]],
        ];
    }

    /**
     * @return void
     * @throws InvalidLockMode
     */
    public function testInvalidLockMode(): void
    {
        $this->expectException(InvalidLockMode::class);
        $this->platform->appendLockHint('TABLE', 128);
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testItAddsCommentsForOverridingTypes(): void
    {
        $this->backedUpType = Type::getType(Types::STRING);
        self::assertFalse($this->platform->isCommentedDoctrineType($this->backedUpType));
        $type = new class() extends StringType {
            public function getName(): string
            {
                return Types::STRING;
            }

            public function requiresSQLCommentHint(AbstractPlatform $platform): bool
            {
                return true;
            }
        };
        Type::getTypeRegistry()->override(Types::STRING, $type);
        self::assertTrue($this->platform->isCommentedDoctrineType($type));
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testEmptyTableDiff(): void
    {
        $diff = new TableDiff('test');

        self::assertTrue($diff->isEmpty());
        self::assertSame([], $this->platform->getAlterTableSQL($diff));
    }

    /**
     * @return void
     */
    public function testEmptySchemaDiff(): void
    {
        $diff = new SchemaDiff();

        self::assertTrue($diff->isEmpty());
        self::assertSame([], $this->platform->getAlterSchemaSQL($diff));
    }

    /**
     * @return void
     * @throws Exception
     */
    public function tearDown(): void
    {
        if (!isset($this->backedUpType)) {
            return;
        }

        Type::getTypeRegistry()->override(Types::STRING, $this->backedUpType);
        $this->backedUpType = null;
    }

    /**
     * @return CockroachDBPlatform
     */
    public function createPlatform(): CockroachDBPlatform
    {
        return new CockroachDBPlatform();
    }

    /**
     * @return string
     */
    public function getGenerateTableSql(): string
    {
        return 'CREATE TABLE test (id SERIAL4 NOT NULL, test VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))';
    }

    /**
     * @return string[]
     */
    public function getGenerateTableWithMultiColumnUniqueIndexSql(): array
    {
        return [
            'CREATE TABLE test (foo VARCHAR(255) DEFAULT NULL, bar VARCHAR(255) DEFAULT NULL)',
            'CREATE UNIQUE INDEX UNIQ_D87F7E0C8C73652176FF8CAA ON test (foo, bar)',
        ];
    }

    /**
     * @return string
     */
    public function getGenerateIndexSql(): string
    {
        return 'CREATE INDEX my_idx ON mytable (user_name, last_login)';
    }

    /**
     * @return string
     */
    protected function getGenerateForeignKeySql(): string
    {
        return 'ALTER TABLE test ADD FOREIGN KEY (fk_name_id)'
            . ' REFERENCES other_table (id)';
    }

    /**
     * @return void
     */
    public function testGeneratesForeignKeySqlForNonStandardOptions(): void
    {
        $foreignKey = new ForeignKeyConstraint(
            ['foreign_id'],
            'my_table',
            ['id'],
            'my_fk',
            ['onDelete' => 'CASCADE'],
        );
        self::assertEquals(
            'CONSTRAINT my_fk FOREIGN KEY (foreign_id)'
            . ' REFERENCES my_table (id) ON DELETE CASCADE',
            $this->platform->getForeignKeyDeclarationSQL($foreignKey),
        );

        $foreignKey = new ForeignKeyConstraint(
            ['foreign_id'],
            'my_table',
            ['id'],
            'my_fk',
            ['match' => 'full'],
        );
        self::assertEquals(
            'CONSTRAINT my_fk FOREIGN KEY (foreign_id)'
            . ' REFERENCES my_table (id) MATCH full',
            $this->platform->getForeignKeyDeclarationSQL($foreignKey),
        );

        $foreignKey = new ForeignKeyConstraint(
            ['foreign_id'],
            'my_table',
            ['id'],
            'my_fk',
            ['deferrable' => true],
        );
        self::assertEquals(
            'CONSTRAINT my_fk FOREIGN KEY (foreign_id)'
            . ' REFERENCES my_table (id)',
            $this->platform->getForeignKeyDeclarationSQL($foreignKey),
        );

        $foreignKey = new ForeignKeyConstraint(
            ['foreign_id'],
            'my_table',
            ['id'],
            'my_fk',
            ['deferred' => true],
        );
        self::assertEquals(
            'CONSTRAINT my_fk FOREIGN KEY (foreign_id)'
            . ' REFERENCES my_table (id)',
            $this->platform->getForeignKeyDeclarationSQL($foreignKey),
        );

        $foreignKey = new ForeignKeyConstraint(
            ['foreign_id'],
            'my_table',
            ['id'],
            'my_fk',
            ['feferred' => true],
        );
        self::assertEquals(
            'CONSTRAINT my_fk FOREIGN KEY (foreign_id)'
            . ' REFERENCES my_table (id)',
            $this->platform->getForeignKeyDeclarationSQL($foreignKey),
        );

        $foreignKey = new ForeignKeyConstraint(
            ['foreign_id'],
            'my_table',
            ['id'],
            'my_fk',
            ['deferrable' => true, 'deferred' => true, 'match' => 'full'],
        );
        self::assertEquals(
            'CONSTRAINT my_fk FOREIGN KEY (foreign_id)'
            . ' REFERENCES my_table (id) MATCH full',
            $this->platform->getForeignKeyDeclarationSQL($foreignKey),
        );
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testGeneratesSqlSnippets(): void
    {
        self::assertEquals('SIMILAR TO', $this->platform->getRegexpExpression());
        self::assertEquals('"', $this->platform->getIdentifierQuoteCharacter());

        self::assertEquals(
            'column1 || column2 || column3',
            $this->platform->getConcatExpression('column1', 'column2', 'column3'),
        );

        self::assertEquals('SUBSTRING(column FROM 5)', $this->platform->getSubstringExpression('column', 5));
        self::assertEquals('SUBSTRING(column FROM 1 FOR 5)', $this->platform->getSubstringExpression('column', 1, 5));
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testGeneratesTransactionCommands(): void
    {
        self::assertEquals(
            'SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ UNCOMMITTED',
            $this->platform->getSetTransactionIsolationSQL(TransactionIsolationLevel::READ_UNCOMMITTED),
        );
        self::assertEquals(
            'SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED',
            $this->platform->getSetTransactionIsolationSQL(TransactionIsolationLevel::READ_COMMITTED),
        );
        self::assertEquals(
            'SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            $this->platform->getSetTransactionIsolationSQL(TransactionIsolationLevel::REPEATABLE_READ),
        );
        self::assertEquals(
            'SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE',
            $this->platform->getSetTransactionIsolationSQL(TransactionIsolationLevel::SERIALIZABLE),
        );
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testGeneratesDDLSnippets(): void
    {
        self::assertEquals('CREATE DATABASE foobar', $this->platform->getCreateDatabaseSQL('foobar'));
        self::assertEquals('DROP DATABASE foobar', $this->platform->getDropDatabaseSQL('foobar'));
        self::assertEquals('DROP TABLE foobar', $this->platform->getDropTableSQL('foobar'));
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testGenerateTableWithAutoincrement(): void
    {
        $table = new Table('autoinc_table');
        $column = $table->addColumn('id', 'integer');
        $column->setAutoincrement(true);

        self::assertEquals(
            ['CREATE TABLE autoinc_table (id SERIAL4 NOT NULL)'],
            $this->platform->getCreateTableSQL($table),
        );
    }

    /**
     * @return array<array{string}>
     */
    public static function serialTypes(): array
    {
        return [
            ['integer', 'SERIAL4'],
            ['bigint', 'SERIAL8'],
        ];
    }

    /**
     * @dataProvider serialTypes
     *
     * @param string $type
     * @param string $definition
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testGenerateTableWithAutoincrementDoesNotSetDefault(string $type, string $definition): void
    {
        $table = new Table('autoinc_table_notnull');
        $column = $table->addColumn('id', $type);
        $column->setAutoincrement(true);
        $column->setNotnull(false);

        $sql = $this->platform->getCreateTableSQL($table);

        self::assertEquals([sprintf('CREATE TABLE autoinc_table_notnull (id %s)', $definition)], $sql);
    }

    /**
     * @dataProvider serialTypes
     *
     * @param string $type
     * @param string $definition
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testCreateTableWithAutoincrementAndNotNullAddsConstraint(string $type, string $definition): void
    {
        $table = new Table('autoinc_table_notnull_enabled');
        $column = $table->addColumn('id', $type);
        $column->setAutoincrement(true);
        $column->setNotnull(true);

        $sql = $this->platform->getCreateTableSQL($table);

        self::assertEquals([sprintf('CREATE TABLE autoinc_table_notnull_enabled (id %s NOT NULL)', $definition)], $sql);
    }

    /**
     * @dataProvider serialTypes
     *
     * @param string $type
     * @return void
     * @throws Exception
     */
    public function testGetDefaultValueDeclarationSQLIgnoresTheDefaultKeyWhenTheFieldIsSerial(string $type): void
    {
        $sql = $this->platform->getDefaultValueDeclarationSQL(
            [
                'autoincrement' => true,
                'type' => Type::getType($type),
                'default' => 1,
            ],
        );

        self::assertSame('', $sql);
    }

    /**
     * @return void
     */
    public function testGeneratesTypeDeclarationForIntegers(): void
    {
        self::assertEquals(
            'INT4',
            $this->platform->getIntegerTypeDeclarationSQL([]),
        );
        self::assertEquals(
            'SERIAL4',
            $this->platform->getIntegerTypeDeclarationSQL(['autoincrement' => true]),
        );
        self::assertEquals(
            'SERIAL4',
            $this->platform->getIntegerTypeDeclarationSQL(
                ['autoincrement' => true, 'primary' => true],
            ),
        );
    }

    /**
     * @return void
     */
    public function testGeneratesTypeDeclarationForStrings(): void
    {
        self::assertEquals(
            'CHAR(10)',
            $this->platform->getStringTypeDeclarationSQL(
                ['length' => 10, 'fixed' => true],
            ),
        );
        self::assertEquals(
            'VARCHAR(50)',
            $this->platform->getStringTypeDeclarationSQL(['length' => 50]),
        );
        self::assertEquals(
            'VARCHAR(255)',
            $this->platform->getStringTypeDeclarationSQL([]),
        );
    }

    /**
     * @return string
     */
    public function getGenerateUniqueIndexSql(): string
    {
        return 'CREATE UNIQUE INDEX index_name ON test (test, test2)';
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testGeneratesSequenceSqlCommands(): void
    {
        $sequence = new Sequence('myseq', 20, 1);
        self::assertEquals(
            'CREATE SEQUENCE myseq INCREMENT BY 20 MINVALUE 1 START 1',
            $this->platform->getCreateSequenceSQL($sequence),
        );
        self::assertEquals(
            'DROP SEQUENCE myseq CASCADE',
            $this->platform->getDropSequenceSQL('myseq'),
        );
        self::assertEquals(
            "SELECT NEXTVAL('myseq')",
            $this->platform->getSequenceNextValSQL('myseq'),
        );
    }

    /**
     * @return void
     */
    public function testDoesNotPreferIdentityColumns(): void
    {
        self::assertFalse($this->platform->prefersIdentityColumns());
    }

    /**
     * @return void
     */
    public function testSupportsIdentityColumns(): void
    {
        self::assertTrue($this->platform->supportsIdentityColumns());
    }

    /**
     * @return void
     */
    public function testSupportsSavePoints(): void
    {
        self::assertTrue($this->platform->supportsSavepoints());
    }

    /**
     * @return void
     */
    public function testSupportsSequences(): void
    {
        self::assertTrue($this->platform->supportsSequences());
    }

    /**
     * @return bool
     */
    protected function supportsCommentOnStatement(): bool
    {
        return true;
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testModifyLimitQuery(): void
    {
        $sql = $this->platform->modifyLimitQuery('SELECT * FROM user', 10, 0);
        self::assertEquals('SELECT * FROM user LIMIT 10', $sql);
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testModifyLimitQueryWithEmptyOffset(): void
    {
        $sql = $this->platform->modifyLimitQuery('SELECT * FROM user', 10);
        self::assertEquals('SELECT * FROM user LIMIT 10', $sql);
    }

    /**
     * @return string[]
     */
    public function getCreateTableColumnCommentsSQL(): array
    {
        return [
            'CREATE TABLE test (id INT4 NOT NULL, PRIMARY KEY(id))',
            "COMMENT ON COLUMN test.id IS 'This is a comment'",
        ];
    }

    /**
     * @return string[]
     */
    public function getAlterTableColumnCommentsSQL(): array
    {
        return [
            'ALTER TABLE mytable ADD quota INT4 NOT NULL',
            "COMMENT ON COLUMN mytable.quota IS 'A comment'",
            'COMMENT ON COLUMN mytable.foo IS NULL',
            "COMMENT ON COLUMN mytable.baz IS 'B comment'",
        ];
    }

    /**
     * @return string[]
     */
    public function getCreateTableColumnTypeCommentsSQL(): array
    {
        return [
            'CREATE TABLE test (id INT4 NOT NULL, data TEXT NOT NULL, PRIMARY KEY(id))',
            "COMMENT ON COLUMN test.data IS '(DC2Type:array)'",
        ];
    }

    /**
     * @return string[]
     */
    protected function getQuotedColumnInPrimaryKeySQL(): array
    {
        return ['CREATE TABLE "quoted" ("create" VARCHAR(255) NOT NULL, PRIMARY KEY("create"))'];
    }

    /**
     * @return string[]
     */
    protected function getQuotedColumnInIndexSQL(): array
    {
        return [
            'CREATE TABLE "quoted" ("create" VARCHAR(255) NOT NULL)',
            'CREATE INDEX IDX_22660D028FD6E0FB ON "quoted" ("create")',
        ];
    }

    /**
     * @return string[]
     */
    protected function getQuotedNameInIndexSQL(): array
    {
        return [
            'CREATE TABLE test (column1 VARCHAR(255) NOT NULL)',
            'CREATE INDEX "key" ON test (column1)',
        ];
    }

    /**
     * @return string[]
     */
    protected function getQuotedColumnInForeignKeySQL(): array
    {
        return [
            'CREATE TABLE "quoted" ("create" VARCHAR(255) NOT NULL, '
            . 'foo VARCHAR(255) NOT NULL, "bar" VARCHAR(255) NOT NULL)',
            'CREATE INDEX IDX_22660D028FD6E0FB8C736521D79164E3 ON "quoted" ("create", foo, "bar")',
            'ALTER TABLE "quoted" ADD CONSTRAINT FK_WITH_RESERVED_KEYWORD FOREIGN KEY ("create", foo, "bar")'
            . ' REFERENCES "foreign" ("create", bar, "foo-bar")',
            'ALTER TABLE "quoted" ADD CONSTRAINT FK_WITH_NON_RESERVED_KEYWORD FOREIGN KEY ("create", foo, "bar")'
            . ' REFERENCES foo ("create", bar, "foo-bar")',
            'ALTER TABLE "quoted" ADD CONSTRAINT FK_WITH_INTENDED_QUOTATION FOREIGN KEY ("create", foo, "bar")'
            . ' REFERENCES "foo-bar" ("create", bar, "foo-bar")',
        ];
    }

    /**
     * @dataProvider pgBooleanProvider
     *
     * @param bool|string|null $databaseValue
     * @param string $preparedStatementValue
     * @return void
     */
    public function testConvertBooleanAsLiteralStrings(
        bool|string|null $databaseValue,
        string $preparedStatementValue,
    ): void {
        $platform = $this->createPlatform();

        self::assertEquals($preparedStatementValue, $platform->convertBooleans($databaseValue));
    }

    /**
     * @return void
     */
    public function testConvertBooleanAsLiteralIntegers(): void
    {
        $platform = $this->createPlatform();
        $platform->setUseBooleanTrueFalseStrings(false);

        self::assertEquals(1, $platform->convertBooleans(true));
        self::assertEquals(1, $platform->convertBooleans('1'));

        self::assertEquals(0, $platform->convertBooleans(false));
        self::assertEquals(0, $platform->convertBooleans('0'));
    }

    /**
     * @dataProvider pgBooleanProvider
     *
     * @param bool|string|null $databaseValue
     * @param string $preparedStatementValue
     * @param int|null $integerValue
     * @param bool|null $booleanValue
     * @return void
     */
    public function testConvertBooleanAsDatabaseValueStrings(
        bool|string|null $databaseValue,
        string $preparedStatementValue,
        ?int $integerValue,
        ?bool $booleanValue,
    ): void {
        $platform = $this->createPlatform();

        self::assertSame($integerValue, $platform->convertBooleansToDatabaseValue($booleanValue));
    }

    /**
     * @return void
     */
    public function testConvertBooleanAsDatabaseValueIntegers(): void
    {
        $platform = $this->createPlatform();
        $platform->setUseBooleanTrueFalseStrings(false);

        self::assertSame(1, $platform->convertBooleansToDatabaseValue(true));
        self::assertSame(0, $platform->convertBooleansToDatabaseValue(false));
    }

    /**
     * @dataProvider pgBooleanProvider
     *
     * @param bool|string|null $databaseValue
     * @param string $prepareStatementValue
     * @param int|null $integerValue
     * @param bool|null $booleanValue
     * @return void
     */
    public function testConvertFromBoolean(
        bool|string|null $databaseValue,
        string $prepareStatementValue,
        ?int $integerValue,
        ?bool $booleanValue,
    ): void {
        $platform = $this->createPlatform();

        self::assertSame($booleanValue, $platform->convertFromBoolean($databaseValue));
    }

    /**
     * @return void
     */
    public function testThrowsExceptionWithInvalidBooleanLiteral(): void
    {
        $platform = $this->createPlatform();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Unrecognized boolean literal 'my-bool'");

        $platform->convertBooleansToDatabaseValue('my-bool');
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testGetCreateSchemaSQL(): void
    {
        $schemaName = 'schema';
        $sql = $this->platform->getCreateSchemaSQL($schemaName);
        self::assertEquals('CREATE SCHEMA ' . $schemaName, $sql);
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testAlterDecimalPrecisionScale(): void
    {
        $table = new Table('mytable');
        $table->addColumn('dfoo1', 'decimal');
        $table->addColumn('dfoo2', 'decimal', ['precision' => 10, 'scale' => 6]);
        $table->addColumn('dfoo3', 'decimal', ['precision' => 10, 'scale' => 6]);
        $table->addColumn('dfoo4', 'decimal', ['precision' => 10, 'scale' => 6]);

        $tableDiff = new TableDiff('mytable');
        $tableDiff->fromTable = $table;

        $tableDiff->changedColumns['dloo1'] = new ColumnDiff(
            'dloo1',
            new Column(
                'dloo1',
                Type::getType('decimal'),
                ['precision' => 16, 'scale' => 6],
            ),
            ['precision'],
        );
        $tableDiff->changedColumns['dloo2'] = new ColumnDiff(
            'dloo2',
            new Column(
                'dloo2',
                Type::getType('decimal'),
                ['precision' => 10, 'scale' => 4],
            ),
            ['scale'],
        );
        $tableDiff->changedColumns['dloo3'] = new ColumnDiff(
            'dloo3',
            new Column(
                'dloo3',
                Type::getType('decimal'),
                ['precision' => 10, 'scale' => 6],
            ),
            [],
        );
        $tableDiff->changedColumns['dloo4'] = new ColumnDiff(
            'dloo4',
            new Column(
                'dloo4',
                Type::getType('decimal'),
                ['precision' => 16, 'scale' => 8],
            ),
            ['precision', 'scale'],
        );

        $sql = $this->platform->getAlterTableSQL($tableDiff);

        $expectedSql = [
            'ALTER TABLE mytable ALTER dloo1 TYPE NUMERIC(16, 6)',
            'ALTER TABLE mytable ALTER dloo2 TYPE NUMERIC(10, 4)',
            'ALTER TABLE mytable ALTER dloo4 TYPE NUMERIC(16, 8)',
        ];

        self::assertEquals($expectedSql, $sql);
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testDroppingConstraintsBeforeColumns(): void
    {
        $newTable = new Table('mytable');
        $newTable->addColumn('id', 'integer');
        $newTable->setPrimaryKey(['id']);

        $oldTable = clone $newTable;
        $oldTable->addColumn('parent_id', 'integer');
        $oldTable->addForeignKeyConstraint('mytable', ['parent_id'], ['id']);

        $diff = (new Comparator())->diffTable($oldTable, $newTable);
        self::assertNotFalse($diff);

        $sql = $this->platform->getAlterTableSQL($diff);

        $expectedSql = [
            'ALTER TABLE mytable DROP CONSTRAINT FK_6B2BD609727ACA70',
            'DROP INDEX IDX_6B2BD609727ACA70',
            'ALTER TABLE mytable DROP parent_id',
        ];

        self::assertEquals($expectedSql, $sql);
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testDroppingPrimaryKey(): void
    {
        $oldTable = new Table('mytable');
        $oldTable->addColumn('id', 'integer');
        $oldTable->setPrimaryKey(['id']);

        $newTable = clone $oldTable;
        $newTable->dropPrimaryKey();

        $diff = (new Comparator())->compareTables($oldTable, $newTable);

        $sql = $this->platform->getAlterTableSQL($diff);

        $expectedSql = ['DROP INDEX "primary"'];

        self::assertEquals($expectedSql, $sql);
    }

    /**
     * @return void
     */
    public function testUsesSequenceEmulatedIdentityColumns(): void
    {
        self::assertTrue($this->platform->usesSequenceEmulatedIdentityColumns());
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testReturnsIdentitySequenceName(): void
    {
        self::assertSame('mytable_mycolumn_seq', $this->platform->getIdentitySequenceName('mytable', 'mycolumn'));
    }

    /**
     * @dataProvider dataCreateSequenceWithCache
     *
     * @param int $cacheSize
     * @param string $expectedSql
     * @return void
     * @throws Exception
     */
    public function testCreateSequenceWithCache(int $cacheSize, string $expectedSql): void
    {
        $sequence = new Sequence('foo', 1, 1, $cacheSize);
        self::assertStringContainsString($expectedSql, $this->platform->getCreateSequenceSQL($sequence));
    }

    /**
     * @return array<array{mixed}>
     */
    public static function dataCreateSequenceWithCache(): array
    {
        return [
            [3, 'CACHE 3'],
        ];
    }

    /**
     * @return void
     */
    public function testReturnsBinaryTypeDeclarationSQL(): void
    {
        self::assertSame('BYTEA', $this->platform->getBinaryTypeDeclarationSQL([]));
        self::assertSame('BYTEA', $this->platform->getBinaryTypeDeclarationSQL(['length' => 0]));
        self::assertSame('BYTEA', $this->platform->getBinaryTypeDeclarationSQL(['length' => 9999999]));

        self::assertSame('BYTEA', $this->platform->getBinaryTypeDeclarationSQL(['fixed' => true]));
        self::assertSame('BYTEA', $this->platform->getBinaryTypeDeclarationSQL(['fixed' => true, 'length' => 0]));
        self::assertSame('BYTEA', $this->platform->getBinaryTypeDeclarationSQL(['fixed' => true, 'length' => 9999999]));
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testDoesNotPropagateUnnecessaryTableAlterationOnBinaryType(): void
    {
        $table1 = new Table('mytable');
        $table1->addColumn('column_varbinary', 'binary');
        $table1->addColumn('column_binary', 'binary', ['fixed' => true]);
        $table1->addColumn('column_blob', 'blob');

        $table2 = new Table('mytable');
        $table2->addColumn('column_varbinary', 'binary', ['fixed' => true]);
        $table2->addColumn('column_binary', 'binary');
        $table2->addColumn('column_blob', 'binary');

        $comparator = new Comparator();

        // VARBINARY -> BINARY
        // BINARY    -> VARBINARY
        // BLOB      -> VARBINARY
        $diff = $comparator->diffTable($table1, $table2);
        self::assertNotFalse($diff);
        self::assertEmpty($this->platform->getAlterTableSQL($diff));

        $table2 = new Table('mytable');
        $table2->addColumn('column_varbinary', 'binary', ['length' => 42]);
        $table2->addColumn('column_binary', 'blob');
        $table2->addColumn('column_blob', 'binary', ['length' => 11, 'fixed' => true]);

        // VARBINARY -> VARBINARY with changed length
        // BINARY    -> BLOB
        // BLOB      -> BINARY
        $diff = $comparator->diffTable($table1, $table2);
        self::assertNotFalse($diff);
        self::assertEmpty($this->platform->getAlterTableSQL($diff));

        $table2 = new Table('mytable');
        $table2->addColumn('column_varbinary', 'blob');
        $table2->addColumn('column_binary', 'binary', ['length' => 42, 'fixed' => true]);
        $table2->addColumn('column_blob', 'blob');

        // VARBINARY -> BLOB
        // BINARY    -> BINARY with changed length
        // BLOB      -> BLOB
        $diff = $comparator->diffTable($table1, $table2);
        self::assertNotFalse($diff);
        self::assertEmpty($this->platform->getAlterTableSQL($diff));
    }

    /**
     * @return string[]
     */
    protected function getAlterTableRenameIndexSQL(): array
    {
        return ['ALTER INDEX idx_foo RENAME TO idx_bar'];
    }

    /**
     * @return string[]
     */
    protected function getQuotedAlterTableRenameIndexSQL(): array
    {
        return [
            'ALTER INDEX "create" RENAME TO "select"',
            'ALTER INDEX "foo" RENAME TO "bar"',
        ];
    }

    /**
     * @return array<array{mixed}>
     */
    public static function pgBooleanProvider(): array
    {
        return [
            [true, 'true', 1, true],
            ['t', 'true', 1, true],
            ['true', 'true', 1, true],
            ['y', 'true', 1, true],
            ['yes', 'true', 1, true],
            ['on', 'true', 1, true],
            ['1', 'true', 1, true],

            [false, 'false', 0, false],
            ['f', 'false', 0, false],
            ['false', 'false', 0, false],
            ['n', 'false', 0, false],
            ['no', 'false', 0, false],
            ['off', 'false', 0, false],
            ['0', 'false', 0, false],

            [null, 'NULL', null, null],
        ];
    }

    /**
     * @return string[]
     */
    protected function getQuotedAlterTableRenameColumnSQL(): array
    {
        return [
            'ALTER TABLE mytable RENAME COLUMN unquoted1 TO unquoted',
            'ALTER TABLE mytable RENAME COLUMN unquoted2 TO "where"',
            'ALTER TABLE mytable RENAME COLUMN unquoted3 TO "foo"',
            'ALTER TABLE mytable RENAME COLUMN "create" TO reserved_keyword',
            'ALTER TABLE mytable RENAME COLUMN "table" TO "from"',
            'ALTER TABLE mytable RENAME COLUMN "select" TO "bar"',
            'ALTER TABLE mytable RENAME COLUMN quoted1 TO quoted',
            'ALTER TABLE mytable RENAME COLUMN quoted2 TO "and"',
            'ALTER TABLE mytable RENAME COLUMN quoted3 TO "baz"',
        ];
    }

    /**
     * @return string[]
     */
    protected function getQuotedAlterTableChangeColumnLengthSQL(): array
    {
        return [
            'ALTER TABLE mytable ALTER unquoted1 TYPE VARCHAR(255)',
            'ALTER TABLE mytable ALTER unquoted2 TYPE VARCHAR(255)',
            'ALTER TABLE mytable ALTER unquoted3 TYPE VARCHAR(255)',
            'ALTER TABLE mytable ALTER "create" TYPE VARCHAR(255)',
            'ALTER TABLE mytable ALTER "table" TYPE VARCHAR(255)',
            'ALTER TABLE mytable ALTER "select" TYPE VARCHAR(255)',
        ];
    }

    /**
     * @return string[]
     */
    protected function getAlterTableRenameIndexInSchemaSQL(): array
    {
        return ['ALTER INDEX myschema.idx_foo RENAME TO idx_bar'];
    }

    /**
     * @return string[]
     */
    protected function getQuotedAlterTableRenameIndexInSchemaSQL(): array
    {
        return [
            'ALTER INDEX "schema"."create" RENAME TO "select"',
            'ALTER INDEX "schema"."foo" RENAME TO "bar"',
        ];
    }

    /**
     * @return string
     */
    protected function getQuotesDropForeignKeySQL(): string
    {
        return 'ALTER TABLE "table" DROP CONSTRAINT "select"';
    }

    /**
     * @return void
     */
    public function testGetNullCommentOnColumnSQL(): void
    {
        self::assertEquals(
            'COMMENT ON COLUMN mytable.id IS NULL',
            $this->platform->getCommentOnColumnSQL('mytable', 'id', null),
        );
    }

    /**
     * @return void
     */
    public function testReturnsGuidTypeDeclarationSQL(): void
    {
        self::assertSame('UUID', $this->platform->getGuidTypeDeclarationSQL([]));
    }

    /**
     * @return string[]
     */
    public function getAlterTableRenameColumnSQL(): array
    {
        return ['ALTER TABLE foo RENAME COLUMN bar TO baz'];
    }

    /**
     * @return string[]
     */
    protected function getCommentOnColumnSQL(): array
    {
        return [
            'COMMENT ON COLUMN foo.bar IS \'comment\'',
            'COMMENT ON COLUMN "Foo"."BAR" IS \'comment\'',
            'COMMENT ON COLUMN "select"."from" IS \'comment\'',
        ];
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testAltersTableColumnCommentWithExplicitlyQuotedIdentifiers(): void
    {
        $table1 = new Table('"foo"', [new Column('"bar"', Type::getType('integer'))]);
        $table2 = new Table('"foo"', [new Column('"bar"', Type::getType('integer'), ['comment' => 'baz'])]);

        $comparator = new Comparator();

        $tableDiff = $comparator->diffTable($table1, $table2);

        self::assertInstanceOf(TableDiff::class, $tableDiff);
        self::assertSame(
            ['COMMENT ON COLUMN "foo"."bar" IS \'baz\''],
            $this->platform->getAlterTableSQL($tableDiff),
        );
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testAltersTableColumnCommentIfRequiredByType(): void
    {
        $table1 = new Table('"foo"', [new Column('"bar"', Type::getType('datetime'))]);
        $table2 = new Table('"foo"', [new Column('"bar"', Type::getType('datetime_immutable'))]);

        $comparator = new Comparator();

        $tableDiff = $comparator->diffTable($table1, $table2);

        self::assertNotFalse($tableDiff);
        self::assertSame(
            [
                'ALTER TABLE "foo" ALTER "bar" TYPE TIMESTAMP',
                'COMMENT ON COLUMN "foo"."bar" IS \'(DC2Type:datetime_immutable)\'',
            ],
            $this->platform->getAlterTableSQL($tableDiff),
        );
    }

    /**
     * @return string
     */
    protected function getQuotesReservedKeywordInUniqueConstraintDeclarationSQL(): string
    {
        return 'CONSTRAINT "select" UNIQUE (foo)';
    }

    /**
     * @return string
     */
    protected function getQuotesReservedKeywordInIndexDeclarationSQL(): string
    {
        return 'INDEX "select" (foo)';
    }

    /**
     * @return string
     */
    protected function getQuotesReservedKeywordInTruncateTableSQL(): string
    {
        return 'TRUNCATE "select"';
    }

    /**
     * @return string[]
     */
    protected function getAlterStringToFixedStringSQL(): array
    {
        return ['ALTER TABLE mytable ALTER name TYPE CHAR(2)'];
    }

    /**
     * @return string[]
     */
    protected function getGeneratesAlterTableRenameIndexUsedByForeignKeySQL(): array
    {
        return ['ALTER INDEX idx_foo RENAME TO idx_foo_renamed'];
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testInitializesTsvectorTypeMapping(): void
    {
        self::assertTrue($this->platform->hasDoctrineTypeMappingFor('tsvector'));
        self::assertEquals('text', $this->platform->getDoctrineTypeMapping('tsvector'));
    }

    /**
     * @return void
     */
    public function testQuotesTableNameInListTableForeignKeysSQL(): void
    {
        self::assertStringContainsStringIgnoringCase(
            "'Foo''Bar\\'",
            $this->platform->getListTableForeignKeysSQL("Foo'Bar\\"),
        );
    }

    /**
     * @return void
     */
    public function testQuotesSchemaNameInListTableForeignKeysSQL(): void
    {
        self::assertStringContainsStringIgnoringCase(
            "'Foo''Bar\\'",
            $this->platform->getListTableForeignKeysSQL("Foo'Bar\\.baz_table"),
        );
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testQuotesTableNameInListTableConstraintsSQL(): void
    {
        self::assertStringContainsStringIgnoringCase(
            "'Foo''Bar\\'",
            $this->platform->getListTableConstraintsSQL("Foo'Bar\\"),
        );
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testQuotesTableNameInListTableIndexesSQL(): void
    {
        self::assertStringContainsStringIgnoringCase(
            "'Foo''Bar\\'",
            $this->platform->getListTableIndexesSQL("Foo'Bar\\"),
        );
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testQuotesSchemaNameInListTableIndexesSQL(): void
    {
        self::assertStringContainsStringIgnoringCase(
            "'Foo''Bar\\'",
            $this->platform->getListTableIndexesSQL("Foo'Bar\\.baz_table"),
        );
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testQuotesTableNameInListTableColumnsSQL(): void
    {
        self::assertStringContainsStringIgnoringCase(
            "'Foo''Bar\\'",
            $this->platform->getListTableColumnsSQL("Foo'Bar\\"),
        );
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testQuotesSchemaNameInListTableColumnsSQL(): void
    {
        self::assertStringContainsStringIgnoringCase(
            "'Foo''Bar\\'",
            $this->platform->getListTableColumnsSQL("Foo'Bar\\.baz_table"),
        );
    }

    /**
     * @return void
     */
    public function testSupportsPartialIndexes(): void
    {
        self::assertTrue($this->platform->supportsPartialIndexes());
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testGetCreateTableSQLWithUniqueConstraints(): void
    {
        $table = new Table('foo');
        $table->addColumn('id', 'string');
        $table->addUniqueConstraint(['id'], 'test_unique_constraint');
        self::assertSame(
            [
                'CREATE TABLE foo (id VARCHAR(255) NOT NULL)',
                'ALTER TABLE foo ADD CONSTRAINT test_unique_constraint UNIQUE (id)',
            ],
            $this->platform->getCreateTableSQL($table),
            'Unique constraints are added to table.',
        );
    }

    /**
     * @return void
     * @throws Exception
     * @throws SchemaException
     */
    public function testGetCreateTableSQLWithColumnCollation(): void
    {
        $table = new Table('foo');
        $table->addColumn('id', 'string');
        $table->addOption('comment', 'foo');
        self::assertSame(
            [
                'CREATE TABLE foo (id VARCHAR(255) NOT NULL)',
                "COMMENT ON TABLE foo IS 'foo'",
            ],
            $this->platform->getCreateTableSQL($table),
            'Comments are added to table.',
        );
    }

    /**
     * @return void
     */
    public function testColumnCollationDeclarationSQL(): void
    {
        self::assertEquals(
            'COLLATE "en_US.UTF-8"',
            $this->platform->getColumnCollationDeclarationSQL('en_US.UTF-8'),
        );
    }

    /**
     * @return void
     */
    public function testHasNativeJsonType(): void
    {
        self::assertTrue($this->platform->hasNativeJsonType());
    }

    /**
     * @return void
     */
    public function testReturnsJsonTypeDeclarationSQL(): void
    {
        self::assertSame('JSON', $this->platform->getJsonTypeDeclarationSQL([]));
        self::assertSame('JSON', $this->platform->getJsonTypeDeclarationSQL(['jsonb' => false]));
        self::assertSame('JSONB', $this->platform->getJsonTypeDeclarationSQL(['jsonb' => true]));
    }

    /**
     * @return void
     */
    public function testReturnsSmallIntTypeDeclarationSQL(): void
    {
        self::assertSame(
            'SERIAL2',
            $this->platform->getSmallIntTypeDeclarationSQL(['autoincrement' => true]),
        );

        self::assertSame(
            'INT2',
            $this->platform->getSmallIntTypeDeclarationSQL(['autoincrement' => false]),
        );

        self::assertSame(
            'INT2',
            $this->platform->getSmallIntTypeDeclarationSQL([]),
        );
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testInitializesJsonTypeMapping(): void
    {
        self::assertTrue($this->platform->hasDoctrineTypeMappingFor('json'));
        self::assertEquals(Types::JSON, $this->platform->getDoctrineTypeMapping('json'));
        self::assertTrue($this->platform->hasDoctrineTypeMappingFor('jsonb'));
        self::assertEquals(Types::JSON, $this->platform->getDoctrineTypeMapping('jsonb'));
    }
}
