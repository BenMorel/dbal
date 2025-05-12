<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Schema;

use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Tests\Functional\Platform\RenameColumnTest;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function current;

abstract class AbstractComparatorTestCase extends TestCase
{
    use VerifyDeprecations;

    private Comparator $comparator;

    abstract protected function createComparator(ComparatorConfig $config): Comparator;

    protected function setUp(): void
    {
        $this->comparator = $this->createComparator(new ComparatorConfig());
    }

    public function testCompareSame1(): void
    {
        $schema1 = new Schema([
            new Table('bugdb', [
                Column::editor()
                    ->setUnquotedName('integercolumn1')
                    ->setTypeName(Types::INTEGER)
                    ->create(),
            ]),
        ]);
        $schema2 = new Schema([
            new Table('bugdb', [
                Column::editor()
                    ->setUnquotedName('integercolumn1')
                    ->setTypeName(Types::INTEGER)
                    ->create(),
            ]),
        ]);

        self::assertEquals(
            new SchemaDiff([], [], [], [], [], [], [], []),
            $this->comparator->compareSchemas($schema1, $schema2),
        );
    }

    public function testCompareSame2(): void
    {
        $schema1 = new Schema([
            new Table('bugdb', [
                Column::editor()
                    ->setUnquotedName('integercolumn1')
                    ->setTypeName(Types::INTEGER)
                    ->create(),
                Column::editor()
                    ->setUnquotedName('integercolumn2')
                    ->setTypeName(Types::INTEGER)
                    ->create(),
            ]),
        ]);
        $schema2 = new Schema([
            new Table('bugdb', [
                Column::editor()
                    ->setUnquotedName('integercolumn2')
                    ->setTypeName(Types::INTEGER)
                    ->create(),
                Column::editor()
                    ->setUnquotedName('integercolumn1')
                    ->setTypeName(Types::INTEGER)
                    ->create(),
            ]),
        ]);

        self::assertEquals(
            new SchemaDiff([], [], [], [], [], [], [], []),
            $this->comparator->compareSchemas($schema1, $schema2),
        );
    }

    public function testCompareMissingTable(): void
    {
        $schemaConfig = new SchemaConfig();

        $table = new Table('bugdb', [
            Column::editor()
                ->setUnquotedName('integercolumn1')
                ->setTypeName(Types::INTEGER)
                ->create(),
        ], [], [], [], [], $schemaConfig->toTableConfiguration());

        $schema1 = new Schema([$table], [], $schemaConfig);
        $schema2 = new Schema([], [], $schemaConfig);

        self::assertEquals(
            new SchemaDiff([], [], [], [], [$table], [], [], []),
            $this->comparator->compareSchemas($schema1, $schema2),
        );
    }

    public function testCompareNewTable(): void
    {
        $schemaConfig = new SchemaConfig();

        $table = new Table('bugdb', [
            Column::editor()
                ->setUnquotedName('integercolumn1')
                ->setTypeName(Types::INTEGER)
                ->create(),
        ], [], [], [], [], $schemaConfig->toTableConfiguration());

        $schema1 = new Schema([], [], $schemaConfig);
        $schema2 = new Schema([$table], [], $schemaConfig);

        $expected = new SchemaDiff([], [], [$table], [], [], [], [], []);

        self::assertEquals($expected, $this->comparator->compareSchemas($schema1, $schema2));
    }

    public function testCompareAutoIncrementChanged(): void
    {
        $column1 = Column::editor()
            ->setUnquotedName('foo')
            ->setTypeName(Types::INTEGER)
            ->setAutoincrement(true)
            ->create();

        $column2 = $column1->edit()
            ->setAutoincrement(false)
            ->create();

        $diff = new ColumnDiff($column2, $column1);

        self::assertTrue($diff->hasAutoIncrementChanged());
    }

    public function testCompareChangedColumnsChangeType(): void
    {
        $column1 = Column::editor()
            ->setUnquotedName('id')
            ->setTypeName(Types::STRING)
            ->create();

        $column2 = $column1->edit()
            ->setTypeName(Types::INTEGER)
            ->create();

        $diff12 = new ColumnDiff($column2, $column1);
        self::assertTrue($diff12->hasTypeChanged());

        $diff11 = new ColumnDiff($column1, $column1);
        self::assertFalse($diff11->hasTypeChanged());
    }

