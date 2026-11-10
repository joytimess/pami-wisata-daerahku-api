<?php

use Illuminate\Support\Facades\DB;

if (! function_exists('assertDisposableMysqlAccount')) {
    /**
     * Fail-closed account guard: runs before any CREATE DATABASE. The account must be a dedicated, non-root test
     * login whose only privileges are DDL/DML on the disposable database name patterns, with nothing else visible.
     *
     * @param  array<string, mixed>  $connection
     */
    function assertDisposableMysqlAccount(array $connection, ?string $expectedDatabase = null): void
    {
        if (! app()->environment('testing') || config('app.env') !== 'testing'
            || ! in_array(env('MYSQL_CONCURRENCY_TESTS'), [true, 1, '1'], true)
            || config('database.default') !== 'mysql' || ($connection['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('Disposable MySQL tests require testing, MySQL and explicit opt-in.');
        }
        if (! empty($connection['url']) || array_key_exists('read', $connection) || array_key_exists('write', $connection)
            || app()->configurationIsCached() || $connection !== config('database.connections.mysql')) {
            throw new RuntimeException('Disposable MySQL tests reject URL, replica overrides, cached or mismatched configuration.');
        }
        $username = strtolower(trim((string) ($connection['username'] ?? '')));
        if ($username === '' || $username === 'root' || str_contains($username, 'admin')) {
            throw new RuntimeException('Disposable MySQL tests require a dedicated non-root test account.');
        }
        $database = (string) ($connection['database'] ?? '');
        $databasePattern = '/^pami_(payment|doku|phase1b)_[a-z0-9]{1,12}_test$/D';
        if (! preg_match($databasePattern, $database, $databaseMatch)
            || ($expectedDatabase !== null && $expectedDatabase !== $database)) {
            throw new RuntimeException('The database must match pami_(payment|doku|phase1b)_<alnum>_test and the expected target.');
        }
        $selected = DB::selectOne('SELECT DATABASE() AS db, CURRENT_USER() AS account');
        if (($selected->db ?? null) !== $database
            || explode('@', (string) ($selected->account ?? ''), 2)[0] !== $connection['username']) {
            throw new RuntimeException('Disposable MySQL selected database or authenticated account differs from configuration.');
        }
        $allowedScope = '/^GRANT [A-Z ,]+ ON `pami\\\\_(payment|doku|phase1b)\\\\_%\\\\_test`\.\* TO /D';
        $hasDatabaseGrant = false;
        foreach (DB::select('SHOW GRANTS FOR CURRENT_USER()') as $row) {
            $grant = (string) (array_values((array) $row)[0] ?? '');
            $global = preg_match('/^GRANT USAGE ON \*\.\* TO /D', $grant) === 1;
            $scoped = preg_match($allowedScope, $grant, $grantMatch) === 1;
            if (str_contains($grant, 'WITH GRANT OPTION') || (! $global && ! $scoped)) {
                throw new RuntimeException('Disposable MySQL account has a grant outside the allowed database patterns.');
            }
            $hasDatabaseGrant = $hasDatabaseGrant || ($scoped && $grantMatch[1] === $databaseMatch[1]);
        }
        if (! $hasDatabaseGrant) {
            throw new RuntimeException('Disposable MySQL account lacks a grant for the selected disposable namespace.');
        }
        $hasDatabase = false;
        foreach (DB::select('SELECT schema_name AS name FROM information_schema.schemata') as $schema) {
            if (! in_array($schema->name, ['information_schema', 'performance_schema'], true)
                && ! preg_match($databasePattern, $schema->name)) {
                throw new RuntimeException('Disposable MySQL account can see a non-disposable schema.');
            }
            $hasDatabase = $hasDatabase || $schema->name === $database;
        }
        if (! $hasDatabase) {
            throw new RuntimeException('Disposable MySQL selected database is absent from visible schemas.');
        }
    }
}
