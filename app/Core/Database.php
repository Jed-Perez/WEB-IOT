<?php
declare(strict_types=1);

namespace App\Core;

use mysqli;

final class Database
{
    private static ?mysqli $connection = null;

    public static function connection(): mysqli
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $config = require dirname(__DIR__, 2) . '/config/database.php';
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        self::$connection = new mysqli(
            $config['host'],
            $config['username'],
            $config['password'],
            $config['database'],
            $config['port']
        );
        self::$connection->set_charset('utf8mb4');
        self::$connection->query("SET time_zone = '+00:00'");

        return self::$connection;
    }

    public static function query(string $sql, array $parameters = []): \mysqli_stmt
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }
}