    public function testDifferentTypeInstancesOfTheSameType(): void
    {
        $type1 = Type::getType(Types::INTEGER);
        $type2 = clone $type1;

        self::assertNotSame($type1, $type2);

        $column1 = Column::editor()
            ->setUnquotedName('id')
            ->setType($type1)
            ->create();

        $column2 = $column1->edit()
            ->setType($type2)
            ->create();

        $diff = new ColumnDiff($column2, $column1);
        self::assertFalse($diff->hasTypeChanged());
    }

    public function testOverriddenType(): void
    {
        $defaultStringType = Type::getType(Types::STRING);
        $integerType       = Type::getType(Types::INTEGER);

        Type::overrideType(Types::STRING, $integerType::class);
        $overriddenStringType = Type::getType(Types::STRING);

        Type::overrideType(Types::STRING, $defaultStringType::class);

        $column1 = Column::editor()
            ->setUnquotedName('id')
            ->setType($integerType)
            ->create();

        $column2 = $column1->edit()
            ->setType($overriddenStringType)
            ->create();

        $diff = new ColumnDiff($column2, $column1);
        self::assertFalse($diff->hasTypeChanged());
    }

    public function testCompareChangeColumnsMultipleNewColumnsRename(): void
    {
        $tableA = new Table('foo');
        $tableA->addColumn('datecolumn1', Types::DATETIME_MUTABLE);

        $tableB = new Table('foo');
        $tableB->addColumn('new_datecolumn1', Types::DATETIME_MUTABLE);
        $tableB->addColumn('new_datecolumn2', Types::DATETIME_MUTABLE);

        $tableDiff = $this->comparator->compareTables($tableA, $tableB);

        $renamedColumns = RenameColumnTest::getRenamedColumns($tableDiff);
        self::assertCount(1, $renamedColumns);
        self::assertArrayHasKey('datecolumn1', $renamedColumns);
        self::assertEquals(['new_datecolumn2'], $this->getAssetNames($tableDiff->getAddedColumns()));

        self::assertCount(0, $tableDiff->getDroppedColumns());
        self::assertCount(1, $tableDiff->getChangedColumns());
    }

    public function testCompareSequences(): void
    {
        $seq1 = new Sequence('foo', 1, 1);
        $seq2 = new Sequence('foo', 1, 2);
        $seq3 = new Sequence('foo', 2, 1);

        self::assertTrue($this->comparator->diffSequence($seq1, $seq2));
        self::assertTrue($this->comparator->diffSequence($seq1, $seq3));
    }

    public function testRemovedSequence(): void
    {
        $schema1 = new Schema();
        $seq     = $schema1->createSequence('foo');

        $schema2 = new Schema();

        $diffSchema = $this->comparator->compareSchemas($schema1, $schema2);

        self::assertSame([$seq], $diffSchema->getDroppedSequences());
    }

    public function testAddedSequence(): void
    {
        $schema1 = new Schema();

        $schema2 = new Schema();
        $seq     = $schema2->createSequence('foo');

        $diffSchema = $this->comparator->compareSchemas($schema1, $schema2);

        self::assertSame([$seq], $diffSchema->getCreatedSequences());
    }

    public function testTableAddForeignKey(): void
    {
        $tableForeign = new Table('bar');
        $tableForeign->addColumn('id', Types::INTEGER);

        $table1 = new Table('foo');
        $table1->addColumn('fk', Types::INTEGER);

        $table2 = new Table('foo');
        $table2->addColumn('fk', Types::INTEGER);
        $table2->addForeignKeyConstraint($tableForeign->getName(), ['fk'], ['id']);

        $tableDiff = $this->comparator->compareTables($table1, $table2);

        self::assertCount(1, $tableDiff->getAddedForeignKeys());
    }

