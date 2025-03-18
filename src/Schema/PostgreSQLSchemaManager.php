<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\Type;

use function array_change_key_case;
use function array_key_exists;
use function array_map;
use function assert;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_replace;
use function strtolower;
use function trim;

use const CASE_LOWER;

/**
 * PostgreSQL Schema Manager.
 *
 * @extends AbstractSchemaManager<PostgreSQLPlatform>
 */
class PostgreSQLSchemaManager extends AbstractSchemaManager
{
    /**
     * {@inheritDoc}
     */
    public function listSchemaNames(): array
    {
        return $this->connection->fetchFirstColumn(
            <<<'SQL'
SELECT schema_name
FROM   information_schema.schemata
WHERE  schema_name NOT LIKE 'pg\_%'
AND    schema_name != 'information_schema'
SQL,
        );
    }

    /**
     * Returns the name of the current schema.
     *
     * @deprecated Use {@link getCurrentSchemaName()} instead
     *
     * @throws Exception
     */
    protected function getCurrentSchema(): ?string
    {
        return $this->getCurrentSchemaName();
    }

    /**
     * Determines the name of the current schema.
     *
     * @deprecated Use {@link determineCurrentSchemaName()} instead
     *
     * @throws Exception
     */
    protected function determineCurrentSchema(): string
    {
        $currentSchema = $this->connection->fetchOne('SELECT current_schema()');
        assert(is_string($currentSchema));

        return $currentSchema;
    }

