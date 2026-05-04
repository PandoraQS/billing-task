<?php
namespace App;

class Database
{
    private static ?\PDO $instance = null;

    // Returns a single shared PDO connection (singleton).
    // Credentials are read from environment variables injected by Docker, not to have hardcode credentials in source code.
    public static function connect(): \PDO
    {
        if (self::$instance) {
            return self::$instance;
        }

        $host   = getenv('DB_HOST') ?: 'mysql';
        $dbname = getenv('DB_NAME') ?: 'billing_task';
        $user   = getenv('DB_USER') ?: 'root';
        $pass   = getenv('DB_PASS') ?: 'secret';

        $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

        // ERRMODE_EXCEPTION: all query errors throw exceptions, never fail silently.
        // FETCH_ASSOC: rows are returned as named arrays, not positional.
        // EMULATE_PREPARES false: uses native MySQL prepared statements instead of emulating them in PHP, which guarantees values never touch the query string and fully prevents SQL injection.
        self::$instance = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return self::$instance;
    }
}