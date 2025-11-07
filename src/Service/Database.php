<?php

namespace App\Service;

use App\PDO\Postgres;
use App\PDO\Mysql;
use App\PDO\Mariadb;
use PDO;
use App\Enums\DatabaseType;
use App\Database\AbstractDatabase;
use App\Database\Mysql as MysqlDB;
use App\Database\Postgres as PostgresDB;
use App\Database\Mariadb as MariadbDB;
use App\Service\Main;

class Database extends AbstractService {

    protected static ?PDO $pdoref;

    private static ?AbstractDatabase $dbref;

    private static bool $initializedDatabase = false;

    private static bool $initializedPDO = false;

    // Services.
    private Main $mainService;

    protected function __construct() {
        // Don't access Recipe here - it causes circular dependency.
        // Initialize lazily when needed.
    }

    final public static function instance(): Database {
        return self::setup_singleton();
    }

    private static function ensureInitializedPDO(): void {
        if (self::$initializedPDO) {
            return;
        }

        self::$initializedPDO = true;
        
        $instance = self::instance();
        $recipe = $instance->mainService->getRecipe();
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

    private static function ensureInitializedDatabase(): void {
        if (self::$initializedDatabase) {
            return;
        }

        self::$initializedDatabase = true;

        $instance = self::instance();
        $recipe = $instance->mainService->getRecipe();
        $dbType = $recipe->dbType;
        
        if (!DatabaseType::tryFrom($dbType)) {
            throw new \Exception('Unsupported dbType '.$dbType);
        }

        if ($dbType === DatabaseType::Postgres->value) {
            self::$dbref = new PostgresDB($recipe, $instance->mainService->cli);
        } else if ($dbType === DatabaseType::Mysql->value) {
            self::$dbref = new MysqlDB($recipe, $instance->mainService->cli);
        } else if ($dbType === DatabaseType::Mariadb->value) {
            self::$dbref = new MariadbDB($recipe, $instance->mainService->cli);
        } else {
            throw new \Exception('Unsupported dbType '.$dbType);
        }
    }

    private static function pdo(): PDO {
        self::ensureInitializedPDO();
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

    public static function getDatabase(): MysqlDB | PostgresDB | MariadbDB {
        self::ensureInitializedDatabase();
        return self::$dbref;
    }
}
