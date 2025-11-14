<?php

namespace App\Database;

use App\Exceptions\ExecFailed;
use App\Helpers\OS;
use App\Service\Docker;
use App\Service\Main;

class Mysql extends AbstractDatabase implements DatabaseInterface {

    public function dropAllTables(): void {
        $mainService   = Main::instance($this->cli);
        $dbContainer   = $mainService->getDockerDatabaseContainerName();
        $dockerService = Docker::instance($this->cli);
        $recipe        = $this->recipe;
        $dbUser        = $recipe->dbUser;
        $dbName        = $this->getDbName(); // single source of truth
        $dbPassword    = $recipe->dbPassword;

        // Build the SQL query - use double quotes for GROUP_CONCAT delimiter to avoid shell interpretation
        $sqlQuery = 'SET FOREIGN_KEY_CHECKS = 0; '
            . 'SET GROUP_CONCAT_MAX_LEN=32768; '
            . 'SET @tables = NULL; '
            . 'SELECT GROUP_CONCAT(CONCAT("`", table_name, "`")) INTO @tables FROM information_schema.tables WHERE table_schema = ' . escapeshellarg($dbName) . '; '
            . 'SET @tables = CONCAT("DROP TABLE IF EXISTS ", @tables); '
            . 'PREPARE stmt FROM @tables; '
            . 'EXECUTE stmt; '
            . 'DEALLOCATE PREPARE stmt; '
            . 'SET FOREIGN_KEY_CHECKS = 1;';

        // Build the mysql command properly escaped for shell execution
        // Use the same pattern as buildDBQueryDockerCommand
        $dbdeletecmd = 'mysql -u' . escapeshellarg($dbUser)
            . ' -p' . escapeshellarg($dbPassword)
            . ' -D ' . escapeshellarg($dbName)
            . ' -e ' . escapeshellarg($sqlQuery);
        try {
            $this->cli->info("Dropping all tables from database $dbName");
            $this->cli->info("Command: $dbdeletecmd");
            $dockerService->execute($dbContainer, $this->buildEscapedDockerCommand($dbdeletecmd));
            return;
        } catch (ExecFailed $e) {
            throw new \RuntimeException('Failed to drop all tables', 0, $e);
        }
    }

    public function dbeaverConnectionString(): string {
        if (empty($this->recipe->dbHostPort)) {
            throw new \Error('The recipe must have dbHostPort specified in order to generate a connection string');
        }
        $dbeavercmd = OS::isWindows() ? 'dbeaver.exe' : 'open -na "DBeaver" --args';
        $conString = sprintf(
            'driver=mysql|host=localhost|port=%s|database=%s|user=%s|password=%s',
            $this->recipe->dbHostPort,
            $this->getDbName(),
            $this->recipe->dbUser,
            $this->recipe->dbPassword
        );

        // Escape the *whole thing once*
        $cmd = $dbeavercmd . ' -con ' . escapeshellarg($conString);
        return $cmd;
    }

    /**
     * Return a MySQL Workbench connection string (MySQL only).
     * @return string
     */
    public function mysqlWorkbenchConnectionString(): string {
        if (empty($this->recipe->dbHostPort)) {
            throw new \Error('The recipe must have dbHostPort specified in order to generate a connection string');
        }

        $workbenchCmd = OS::isWindows() ? 'MySQLWorkbench.exe' : 'open -a "MySQL Workbench"';
        $connectionString = sprintf(
            'mysql://%s:%s@localhost:%s/%s',
            urlencode($this->recipe->dbUser),
            urlencode($this->recipe->dbPassword),
            $this->recipe->dbHostPort,
            urlencode($this->getDbName())
        );

        return $workbenchCmd . ' --query=' . escapeshellarg($connectionString);
    }

    /**
     * Return a mysql CLI connection string (MySQL only).
     * @return string
     */
    public function mysqlConnectionString(): string {
        if (empty($this->recipe->dbHostPort)) {
            throw new \Error('The recipe must have dbHostPort specified in order to generate a connection string');
        }

        return sprintf(
            'mysql -h localhost -P %s -u %s -p%s %s',
            escapeshellarg($this->recipe->dbHostPort),
            escapeshellarg($this->recipe->dbUser),
            escapeshellarg($this->recipe->dbPassword),
            escapeshellarg($this->getDbName())
        );
    }

    public function pgAdminConnectionString(): string {
        throw new \InvalidArgumentException('pgAdmin can only be used with PostgreSQL databases');
    }

    public function psqlConnectionCommand(): array {
        throw new \InvalidArgumentException('psql can only be used with PostgreSQL databases');
    }

    public function buildDBQueryDockerCommand(string $query, bool $isCheck = false): string {
        $mainService   = Main::instance($this->cli);
        $dbContainer   = $mainService->getDockerDatabaseContainerName();
        $recipe        = $this->recipe;
        $mysqlCommand  = 'mysql -u' . $recipe->dbUser . ' -p' . $recipe->dbPassword . ' -D ' . $recipe->dbName  . ' -e "' . $query . '" > /dev/null 2>&1';
        $dbCommand     = $this->buildExecDockerCommand($dbContainer, $mysqlCommand);

        if ($isCheck) {
            $dbCommand .= ' || exit 1';
        }

        return $dbCommand;
    }

}