    public function testTableRemoveForeignKey(): void
    {
        $tableForeign = new Table('bar');
        $tableForeign->addColumn('id', Types::INTEGER);

        $table1 = new Table('foo');
        $table1->addColumn('fk', Types::INTEGER);

        $table2 = new Table('foo');
        $table2->addColumn('fk', Types::INTEGER);
        $table2->addForeignKeyConstraint($tableForeign->getName(), ['fk'], ['id']);

        $tableDiff = $this->comparator->compareTables($table2, $table1);

        self::assertCount(1, $tableDiff->getDroppedForeignKeys());
    }

    public function testTableUpdateForeignKey(): void
    {
        $tableForeign = new Table('bar');
        $tableForeign->addColumn('id', Types::INTEGER);

        $table1 = new Table('foo');
        $table1->addColumn('fk', Types::INTEGER);
        $table1->addForeignKeyConstraint($tableForeign->getName(), ['fk'], ['id']);

        $table2 = new Table('foo');
        $table2->addColumn('fk', Types::INTEGER);
        $table2->addForeignKeyConstraint($tableForeign->getName(), ['fk'], ['id'], ['onUpdate' => 'CASCADE']);

        $tableDiff = $this->comparator->compareTables($table1, $table2);

        self::assertCount(1, $tableDiff->getDroppedForeignKeys());
        self::assertCount(1, $tableDiff->getAddedForeignKeys());
    }

    public function testMovedForeignKeyForeignTable(): void
    {
        $tableForeign = new Table('bar');
        $tableForeign->addColumn('id', Types::INTEGER);

        $tableForeign2 = new Table('bar2');
        $tableForeign2->addColumn('id', Types::INTEGER);

        $table1 = new Table('foo');
        $table1->addColumn('fk', Types::INTEGER);
        $table1->addForeignKeyConstraint($tableForeign->getName(), ['fk'], ['id']);

        $table2 = new Table('foo');
        $table2->addColumn('fk', Types::INTEGER);
        $table2->addForeignKeyConstraint($tableForeign2->getName(), ['fk'], ['id']);

        $tableDiff = $this->comparator->compareTables($table1, $table2);

        self::assertCount(1, $tableDiff->getDroppedForeignKeys());
        self::assertCount(1, $tableDiff->getAddedForeignKeys());
    }

    public function testTablesCaseInsensitive(): void
    {
        $schemaA = new Schema();
        $schemaA->createTable('foo');
        $schemaA->createTable('bAr');
        $schemaA->createTable('BAZ');
        $schemaA->createTable('new');

        $schemaB = new Schema();
        $schemaB->createTable('FOO');
        $schemaB->createTable('bar');
        $schemaB->createTable('Baz');
        $schemaB->createTable('old');

        $diff = $this->comparator->compareSchemas($schemaA, $schemaB);

        self::assertCount(1, $diff->getCreatedTables());
        self::assertCount(0, $diff->getAlteredTables());
        self::assertCount(1, $diff->getDroppedTables());
    }

    public function testSequencesCaseInsensitive(): void
    {
        $schemaA = new Schema();
        $schemaA->createSequence('foo');
        $schemaA->createSequence('BAR');
        $schemaA->createSequence('Baz');
        $schemaA->createSequence('new');

        $schemaB = new Schema();
        $schemaB->createSequence('FOO');
        $schemaB->createSequence('Bar');
        $schemaB->createSequence('baz');
        $schemaB->createSequence('old');

        $diff = $this->comparator->compareSchemas($schemaA, $schemaB);

        self::assertCount(1, $diff->getCreatedSequences());
        self::assertCount(0, $diff->getAlteredSequences());
        self::assertCount(1, $diff->getDroppedSequences());
    }

    public function testCompareColumnCompareCaseInsensitive(): void
    {
        $tableA = new Table('foo');
        $tableA->addColumn('id', Types::INTEGER);

        $tableB = new Table('foo');
        $tableB->addColumn('ID', Types::INTEGER);

        $tableDiff = $this->comparator->compareTables($tableA, $tableB);

        self::assertTrue($tableDiff->isEmpty());
    }

