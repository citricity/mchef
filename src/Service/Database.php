<?php

namespace App\Service;

use App\PDO\Postgres;
use App\PDO\Mysql;
use App\PDO\Mariadb;
use App\StaticVars;
use PDO;
use App\Enums\DatabaseType;

class Database extends AbstractService {

    protected static PDO $pdoref;

    protected function __construct() {
        $recipe = StaticVars::$recipe;
        $dbType = $recipe->dbType;
        if (!DatabaseType::tryFrom($dbType)) {
            throw new \Exception('Unsupported dbType '.$dbType);
        }

        if ($dbType === DatabaseType::Postgres->value) {
            self::$pdoref = new Postgres();
        } else if ($dbType === DatabaseType::Mysql->value) {
            self::$pdoref = new Mysql();
        } else if ($dbType === DatabaseType::Mariadb->value) {
            self::$pdoref = new Mariadb();
        } else {
            throw new \Exception('Unsupported dbType '.$dbType);
        }
    }

    final public static function instance(): Database {
        return self::setup_singleton();
    }

    private static function pdo(): PDO {
        self::instance();
        return self::$pdoref;
    }

    public static function query(string $query, ...$args)  {
        $query = trim($query);
        $statement = self::pdo()->prepare($query);
        if (!$statement) {
            throw new \Exception('Failed to parse query '.$query);
        }

        $success = $statement->execute($args);
        if (!$success) {
            throw new \Exception('Database query failed ' . $query . ' ' . var_export($args, true));
        }
        return $statement;
    }
}
