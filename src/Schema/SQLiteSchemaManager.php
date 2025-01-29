<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\SQLite;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Type;
use Doctrine\Deprecations\Deprecation;

use function array_change_key_case;
use function array_map;
use function array_merge;
use function assert;
use function count;
use function func_get_arg;
use function func_num_args;
use function implode;
use function is_string;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strcasecmp;
use function strtolower;
use function trim;
use function usort;

use const CASE_LOWER;

/**
 * SQLite SchemaManager.
 *
 * @extends AbstractSchemaManager<SQLitePlatform>
 */
class SQLiteSchemaManager extends AbstractSchemaManager
{
    /**
     * {@inheritDoc}
     */
    protected function fetchForeignKeyColumnsByTable(string $databaseName): array
    {
        $columnsByTable = parent::fetchForeignKeyColumnsByTable($databaseName);

        if (count($columnsByTable) > 0) {
            foreach ($columnsByTable as $table => $columns) {
                $columnsByTable[$table] = $this->addDetailsToTableForeignKeyColumns($table, $columns);
            }
        }

        return $columnsByTable;
    }

    public function createForeignKey(ForeignKeyConstraint $foreignKey, string $table): void
    {
        $table = $this->introspectTable($table);

        $this->alterTable(new TableDiff($table, modifiedForeignKeys: [$foreignKey]));
    }

    public function dropForeignKey(string $name, string $table): void
    {
        $table = $this->introspectTable($table);

        $foreignKey = $table->getForeignKey($name);

        $this->alterTable(new TableDiff($table, droppedForeignKeys: [$foreignKey]));
    }

