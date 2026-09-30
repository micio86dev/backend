<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

use Illuminate\Support\Facades\DB;

/**
 * A checksum of the database, used to prove a DENIED request changed nothing.
 *
 * Every table of the `public` schema is fingerprinted as
 * `count(*)` + `md5` of the ordered row text, so an INSERT, a DELETE and an
 * UPDATE (including a mere `updated_at` bump) all change it. It deliberately
 * covers the WHOLE schema rather than "the tables this route should touch":
 * the interesting failure of a broken guard is a write to a table nobody
 * thought to list.
 *
 * Only bookkeeping that legitimately moves without a request doing anything
 * is skipped ({@see self::IGNORED_TABLES}).
 */
final class AuthMatrixSnapshot
{
    /** @var list<string> */
    private const IGNORED_TABLES = ['migrations', 'cache', 'cache_locks'];

    /**
     * @return array<string, array{count: int, checksum: string}>
     */
    public static function take(): array
    {
        $snapshot = [];

        $tables = DB::select("select tablename from pg_tables where schemaname = 'public' order by tablename");

        foreach ($tables as $row) {
            $table = (string) $row->tablename;

            if (in_array($table, self::IGNORED_TABLES, true)) {
                continue;
            }

            $result = DB::selectOne(
                'select count(*) as n, coalesce(md5(string_agg(t::text, \'|\' order by t::text)), \'\') as checksum from "'.$table.'" t',
            );

            $snapshot[$table] = ['count' => (int) $result->n, 'checksum' => (string) $result->checksum];
        }

        return $snapshot;
    }

    /**
     * The tables whose contents differ between two snapshots, described
     * ("projects: 3 -> 2 rows"), so a failure names the table that moved.
     *
     * @param  array<string, array{count: int, checksum: string}>  $before
     * @param  array<string, array{count: int, checksum: string}>  $after
     * @return list<string>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $table => $state) {
            $was = $before[$table] ?? ['count' => 0, 'checksum' => ''];

            if ($was['count'] !== $state['count']) {
                $changes[] = "{$table}: {$was['count']} -> {$state['count']} rows";
            } elseif ($was['checksum'] !== $state['checksum']) {
                $changes[] = "{$table}: same row count ({$state['count']}) but content changed";
            }
        }

        return $changes;
    }
}