    public function testCompareIndexBasedOnPropertiesNotName(): void
    {
        $tableA = new Table('foo');
        $tableA->addColumn('id', Types::INTEGER);
        $tableA->addIndex(['id'], 'foo_bar_idx');

        $tableB = new Table('foo');
        $tableB->addColumn('ID', Types::INTEGER);
        $tableB->addIndex(['id'], 'bar_foo_idx');

        self::assertEquals(
            new TableDiff($tableA, renamedIndexes: [
                'foo_bar_idx' => new Index('bar_foo_idx', ['id']),
            ]),
            $this->comparator->compareTables($tableA, $tableB),
        );
    }

    public function testCompareForeignKeyBasedOnPropertiesNotName(): void
    {
        $tableA = new Table('foo');
        $tableA->addColumn('id', Types::INTEGER);
        $tableA->addForeignKeyConstraint('bar', ['id'], ['id'], [], 'foo_constraint');

        $tableB = new Table('foo');
        $tableB->addColumn('ID', Types::INTEGER);
        $tableB->addForeignKeyConstraint('bar', ['id'], ['id'], [], 'bar_constraint');

        self::assertEquals(
            new TableDiff($tableA),
            $this->comparator->compareTables($tableA, $tableB),
        );
    }

    public function testDetectRenameColumn(): void
    {
        $tableA = new Table('foo');
        $tableA->addColumn('foo', Types::INTEGER);

        $tableB = new Table('foo');
        $tableB->addColumn('bar', Types::INTEGER);

        $tableDiff = $this->comparator->compareTables($tableA, $tableB);

        self::assertCount(0, $tableDiff->getAddedColumns());
        self::assertCount(0, $tableDiff->getDroppedColumns());

        $renamedColumns = RenameColumnTest::getRenamedColumns($tableDiff);
        self::assertArrayHasKey('foo', $renamedColumns);
        self::assertEquals('bar', $renamedColumns['foo']->getName());
    }

    public function testDetectRenameColumnDisabled(): void
    {
        $tableA = new Table('foo');
        $tableA->addColumn('foo', Types::INTEGER);

        $tableB = new Table('foo');
        $tableB->addColumn('bar', Types::INTEGER);

        $this->comparator = $this->createComparator((new ComparatorConfig())->withDetectRenamedColumns(false));
        $tableDiff        = $this->comparator->compareTables($tableA, $tableB);

        self::assertCount(1, $tableDiff->getAddedColumns());
        self::assertCount(1, $tableDiff->getDroppedColumns());
        self::assertCount(0, $tableDiff->getRenamedColumns());
    }

    /**
     * You can easily have ambiguities in the column renaming. If these
     * are detected no renaming should take place, instead adding and dropping
     * should be used exclusively.
     */
    public function testDetectRenameColumnAmbiguous(): void
    {
        $tableA = new Table('foo');
        $tableA->addColumn('foo', Types::INTEGER);
        $tableA->addColumn('bar', Types::INTEGER);

        $tableB = new Table('foo');
        $tableB->addColumn('baz', Types::INTEGER);

        $tableDiff = $this->comparator->compareTables($tableA, $tableB);

        self::assertEquals(['baz'], $this->getAssetNames($tableDiff->getAddedColumns()));
        self::assertEquals(['foo', 'bar'], $this->getAssetNames($tableDiff->getDroppedColumns()));
        self::assertCount(0, RenameColumnTest::getRenamedColumns($tableDiff));
    }

    public function testDetectRenameIndex(): void
    {
        $table1 = new Table('foo');
        $table1->addColumn('foo', Types::INTEGER);

        $table2 = clone $table1;

        $table1->addIndex(['foo'], 'idx_foo');

        $table2->addIndex(['foo'], 'idx_bar');

        $tableDiff = $this->comparator->compareTables($table1, $table2);

        self::assertCount(0, $tableDiff->getAddedColumns());
        self::assertCount(0, $tableDiff->getDroppedIndexes());

        $renamedIndexes = $tableDiff->getRenamedIndexes();
        self::assertArrayHasKey('idx_foo', $renamedIndexes);
        self::assertEquals('idx_bar', $renamedIndexes['idx_foo']->getName());
    }

