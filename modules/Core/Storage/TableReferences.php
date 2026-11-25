<?php

declare(strict_types=1);

namespace Modules\Core\Storage;

use CodeIgniter\Database\BaseConnection;
use Generator;

/**
 * Reads the columns of a module's tables in bounded batches for its `FileReferenceProvider`
 * (D-55-01): every row, soft-deleted ones (the trash) included, since the Query Builder is used
 * directly. A table that does not exist yet (module not migrated) gives nothing; any other
 * database error is thrown, which aborts the scan.
 *
 * ```php
 * public function references(): iterable
 * {
 *     yield from TableReferences::texts('news', ['cover']);
 *     yield from TableReferences::texts('news_translations', ['summary', 'body']);
 * }
 * ```
 */
final class TableReferences
{
    public const BATCH = 500;

    /**
     * The non-empty values of the columns, as strings (keys, URLs or texts to scan).
     *
     * @param list<string>                                          $columns
     * @param BaseConnection<object|resource, object|resource>|null $db
     *
     * @return Generator<int, string>
     */
    public static function texts(string $table, array $columns, string $idColumn = 'id', ?BaseConnection $db = null): Generator
    {
        foreach (self::rows($table, $columns, $idColumn, $db) as $row) {
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;

                if (is_scalar($value) && (string) $value !== '') {
                    yield (string) $value;
                }
            }
        }
    }

    /**
     * The stored-file ids of a column (e.g. `files.stored_file_id`).
     *
     * @param BaseConnection<object|resource, object|resource>|null $db
     *
     * @return Generator<int, int>
     */
    public static function ids(string $table, string $column, string $idColumn = 'id', ?BaseConnection $db = null): Generator
    {
        foreach (self::rows($table, [$column], $idColumn, $db) as $row) {
            if (is_numeric($row[$column] ?? null)) {
                yield (int) $row[$column];
            }
        }
    }

    /**
     * @param list<string>                                          $columns
     * @param BaseConnection<object|resource, object|resource>|null $db
     *
     * @return Generator<int, array<string, mixed>>
     */
    private static function rows(string $table, array $columns, string $idColumn, ?BaseConnection $db): Generator
    {
        $db ??= db_connect();

        if (! $db->tableExists($table)) {
            return;
        }

        $last = 0;

        while (true) {
            $rows = $db->table($table)->select(implode(', ', array_unique([$idColumn, ...$columns])))
                ->where($idColumn . ' >', $last)->orderBy($idColumn)->limit(self::BATCH)->get()->getResultArray();

            foreach ($rows as $row) {
                yield $row;
            }

            if (count($rows) < self::BATCH) {
                return;
            }

            $last = (int) $rows[count($rows) - 1][$idColumn];
        }
    }
}
