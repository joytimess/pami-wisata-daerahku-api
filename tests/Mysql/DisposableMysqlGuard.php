<?php

use Illuminate\Support\Facades\DB;

if (! function_exists('assertDisposableMysqlAccount')) {
    /**
     * Fail-closed account guard: runs before any CREATE DATABASE. The account must be a dedicated, non-root test
     * login whose only privileges are DDL/DML on the disposable database name patterns, with nothing else visible.
     *
     * @param  array<string, mixed>  $connection
     */
    function assertDisposableMysqlAccount(array $connection): void
    {
        $username = strtolower((string) ($connection['username'] ?? ''));
        if ($username === '' || $username === 'root' || str_contains($username, 'admin')) {
            throw new RuntimeException('Disposable MySQL tests require a dedicated non-root test account.');
        }
        if (! preg_match('/^pami_(doku|phase1b)_[a-z0-9]{1,12}_test$/D', (string) ($connection['database'] ?? ''))) {
            throw new RuntimeException('The bootstrap database must match pami_(doku|phase1b)_<alnum>_test.');
        }
        $allowedScope = '/^GRANT [A-Z ,]+ ON `pami\\\\_(doku|phase1b)\\\\_%\\\\_test`\.\* TO /D';
        foreach (DB::select('SHOW GRANTS FOR CURRENT_USER()') as $row) {
            $grant = (string) array_values((array) $row)[0];
            $global = preg_match('/^GRANT USAGE ON \*\.\* TO /D', $grant) === 1;
            if (str_contains($grant, 'WITH GRANT OPTION') || (! $global && preg_match($allowedScope, $grant) !== 1)) {
                throw new RuntimeException('Disposable MySQL account has a grant outside the allowed database patterns.');
            }
        }
        foreach (DB::select('SELECT schema_name AS name FROM information_schema.schemata') as $schema) {
            if (! in_array($schema->name, ['information_schema', 'performance_schema'], true)
                && ! preg_match('/^pami_(doku|phase1b)_[a-z0-9]{1,12}_test$/D', $schema->name)) {
                throw new RuntimeException('Disposable MySQL account can see a non-disposable schema.');
            }
        }
    }
}
