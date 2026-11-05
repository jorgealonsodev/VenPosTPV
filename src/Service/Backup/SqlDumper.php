<?php

declare(strict_types=1);

namespace VenPosTPV\Service\Backup;

use Doctrine\DBAL\Connection;

/**
 * A pure-PHP SQL dump of the TPV database (shared hosting may have no mysqldump).
 *
 * The dump is one consistent InnoDB snapshot and is streamed through the writer:
 * rows are read in pages by primary key (or by offset inside the snapshot for a
 * table without one), never a whole table at once. It restores with
 * `mysql db < file.sql`, phpMyAdmin «Importar» and Plesk «Importar volcado»:
 *
 * - every base table is dropped and recreated with SHOW CREATE TABLE, which also
 *   keeps the AUTO_INCREMENT counters, so ticket numbers continue after a restore;
 * - rows are multi-row INSERTs with explicit columns (generated columns are left
 *   out), strings escaped by the connection's quote() and binary columns written
 *   as hexadecimal literals, so NUL bytes and invalid UTF-8 survive any import;
 * - TIMESTAMP values are read and written in UTC (`SET time_zone = '+00:00'`);
 * - the triggers (fiscal_record_no_update / fiscal_record_no_delete) are recreated
 *   after the data, without their DEFINER (restoring on another host or user would
 *   otherwise need SUPER). A trigger whose body has no semicolon is a plain
 *   statement, which every importer accepts; one with a BEGIN … END body is wrapped
 *   in a `DELIMITER ;;` block, which the mysql client and phpMyAdmin accept.
 *
 * Views, stored routines and events are not dumped: the TPV has none.
 */