    /**
     * {@inheritDoc}
     */
    public function listTableForeignKeys(string $table): array
    {
        $table = $this->normalizeName($table);

        $columns = $this->selectForeignKeyColumns('main', $table)
            ->fetchAllAssociative();

        if (count($columns) > 0) {
            $columns = $this->addDetailsToTableForeignKeyColumns($table, $columns);
        }

        return $this->_getPortableTableForeignKeysList($columns);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableTableDefinition(array $table): string
    {
        return $table['table_name'];
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableTableIndexesList(array $rows, string $tableName): array
    {
        $indexBuffer = [];

        // fetch primary
        $indexArray = $this->connection->fetchAllAssociative('SELECT * FROM PRAGMA_TABLE_INFO (?)', [$tableName]);

        usort(
            $indexArray,
            /**
             * @param array<string,mixed> $a
             * @param array<string,mixed> $b
             */
            static function (array $a, array $b): int {
                if ($a['pk'] === $b['pk']) {
                    return $a['cid'] - $b['cid'];
                }

                return $a['pk'] - $b['pk'];
            },
        );

        foreach ($indexArray as $indexColumnRow) {
            if ($indexColumnRow['pk'] === 0 || $indexColumnRow['pk'] === '0') {
                continue;
            }

            $indexBuffer[] = [
                'key_name' => 'primary',
                'primary' => true,
                'non_unique' => false,
                'column_name' => $indexColumnRow['name'],
            ];
        }

        // fetch regular indexes
        foreach ($rows as $row) {
            // Ignore indexes with reserved names, e.g. autoindexes
            if (str_starts_with($row['name'], 'sqlite_')) {
                continue;
            }

            $keyName           = $row['name'];
            $idx               = [];
            $idx['key_name']   = $keyName;
            $idx['primary']    = false;
            $idx['non_unique'] = ! $row['unique'];

            $indexArray = $this->connection->fetchAllAssociative('SELECT * FROM PRAGMA_INDEX_INFO (?)', [$keyName]);

            foreach ($indexArray as $indexColumnRow) {
                $idx['column_name'] = $indexColumnRow['name'];
                $indexBuffer[]      = $idx;
            }
        }

        return parent::_getPortableTableIndexesList($indexBuffer, $tableName);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableTableColumnList(string $table, string $database, array $rows): array
    {
        $list = parent::_getPortableTableColumnList($table, $database, $rows);

        // find column with autoincrement
        $autoincrementColumn = null;
        $autoincrementCount  = 0;

        foreach ($rows as $tableColumn) {
            if ($tableColumn['pk'] === 0 || $tableColumn['pk'] === '0') {
                continue;
            }

            $autoincrementCount++;
            if ($autoincrementColumn !== null || strtolower($tableColumn['type']) !== 'integer') {
                continue;
            }

            $autoincrementColumn = $tableColumn['name'];
        }

        if ($autoincrementCount === 1 && $autoincrementColumn !== null) {
            foreach ($list as $column) {
                if ($autoincrementColumn !== $column->getName()) {
                    continue;
                }

                $column->setAutoincrement(true);
            }
        }

        // inspect column collation and comments
        $createSql = $this->getCreateTableSQL($table);

        foreach ($list as $columnName => $column) {
            $type = $column->getType();

            if ($type instanceof StringType || $type instanceof TextType) {
                $column->setPlatformOption(
                    'collation',
                    $this->parseColumnCollationFromSQL($columnName, $createSql) ?? 'BINARY',
                );
            }

            $comment = $this->parseColumnCommentFromSQL($columnName, $createSql);

            $column->setComment($comment);
        }

        return $list;
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableTableColumnDefinition(array $tableColumn): Column
    {
        $matchResult = preg_match('/^([^()]*)\\s*(\\(((\\d+)(,\\s*(\\d+))?)\\))?/', $tableColumn['type'], $matches);
        assert($matchResult === 1);

        $dbType = trim(strtolower($matches[1]));

        $length = $precision = null;
        $fixed  = $unsigned = false;
        $scale  = 0;

        if (isset($matches[4])) {
            if (isset($matches[6])) {
                $precision = (int) $matches[4];
                $scale     = (int) $matches[6];
            } else {
                $length = (int) $matches[4];
            }
        }

        if (str_contains($dbType, ' unsigned')) {
            $dbType   = str_replace(' unsigned', '', $dbType);
            $unsigned = true;
        }

        $type    = $this->platform->getDoctrineTypeMapping($dbType);
        $default = $tableColumn['dflt_value'];
        if ($default === 'NULL') {
            $default = null;
        }

        if ($default !== null) {
            // SQLite returns the default value as a literal expression, so we need to parse it
            if (preg_match('/^\'(.*)\'$/s', $default, $matches) === 1) {
                $default = str_replace("''", "'", $matches[1]);
            }
        }

        $notnull = (bool) $tableColumn['notnull'];

        if (! isset($tableColumn['name'])) {
            $tableColumn['name'] = '';
        }

        if ($dbType === 'char') {
            $fixed = true;
        }

        $options = [
            'length'    => $length,
            'unsigned'  => $unsigned,
            'fixed'     => $fixed,
            'notnull'   => $notnull,
            'default'   => $default,
            'precision' => $precision,
            'scale'     => $scale,
        ];

        return new Column($tableColumn['name'], Type::getType($type), $options);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableViewDefinition(array $view): View
    {
        return new View($view['name'], $view['sql']);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableTableForeignKeysList(array $rows): array
    {
        $list = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $id  = $row['id'];
            if (! isset($list[$id])) {
                if (! isset($row['on_delete']) || $row['on_delete'] === 'RESTRICT') {
                    $row['on_delete'] = null;
                }

                if (! isset($row['on_update']) || $row['on_update'] === 'RESTRICT') {
                    $row['on_update'] = null;
                }

                $list[$id] = [
                    'name' => $row['constraint_name'],
                    'local' => [],
                    'foreign' => [],
                    'foreignTable' => $row['table'],
                    'onDelete' => $row['on_delete'],
                    'onUpdate' => $row['on_update'],
                    'deferrable' => $row['deferrable'],
                    'deferred' => $row['deferred'],
                ];
            }

            $list[$id]['local'][] = $row['from'];

            if ($row['to'] === null) {
                continue;
            }

            $list[$id]['foreign'][] = $row['to'];
        }

        foreach ($list as $id => $value) {
            if (count($value['foreign']) !== 0) {
                continue;
            }

            // Inferring a shorthand form for the foreign key constraint, where the "to" field is empty.
            // @see https://www.sqlite.org/foreignkeys.html#fk_indexes.
            $foreignTableIndexes = $this->_getPortableTableIndexesList([], $value['foreignTable']);

            if (! isset($foreignTableIndexes['primary'])) {
                Deprecation::trigger(
                    'doctrine/dbal',
                    'https://github.com/doctrine/dbal/pull/6701',
                    'Introspection of SQLite foreign key constraints with omitted referenced column names'
                        . ' in an incomplete schema is deprecated.',
                );

                continue;
            }

            $list[$id]['foreign'] = $foreignTableIndexes['primary']->getColumns();
        }

        return parent::_getPortableTableForeignKeysList($list);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableTableForeignKeyDefinition(array $tableForeignKey): ForeignKeyConstraint
    {
        return new ForeignKeyConstraint(
            $tableForeignKey['local'],
            $tableForeignKey['foreignTable'],
            $tableForeignKey['foreign'],
            $tableForeignKey['name'],
            [
                'onDelete' => $tableForeignKey['onDelete'],
                'onUpdate' => $tableForeignKey['onUpdate'],
                'deferrable' => $tableForeignKey['deferrable'],
                'deferred' => $tableForeignKey['deferred'],
            ],
        );
    }

    private function parseColumnCollationFromSQL(string $column, string $sql): ?string
    {
        $pattern = '{' . $this->buildIdentifierPattern($column)
            . '[^,(]+(?:\([^()]+\)[^,]*)?(?:(?:DEFAULT|CHECK)\s*(?:\(.*?\))?[^,]*)*COLLATE\s+["\']?([^\s,"\')]+)}is';

        if (preg_match($pattern, $sql, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    private function parseTableCommentFromSQL(string $table, string $sql): ?string
    {
        $pattern = '/\s* # Allow whitespace characters at start of line
CREATE\sTABLE' . $this->buildIdentifierPattern($table) . '
( # Start capture
   (?:\s*--[^\n]*\n?)+ # Capture anything that starts with whitespaces followed by -- until the end of the line(s)
)/ix';

        if (preg_match($pattern, $sql, $match) !== 1) {
            return null;
        }

        $comment = preg_replace('{^\s*--}m', '', rtrim($match[1], "\n"));

        return $comment === '' ? null : $comment;
    }

    private function parseColumnCommentFromSQL(string $column, string $sql): string
    {
        $pattern = '{[\s(,]' . $this->buildIdentifierPattern($column)
            . '(?:\([^)]*?\)|[^,(])*?,?((?:(?!\n))(?:\s*--[^\n]*\n?)+)}i';

        if (preg_match($pattern, $sql, $match) !== 1) {
            return '';
        }

        $comment = preg_replace('{^\s*--}m', '', rtrim($match[1], "\n"));
        assert(is_string($comment));

        return $comment;
    }

    /**
     * Returns a regular expression pattern that matches the given unquoted or quoted identifier.
     */
    private function buildIdentifierPattern(string $identifier): string
    {
        return '(?:' . implode('|', array_map(
            static function (string $sql): string {
                return '\W' . preg_quote($sql, '/') . '\W';
            },
            [
                $identifier,
                $this->platform->quoteSingleIdentifier($identifier),
            ],
        )) . ')';
    }

    /** @throws Exception */
    private function getCreateTableSQL(string $table): string
    {
        $sql = $this->connection->fetchOne(
            <<<'SQL'
SELECT sql
  FROM (
      SELECT *
        FROM sqlite_master
   UNION ALL
      SELECT *
        FROM sqlite_temp_master
  )
WHERE type = 'table'
AND name = ?
SQL
            ,
            [$table],
        );

        if ($sql !== false) {
            return $sql;
        }

        return '';
    }

    /**
     * @param list<array<string,mixed>> $columns
     *
     * @return list<array<string,mixed>>
     *
     * @throws Exception
     */
    private function addDetailsToTableForeignKeyColumns(string $table, array $columns): array
    {
        $foreignKeyDetails = $this->getForeignKeyDetails($table);
        $foreignKeyCount   = count($foreignKeyDetails);

        foreach ($columns as $i => $column) {
            // SQLite identifies foreign keys in reverse order of appearance in SQL
            $columns[$i] = array_merge($column, $foreignKeyDetails[$foreignKeyCount - $column['id'] - 1]);
        }

        return $columns;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws Exception
     */
    private function getForeignKeyDetails(string $table): array
    {
        $createSql = $this->getCreateTableSQL($table);

        if (
            preg_match_all(
                '#
                    (?:CONSTRAINT\s+(\S+)\s+)?
                    (?:FOREIGN\s+KEY[^)]+\)\s*)?
                    REFERENCES\s+\S+\s*(?:\([^)]+\))?
                    (?:
                        [^,]*?
                        (NOT\s+DEFERRABLE|DEFERRABLE)
                        (?:\s+INITIALLY\s+(DEFERRED|IMMEDIATE))?
                    )?#isx',
                $createSql,
                $match,
            ) === 0
        ) {
            return [];
        }

        $names      = $match[1];
        $deferrable = $match[2];
        $deferred   = $match[3];
        $details    = [];

        for ($i = 0, $count = count($match[0]); $i < $count; $i++) {
            $details[] = [
                'constraint_name' => $names[$i] ?? '',
                'deferrable'      => isset($deferrable[$i]) && strcasecmp($deferrable[$i], 'deferrable') === 0,
                'deferred'        => isset($deferred[$i]) && strcasecmp($deferred[$i], 'deferred') === 0,
            ];
        }

        return $details;
    }

    public function createComparator(/* ComparatorConfig $config = new ComparatorConfig() */): Comparator
    {
        return new SQLite\Comparator($this->platform, func_num_args() > 0 ? func_get_arg(0) : new ComparatorConfig());
    }

    protected function selectTableNames(string $databaseName): Result
    {
        $sql = <<<'SQL'
SELECT name AS table_name
FROM sqlite_master
WHERE type = 'table'
  AND name != 'sqlite_sequence'
  AND name != 'geometry_columns'
  AND name != 'spatial_ref_sys'
UNION ALL
SELECT name
FROM sqlite_temp_master
WHERE type = 'table'
ORDER BY name
SQL;

        return $this->connection->executeQuery($sql);
    }

    protected function selectTableColumns(string $databaseName, ?string $tableName = null): Result
    {
        $sql = <<<'SQL'
            SELECT t.name AS table_name,
                   c.*
              FROM sqlite_master t
              JOIN pragma_table_info(t.name) c
SQL;

        $conditions = [
            "t.type = 'table'",
            "t.name NOT IN ('geometry_columns', 'spatial_ref_sys', 'sqlite_sequence')",
        ];
        $params     = [];

        if ($tableName !== null) {
            $conditions[] = 't.name = ?';
            $params[]     = $tableName;
        }

        $sql .= ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY t.name, c.cid';

        return $this->connection->executeQuery($sql, $params);
    }

    protected function selectIndexColumns(string $databaseName, ?string $tableName = null): Result
    {
        $sql = <<<'SQL'
            SELECT t.name AS table_name,
                   i.*
              FROM sqlite_master t
              JOIN pragma_index_list(t.name) i
SQL;

        $conditions = [
            "t.type = 'table'",
            "t.name NOT IN ('geometry_columns', 'spatial_ref_sys', 'sqlite_sequence')",
        ];
        $params     = [];

        if ($tableName !== null) {
            $conditions[] = 't.name = ?';
            $params[]     = $tableName;
        }

        $sql .= ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY t.name, i.seq';

        return $this->connection->executeQuery($sql, $params);
    }

    protected function selectForeignKeyColumns(string $databaseName, ?string $tableName = null): Result
    {
        $sql = <<<'SQL'
            SELECT t.name AS table_name,
                   p.*
              FROM sqlite_master t
              JOIN pragma_foreign_key_list(t.name) p
                ON p."seq" != '-1'
SQL;

        $conditions = [
            "t.type = 'table'",
            "t.name NOT IN ('geometry_columns', 'spatial_ref_sys', 'sqlite_sequence')",
        ];
        $params     = [];

        if ($tableName !== null) {
            $conditions[] = 't.name = ?';
            $params[]     = $tableName;
        }

        $sql .= ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY t.name, p.id DESC, p.seq';

        return $this->connection->executeQuery($sql, $params);
    }

    /**
     * {@inheritDoc}
     */
    protected function fetchTableOptionsByTable(string $databaseName, ?string $tableName = null): array
    {
        if ($tableName === null) {
            $tables = $this->listTableNames();
        } else {
            $tables = [$tableName];
        }

        $tableOptions = [];
        foreach ($tables as $table) {
            $comment = $this->parseTableCommentFromSQL($table, $this->getCreateTableSQL($table));

            if ($comment === null) {
                continue;
            }

            $tableOptions[$table]['comment'] = $comment;
        }

        return $tableOptions;
    }
}