    public function testDetectRenameIndexDisabled(): void
    {
        $table1 = new Table('foo');
        $table1->addColumn('foo', Types::INTEGER);

        $table2 = clone $table1;

        $table1->addIndex(['foo'], 'idx_foo');

        $table2->addIndex(['foo'], 'idx_bar');

        $this->comparator = $this->createComparator((new ComparatorConfig())->withDetectRenamedIndexes(false));
        $tableDiff        = $this->comparator->compareTables($table1, $table2);

        self::assertCount(1, $tableDiff->getAddedIndexes());
        self::assertCount(1, $tableDiff->getDroppedIndexes());
        self::assertCount(0, $tableDiff->getRenamedIndexes());
    }

    /**
     * You can easily have ambiguities in the index renaming. If these
     * are detected no renaming should take place, instead adding and dropping
     * should be used exclusively.
     */
    public function testDetectRenameIndexAmbiguous(): void
    {
        $table1 = new Table('foo');
        $table1->addColumn('foo', Types::INTEGER);

        $table2 = clone $table1;

        $table1->addIndex(['foo'], 'idx_foo');
        $table1->addIndex(['foo'], 'idx_bar');

        $table2->addIndex(['foo'], 'idx_baz');

        $tableDiff = $this->comparator->compareTables($table1, $table2);

        self::assertEquals(['idx_baz'], $this->getAssetNames($tableDiff->getAddedIndexes()));
        self::assertEquals(['idx_foo', 'idx_bar'], $this->getAssetNames($tableDiff->getDroppedIndexes()));
        self::assertCount(0, $tableDiff->getRenamedIndexes());
    }

    public function testDetectChangeIdentifierType(): void
    {
        $tableA = new Table('foo');
        $tableA->addColumn('id', Types::INTEGER, ['autoincrement' => false]);

        $tableB = new Table('foo');
        $tableB->addColumn('id', Types::INTEGER, ['autoincrement' => true]);

        $tableDiff = $this->comparator->compareTables($tableA, $tableB);

        $modifiedColumns = $tableDiff->getChangedColumns();
        self::assertCount(1, $modifiedColumns);
        /** @var ColumnDiff $modifiedColumn */
        $modifiedColumn = current($modifiedColumns);
        self::assertEquals('id', $modifiedColumn->getOldColumn()->getName());
    }

    public function testReportModifiedIndexesEnabled(): void
    {
        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/dbal/pull/6890');

        $tableDiff = $this->compareTablesWithModifiedIndex(true);

        self::assertCount(0, $tableDiff->getDroppedIndexes());
        self::assertCount(0, $tableDiff->getAddedIndexes());
        self::assertCount(1, $tableDiff->getModifiedIndexes());
    }

    public function testReportModifiedIndexesDisabled(): void
    {
        $this->expectNoDeprecationWithIdentifier('https://github.com/doctrine/dbal/pull/6890');

        $tableDiff = $this->compareTablesWithModifiedIndex(false);

        self::assertCount(1, $tableDiff->getDroppedIndexes());
        self::assertCount(1, $tableDiff->getAddedIndexes());
        self::assertCount(0, $tableDiff->getModifiedIndexes());
    }

    private function compareTablesWithModifiedIndex(bool $reportModifiedIndexes): TableDiff
    {
        $tableA = new Table('foo');
        $tableA->addColumn('id', Types::INTEGER);
        $tableA->addIndex(['id'], 'idx_id');

        $tableB = new Table('foo');
        $tableB->addColumn('id', Types::INTEGER);
        $tableB->addUniqueIndex(['id'], 'idx_id');

        return $this->createComparator(
            (new ComparatorConfig())->withReportModifiedIndexes($reportModifiedIndexes),
        )->compareTables($tableA, $tableB);
    }

    public function testDiff(): void
    {
        $table = new Table('twitter_users');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('twitterId', Types::INTEGER);
        $table->addColumn('displayName', Types::STRING, ['length' => 32]);
        $table->setPrimaryKey(['id']);

        $newtable = new Table('twitter_users');
        $newtable->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $newtable->addColumn('twitter_id', Types::INTEGER);
        $newtable->addColumn('display_name', Types::STRING, ['length' => 32]);
        $newtable->addColumn('logged_in_at', Types::DATETIME_MUTABLE);
        $newtable->setPrimaryKey(['id']);

        $tableDiff = $this->comparator->compareTables($table, $newtable);

        self::assertEquals(['twitterId', 'displayName'], array_keys(RenameColumnTest::getRenamedColumns($tableDiff)));
        self::assertEquals(['logged_in_at'], $this->getAssetNames($tableDiff->getAddedColumns()));
        self::assertCount(0, $tableDiff->getDroppedColumns());
    }