class SqlDumper
{
    private const ROWS_PER_PAGE = 1000;
    private const MAX_INSERT_BYTES = 1_000_000;
    private const BINARY_TYPES = [
        'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit',
        'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring',
        'multipolygon', 'geometrycollection', 'geomcollection',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param \Closure(string): void $write receives the dump in pieces
     *
     * @return int the number of tables dumped
     */
    public function dump(\Closure $write): int
    {
        $connection = $this->connection;
        $previousTimeZone = (string) $connection->fetchOne('SELECT @@session.time_zone');
        $connection->executeStatement("SET time_zone = '+00:00'");
        $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $connection->executeStatement('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        try {
            $write(sprintf(
                "-- VenPosTPV database backup\n-- Database: %s\n-- Server: %s\n-- Created: %s UTC\n\n"
                . "SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n",
                (string) $connection->fetchOne('SELECT DATABASE()'),
                (string) $connection->fetchOne('SELECT VERSION()'),
                gmdate('Y-m-d H:i:s')
            ));

            $tables = $this->baseTables();
            foreach ($tables as $table) {
                $this->dumpTable($table, $write);
            }
            $this->dumpTriggers($write);
            $write("SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            try {
                $connection->executeStatement('COMMIT');
            } finally {
                $connection->executeStatement('SET time_zone = ' . (string) $connection->quote($previousTimeZone));
            }
        }

        return \count($tables);
    }

    /**
     * The statements that recreate one trigger, without its DEFINER.
     */
    public static function triggerStatement(string $name, string $createStatement): string
    {
        $create = preg_replace(
            '/\ACREATE\s+DEFINER\s*=\s*(?:`[^`]*`|\'[^\']*\'|[^@\s]+)@(?:`[^`]*`|\'[^\']*\'|\S+)\s+/i',
            'CREATE ',
            trim($createStatement)
        ) ?? trim($createStatement);
        $create = rtrim($create, "; \t\n\r");
        $drop = sprintf("DROP TRIGGER IF EXISTS %s;\n", self::identifier($name));

        if (!str_contains($create, ';')) {
            return $drop . $create . ";\n";
        }

        return $drop . "DELIMITER ;;\n" . $create . ";;\nDELIMITER ;\n";
    }

    /** @return list<string> */
    private function baseTables(): array
    {
        $tables = [];
        foreach ($this->connection->fetchAllNumeric("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
            $tables[] = (string) $row[0];
        }
        sort($tables, SORT_STRING);

        return $tables;
    }

    /** @param \Closure(string): void $write */
    private function dumpTable(string $table, \Closure $write): void
    {
        $create = $this->connection->fetchNumeric('SHOW CREATE TABLE ' . self::identifier($table));
        if ($create === false || !isset($create[1])) {
            throw new \RuntimeException(sprintf('Cannot read the structure of table %s.', $table));
        }
        $write(sprintf(
            "-- Table %1\$s\nDROP TABLE IF EXISTS %1\$s;\n%2\$s;\n\n",
            self::identifier($table),
            (string) $create[1]
        ));

        $columns = $this->connection->fetchAllAssociative(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, EXTRA AS extra FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table]
        );
        $binary = [];
        foreach ($columns as $column) {
            if (stripos((string) $column['extra'], 'GENERATED') === false) {
                $binary[(string) $column['name']] = \in_array(strtolower((string) $column['type']), self::BINARY_TYPES, true);
            }
        }
        if ($binary === []) {
            return;
        }

        $key = $this->connection->fetchFirstColumn(
            "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'
             ORDER BY ORDINAL_POSITION",
            [$table]
        );
        $key = array_map('strval', $key);
        if (array_diff($key, array_keys($binary)) !== []) {
            $key = []; // a generated key column is not selected: page by offset instead
        }

        $select = 'SELECT ' . implode(', ', array_map(self::identifier(...), array_keys($binary)))
            . ' FROM ' . self::identifier($table);
        $insert = sprintf(
            'INSERT INTO %s (%s) VALUES',
            self::identifier($table),
            implode(', ', array_map(self::identifier(...), array_keys($binary)))
        );
        $order = $key === [] ? '' : ' ORDER BY ' . implode(', ', array_map(self::identifier(...), $key));
        $after = '(' . implode(', ', array_map(self::identifier(...), $key)) . ') > (' . implode(', ', array_fill(0, \count($key), '?')) . ')';

        $last = null;
        $offset = 0;
        do {
            if ($key === []) {
                $rows = $this->connection->fetchAllAssociative(sprintf('%s LIMIT %d OFFSET %d', $select, self::ROWS_PER_PAGE, $offset));
                $offset += \count($rows);
            } else {
                $rows = $last === null
                    ? $this->connection->fetchAllAssociative(sprintf('%s%s LIMIT %d', $select, $order, self::ROWS_PER_PAGE))
                    : $this->connection->fetchAllAssociative(sprintf('%s WHERE %s%s LIMIT %d', $select, $after, $order, self::ROWS_PER_PAGE), $last);
            }
            if ($rows === []) {
                break;
            }

            $this->writeInserts($insert, $rows, $binary, $write);
            if ($key !== []) {
                $lastRow = $rows[\count($rows) - 1];
                $last = array_map(static fn (string $column): mixed => $lastRow[$column], $key);
            }
        } while (\count($rows) === self::ROWS_PER_PAGE);

        $write("\n");
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, bool>        $binary column name => written as hexadecimal
     * @param \Closure(string): void     $write
     */
    private function writeInserts(string $insert, array $rows, array $binary, \Closure $write): void
    {
        $tuples = [];
        $size = 0;
        foreach ($rows as $row) {
            $values = [];
            foreach ($binary as $column => $isBinary) {
                $values[] = $this->literal($row[$column], $isBinary);
            }
            $tuple = '(' . implode(',', $values) . ')';

            if ($tuples !== [] && $size + \strlen($tuple) > self::MAX_INSERT_BYTES) {
                $write($insert . "\n" . implode(",\n", $tuples) . ";\n");
                $tuples = [];
                $size = 0;
            }
            $tuples[] = $tuple;
            $size += \strlen($tuple) + 2;
        }
        if ($tuples !== []) {
            $write($insert . "\n" . implode(",\n", $tuples) . ";\n");
        }
    }

    private function literal(mixed $value, bool $binary): string
    {
        if ($value === null) {
            return 'NULL';
        }
        $value = (string) $value;
        if ($binary) {
            return $value === '' ? "''" : '0x' . bin2hex($value);
        }

        return (string) $this->connection->quote($value);
    }

    /** @param \Closure(string): void $write */
    private function dumpTriggers(\Closure $write): void
    {
        $triggers = $this->connection->fetchAllAssociative('SHOW TRIGGERS');
        if ($triggers === []) {
            return;
        }

        $write("-- Triggers\n");
        foreach ($triggers as $trigger) {
            $name = (string) $trigger['Trigger'];
            $create = $this->connection->fetchAssociative('SHOW CREATE TRIGGER ' . self::identifier($name));
            if ($create === false || !isset($create['SQL Original Statement'])) {
                throw new \RuntimeException(sprintf('Cannot read the trigger %s.', $name));
            }
            $write(self::triggerStatement($name, (string) $create['SQL Original Statement']));
        }
        $write("\n");
    }

    private static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