    protected function determineCurrentSchemaName(): ?string
    {
        return $this->determineCurrentSchema();
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
        assert($result === 1);

        // PostgreSQL returns identifiers that are keywords with quotes, we need them later, don't get
        // the idea to trim them here.
        $localColumns   = array_map('trim', explode(',', $values[1]));
        $foreignColumns = array_map('trim', explode(',', $values[3]));
        $foreignTable   = $values[2];

        return new ForeignKeyConstraint(
            $localColumns,
            $foreignTable,
            $foreignColumns,
            $tableForeignKey['conname'],
            [
                'onUpdate' => $onUpdate,
                'onDelete' => $onDelete,
                'deferrable' => $tableForeignKey['condeferrable'],
                'deferred' => $tableForeignKey['condeferred'],
            ],
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
     * @deprecated Use the schema name and the unqualified table name separately instead.
     *
     * {@inheritDoc}
     */
    protected function _getPortableTableDefinition(array $table): string
    {
        // @phpstan-ignore missingType.checkedException
        $currentSchema = $this->getCurrentSchema();

        if ($table['schema_name'] === $currentSchema) {
            return $table['table_name'];
        }

        return $table['schema_name'] . '.' . $table['table_name'];
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableTableIndexesList(array $rows, string $tableName): array
    {
        $buffer = [];
        foreach ($rows as $row) {
            $colNumbers    = array_map('intval', explode(' ', $row['indkey']));
            $columnNameSql = sprintf(
                <<<'SQL'
                SELECT attnum,
                       quote_ident(attname) AS attname
                FROM pg_attribute
                WHERE attrelid = %d
                  AND attnum IN (%s)
                ORDER BY attnum
                SQL,
                $row['indrelid'],
                implode(', ', $colNumbers),
            );

            // @phpstan-ignore missingType.checkedException
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
                        'non_unique' => ! $row['indisunique'],
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
        if ($sequence['schemaname'] !== 'public') {
            $sequenceName = $sequence['schemaname'] . '.' . $sequence['relname'];
        } else {
            $sequenceName = $sequence['relname'];
        }

        return new Sequence($sequenceName, (int) $sequence['increment_by'], (int) $sequence['min_value']);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableTableColumnDefinition(array $tableColumn): Column
    {
        $tableColumn = array_change_key_case($tableColumn, CASE_LOWER);

        $length = null;

        if (
            in_array(strtolower($tableColumn['type']), ['varchar', 'bpchar'], true)
            && preg_match('/\((\d*)\)/', $tableColumn['complete_type'], $matches) === 1
        ) {
            $length = (int) $matches[1];
        }

        $autoincrement = $tableColumn['attidentity'] === 'd';

        $matches = [];

        assert(array_key_exists('default', $tableColumn));
        assert(array_key_exists('complete_type', $tableColumn));

        if ($tableColumn['default'] !== null) {
            if (preg_match("/^['(](.*)[')]::/", $tableColumn['default'], $matches) === 1) {
                $tableColumn['default'] = $matches[1];
            } elseif (preg_match('/^NULL::/', $tableColumn['default']) === 1) {
                $tableColumn['default'] = null;
            }
        }

        if ($length === -1 && isset($tableColumn['atttypmod'])) {
            $length = $tableColumn['atttypmod'] - 4;
        }

        if ((int) $length <= 0) {
            $length = null;
        }

        $fixed = false;

        if (! isset($tableColumn['name'])) {
            $tableColumn['name'] = '';
        }

        $precision = null;
        $scale     = 0;
        $jsonb     = null;

        $dbType = strtolower($tableColumn['type']);
        if (
            $tableColumn['domain_type'] !== null
            && $tableColumn['domain_type'] !== ''
            && ! $this->platform->hasDoctrineTypeMappingFor($tableColumn['type'])
        ) {
            $dbType                       = strtolower($tableColumn['domain_type']);
            $tableColumn['complete_type'] = $tableColumn['domain_complete_type'];
        }

        $type = $this->platform->getDoctrineTypeMapping($dbType);

        switch ($dbType) {
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
                if ($tableColumn['default'] === 'true') {
                    $tableColumn['default'] = true;
                }

                if ($tableColumn['default'] === 'false') {
                    $tableColumn['default'] = false;
                }

                $length = null;
                break;

            case 'json':
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
                        '([A-Za-z]+\(([0-9]+),([0-9]+)\))',
                        $tableColumn['complete_type'],
                        $match,
                    ) === 1
                ) {
                    $precision = (int) $match[1];
                    $scale     = (int) $match[2];
                    $length    = null;
                }

                break;

            case 'year':
                $length = null;
                break;

            // PostgreSQL 9.4+ only
            case 'jsonb':
                $jsonb = true;
                break;
        }

        if (
            is_string($tableColumn['default']) && preg_match(
                "('([^']+)'::)",
                $tableColumn['default'],
                $match,
            ) === 1
        ) {
            $tableColumn['default'] = $match[1];
        }

        $options = [
            'length'        => $length,
            'notnull'       => (bool) $tableColumn['isnotnull'],
            'default'       => $tableColumn['default'],
            'precision'     => $precision,
            'scale'         => $scale,
            'fixed'         => $fixed,
            'autoincrement' => $autoincrement,
        ];

        if ($tableColumn['comment'] !== null) {
            $options['comment'] = $tableColumn['comment'];
        }

        $column = new Column($tableColumn['field'], Type::getType($type), $options);

        if (! empty($tableColumn['collation'])) {
            $column->setPlatformOption('collation', $tableColumn['collation']);
        }

        if ($column->getType() instanceof JsonType) {
            $column->setPlatformOption('jsonb', $jsonb);
        }

        return $column;
    }

    /**
     * Parses a default value expression as given by PostgreSQL
     */
    private function parseDefaultExpression(?string $default): ?string
    {
        if ($default === null) {
            return $default;
        }

        return str_replace("''", "'", $default);
    }

    protected function selectTableNames(string $databaseName): Result
    {
        $sql = <<<'SQL'
SELECT quote_ident(table_name) AS table_name,
       table_schema AS schema_name
FROM information_schema.tables
WHERE table_catalog = ?
  AND table_schema NOT LIKE 'pg\_%'
  AND table_schema != 'information_schema'
  AND table_name != 'geometry_columns'
  AND table_name != 'spatial_ref_sys'
  AND table_type = 'BASE TABLE'
SQL;

        return $this->connection->executeQuery($sql, [$databaseName]);
    }

    protected function selectTableColumns(string $databaseName, ?string $tableName = null): Result
    {
        $params = [];

        $sql = sprintf(
            <<<'SQL'
            SELECT
            quote_ident(n.nspname) AS schema_name,
            quote_ident(c.relname) AS table_name,
            a.attnum,
            quote_ident(a.attname) AS field,
            t.typname AS type,
            format_type(a.atttypid, a.atttypmod) AS complete_type,
            (
                SELECT CASE
                    WHEN collprovider = 'c' THEN tc.collcollate
                    WHEN collprovider = 'd' THEN null
                    ELSE tc.collname
                END
                FROM pg_catalog.pg_collation tc WHERE tc.oid = a.attcollation
            ) AS collation,
            (SELECT t1.typname FROM pg_catalog.pg_type t1 WHERE t1.oid = t.typbasetype) AS domain_type,
            (SELECT format_type(t2.typbasetype, t2.typtypmod) FROM
              pg_catalog.pg_type t2 WHERE t2.typtype = 'd' AND t2.oid = a.atttypid) AS domain_complete_type,
            a.attnotnull AS isnotnull,
            a.attidentity,
            (SELECT 't'
             FROM pg_index
             WHERE c.oid = pg_index.indrelid
                AND pg_index.indkey[0] = a.attnum
                AND pg_index.indisprimary = 't'
            ) AS pri,
            (%s) AS default,
            (SELECT pg_description.description
                FROM pg_description WHERE pg_description.objoid = c.oid AND a.attnum = pg_description.objsubid
            ) AS comment
            FROM pg_attribute a
                INNER JOIN pg_class c
                    ON c.oid = a.attrelid
                INNER JOIN pg_type t
                    ON t.oid = a.atttypid
                INNER JOIN pg_namespace n
                    ON n.oid = c.relnamespace
                LEFT JOIN pg_depend d
                    ON d.objid = c.oid
                        AND d.deptype = 'e'
                        AND d.classid = (SELECT oid FROM pg_class WHERE relname = 'pg_class')
            WHERE a.attnum > 0
                AND d.refobjid IS NULL
                -- 'r' for regular tables - 'p' for partitioned tables
                AND c.relkind IN('r', 'p')
                -- exclude partitions (tables that inherit from partitioned tables)
                AND NOT EXISTS (
                    SELECT 1
                    FROM pg_inherits
                    INNER JOIN pg_class parent on pg_inherits.inhparent = parent.oid
                        AND parent.relkind = 'p'
                    WHERE inhrelid = c.oid
                )
                AND %s
            ORDER BY a.attnum
            SQL,
            $this->platform->getDefaultColumnValueSQLSnippet(),
            implode(' AND ', $this->buildQueryConditions($tableName, $params)),
        );

        return $this->connection->executeQuery($sql, $params);
    }

    protected function selectIndexColumns(string $databaseName, ?string $tableName = null): Result
    {
        $params = [];

        $sql = sprintf(
            <<<'SQL'
            SELECT
                   quote_ident(tn.nspname) AS schema_name,
                   quote_ident(tc.relname) AS table_name,
                   quote_ident(ic.relname) AS relname,
                   i.indisunique,
                   i.indisprimary,
                   i.indkey,
                   i.indrelid,
                   pg_get_expr(indpred, indrelid) AS "where"
              FROM pg_index i
                   JOIN pg_class AS tc ON tc.oid = i.indrelid
                   JOIN pg_namespace tn ON tn.oid = tc.relnamespace
                   JOIN pg_class AS ic ON ic.oid = i.indexrelid
             WHERE ic.oid IN (
                SELECT indexrelid
                FROM pg_index i
                JOIN pg_class AS c ON c.oid = i.indrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE %s)
            SQL,
            implode(' AND ', $this->buildQueryConditions($tableName, $params)),
        );

        return $this->connection->executeQuery($sql, $params);
    }

    protected function selectForeignKeyColumns(string $databaseName, ?string $tableName = null): Result
    {
        $params = [];

        $sql = sprintf(
            <<<'SQL'
           SELECT
                  quote_ident(tn.nspname) AS schema_name,
                  quote_ident(tc.relname) AS table_name,
                  quote_ident(r.conname) as conname,
                  pg_get_constraintdef(r.oid, true) as condef,
                  r.condeferrable,
                  r.condeferred
                  FROM pg_constraint r
                      JOIN pg_class AS tc ON tc.oid = r.conrelid
                      JOIN pg_namespace tn ON tn.oid = tc.relnamespace
                  WHERE r.conrelid IN
                  (
                      SELECT c.oid
                      FROM pg_class c
                        JOIN pg_namespace n
                            ON n.oid = c.relnamespace
                        WHERE %s)
                  AND r.contype = 'f'
        SQL,
            implode(' AND ', $this->buildQueryConditions($tableName, $params)),
        );

        return $this->connection->executeQuery($sql, $params);
    }

    /**
     * {@inheritDoc}
     */
    protected function fetchTableOptionsByTable(string $databaseName, ?string $tableName = null): array
    {
        $params = [];

        $sql = sprintf(
            <<<'SQL'
            SELECT quote_ident(n.nspname) AS schema_name,
                   quote_ident(c.relname) AS table_name,
                   CASE c.relpersistence WHEN 'u' THEN true ELSE false END as unlogged,
                   obj_description(c.oid, 'pg_class') AS comment
            FROM pg_class c
                 INNER JOIN pg_namespace n
                     ON n.oid = c.relnamespace
            WHERE
                  c.relkind = 'r'
              AND %s
            SQL,
            implode(' AND ', $this->buildQueryConditions($tableName, $params)),
        );

        $tableOptions = [];
        foreach ($this->connection->iterateAssociative($sql, $params) as $row) {
            $tableOptions[$this->_getPortableTableDefinition($row)] = $row;
        }

        return $tableOptions;
    }

    /**
     * @param list<int|string> $params
     *
     * @return non-empty-list<string>
     */
    private function buildQueryConditions(?string $tableName, array &$params): array
    {
        $conditions = [];

        if ($tableName !== null) {
            if (str_contains($tableName, '.')) {
                [$schemaName, $tableName] = explode('.', $tableName);

                $conditions[] = 'n.nspname = ?';
                $params[]     = $schemaName;
            } else {
                $conditions[] = 'n.nspname = ANY(current_schemas(false))';
            }

            $conditions[] = 'c.relname = ?';
            $params[]     = $tableName;
        }

        $conditions[] = "n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast')";

        return $conditions;
    }
}