    public function testAlteredSequence(): void
    {
        $oldSchema = new Schema();
        $oldSchema->createSequence('baz');

        $newSchema = clone $oldSchema;
        $newSchema->getSequence('baz')->setAllocationSize(20);

        $diff = $this->comparator->compareSchemas($oldSchema, $newSchema);

        self::assertSame([$newSchema->getSequence('baz')], $diff->getAlteredSequences());
    }

    public function testFqnSchemaComparison(): void
    {
        $config = new SchemaConfig();
        $config->setName('foo');

        $oldSchema = new Schema([], [], $config);
        $oldSchema->createTable('bar');

        $newSchema = new Schema([], [], $config);
        $newSchema->createTable('foo.bar');

        self::assertEquals(
            new SchemaDiff([], [], [], [], [], [], [], []),
            $this->comparator->compareSchemas($oldSchema, $newSchema),
        );
    }

    public function testNamespacesComparison(): void
    {
        $config = new SchemaConfig();
        $config->setName('schemaName');

        $oldSchema = new Schema([], [], $config);
        $oldSchema->createTable('taz');
        $oldSchema->createTable('war.tab');

        $newSchema = new Schema([], [], $config);
        $newSchema->createTable('bar.tab');
        $newSchema->createTable('baz.tab');
        $newSchema->createTable('war.tab');

        $diff = $this->comparator->compareSchemas($oldSchema, $newSchema);

        self::assertEquals(['bar', 'baz'], $diff->getCreatedSchemas());
        self::assertCount(2, $diff->getCreatedTables());
    }

    public function testFqnSchemaComparisonDifferentSchemaNameButSameTableNoDiff(): void
    {
        $config = new SchemaConfig();
        $config->setName('foo');

        $oldSchema = new Schema([], [], $config);
        $oldSchema->createTable('foo.bar');

        $newSchema = new Schema();
        $newSchema->createTable('bar');

        self::assertEquals(
            new SchemaDiff([], [], [], [], [], [], [], []),
            $this->comparator->compareSchemas($oldSchema, $newSchema),
        );
    }

    public function testFqnSchemaComparisonNoSchemaSame(): void
    {
        $config = new SchemaConfig();
        $config->setName('foo');
        $oldSchema = new Schema([], [], $config);
        $oldSchema->createTable('bar');

        $newSchema = new Schema();
        $newSchema->createTable('bar');

        self::assertEquals(
            new SchemaDiff([], [], [], [], [], [], [], []),
            $this->comparator->compareSchemas($oldSchema, $newSchema),
        );
    }

    public function testAutoIncrementSequences(): void
    {
        $oldSchema = new Schema();
        $table     = $oldSchema->createTable('foo');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);
        $oldSchema->createSequence('foo_id_seq');

        $newSchema = new Schema();
        $table     = $newSchema->createTable('foo');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);

        $diff = $this->comparator->compareSchemas($oldSchema, $newSchema);

        self::assertCount(0, $diff->getDroppedSequences());
    }

    /**
     * Check that added autoincrement sequence is not populated in newSequences
     */
    public function testAutoIncrementNoSequences(): void
    {
        $oldSchema = new Schema();
        $table     = $oldSchema->createTable('foo');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);

        $newSchema = new Schema();
        $table     = $newSchema->createTable('foo');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);
        $newSchema->createSequence('foo_id_seq');

        $diff = $this->comparator->compareSchemas($oldSchema, $newSchema);

        self::assertCount(0, $diff->getCreatedSequences());
    }

    public function testComparesNamespaces(): void
    {
        $oldSchema = new Schema([], [], null, ['foo', 'bar']);
        $newSchema = new Schema([], [], null, ['bar', 'baz']);

        $diff = $this->comparator->compareSchemas($oldSchema, $newSchema);

        self::assertEquals(['baz'], $diff->getCreatedSchemas());
        self::assertEquals(['foo'], $diff->getDroppedSchemas());
    }

    #[DataProvider('getCompareColumnComments')]
    public function testCompareColumnComments(string $comment1, string $comment2, bool $equals): void
    {
        $column1 = Column::editor()
            ->setUnquotedName('foo')
            ->setTypeName(Types::INTEGER)
            ->setComment($comment1)
            ->create();

        $column2 = $column1->edit()
            ->setComment($comment2)
            ->create();

        $diff1 = new ColumnDiff($column2, $column1);
        $diff2 = new ColumnDiff($column1, $column2);

        self::assertSame(! $equals, $diff1->hasCommentChanged());
        self::assertSame(! $equals, $diff2->hasCommentChanged());
    }

    /** @return mixed[][] */
    public static function getCompareColumnComments(): iterable
    {
        return [
            ['', '', true],
            [' ', ' ', true],
            ['0', '0', true],
            ['foo', 'foo', true],

            ['', ' ', false],
            ['', '0', false],
            ['', 'foo', false],

            [' ', '0', false],
            [' ', 'foo', false],

            ['0', 'foo', false],
        ];
    }

    public function testForeignKeyRemovalWithRenamedLocalColumn(): void
    {
        $oldSchema = new Schema([
            new Table('table1', [
                Column::editor()
                    ->setUnquotedName('id')
                    ->setTypeName(Types::INTEGER)
                    ->create(),
            ]),
            new Table('table2', [
                Column::editor()
                    ->setUnquotedName('id')
                    ->setTypeName(Types::INTEGER)
                    ->create(),
                Column::editor()
                    ->setUnquotedName('id_table1')
                    ->setTypeName(Types::INTEGER)
                    ->create(),
            ], [], [], [
                new ForeignKeyConstraint(['id_table1'], 'table1', ['id'], 'fk_table2_table1'),
            ]),
        ]);
        $newSchema = new Schema([
            new Table('table2', [
                Column::editor()
                    ->setUnquotedName('id')
                    ->setTypeName(Types::INTEGER)
                    ->create(),
                Column::editor()
                    ->setUnquotedName('id_table3')
                    ->setTypeName(Types::INTEGER)
                    ->create(),
            ], [], [], [
                new ForeignKeyConstraint(['id_table3'], 'table3', ['id'], 'fk_table2_table3'),
            ]),
            new Table('table3', [
                Column::editor()
                    ->setUnquotedName('id')
                    ->setTypeName(Types::INTEGER)
                    ->create(),
            ]),
        ]);

        $schemaDiff = $this->comparator->compareSchemas($oldSchema, $newSchema);

        $alteredTables = $schemaDiff->getAlteredTables();
        self::assertCount(1, $alteredTables);

        $addedForeignKeys = $alteredTables[0]->getAddedForeignKeys();
        self::assertCount(1, $addedForeignKeys, 'FK to table3 should be added.');
        self::assertEquals('table3', $addedForeignKeys[0]->getForeignTableName());
    }

    public function testWillNotProduceSchemaDiffOnTableWithAddedCustomSchemaDefinition(): void
    {
        $oldSchema = new Schema([
            new Table('a_table', [
                Column::editor()
                    ->setUnquotedName('is_default')
                    ->setTypeName(Types::STRING)
                    ->setLength(32)
                    ->create(),
            ]),
        ]);
        $newSchema = new Schema([
            new Table('a_table', [
                Column::editor()
                    ->setUnquotedName('is_default')
                    ->setTypeName(Types::STRING)
                    ->setLength(32)
                    ->setColumnDefinition('ENUM(\'default\')')
                    ->create(),
            ]),
        ]);

        self::assertEmpty(
            $this->comparator->compareSchemas($oldSchema, $newSchema)
                ->getAlteredTables(),
            'Schema diff is empty, since only `columnDefinition` changed from `null` (not detected) to a defined one',
        );
    }

    /**
     * @param array<AbstractAsset<UnqualifiedName>> $assets
     *
     * @return array<string>
     */
    protected function getAssetNames(array $assets): array
    {
        $names = [];

        foreach ($assets as $asset) {
            $names[] = $asset->getName();
        }

        return $names;
    }
}
