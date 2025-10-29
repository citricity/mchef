<?php

namespace App\Database;

use App\Exceptions\ExecFailed;
use App\Helpers\OS;
use App\Service\Docker;
use App\Service\Main;

class Postgres extends AbstractDatabase implements DatabaseInterface {
    public function dropAllTables(): void {
        $mainService   = Main::instance($this->cli);
        $dbContainer   = $mainService->getDockerDatabaseContainerName();
        $dockerService = Docker::instance($this->cli);
        $recipe        = $this->recipe;
        $dbUser        = $recipe->dbUser;
        $dbName        = $this->getDbName(); // single source of truth
        $env           = '-e PGPASSWORD=' . escapeshellarg($recipe->dbPassword);

        // Helper to run one SQL command via psql with strict/error-stop flags.
        $psql = function (string $sql) use ($dockerService, $dbContainer, $env, $dbUser, $dbName): string {
            $cmd = sprintf(
                "psql -U %s -d %s -v ON_ERROR_STOP=1 -q -t -A -c %s",
                escapeshellarg($dbUser),
                escapeshellarg($dbName),
                escapeshellarg($sql)
            );
            return $dockerService->execute($dbContainer, $cmd, $env);
        };

        try {
            $psql("DROP SCHEMA public CASCADE; CREATE SCHEMA public;");
            return;
        } catch (ExecFailed) {
            $this->cli->warning('DO block failed; falling back to per-table drops…');
        }
        die;

        // Fallback: enumerate base tables and drop one-by-one (quoted, with CASCADE).
        try {
            $listSql = "SELECT tablename FROM pg_tables WHERE schemaname='public'";
            $tablesStr = $psql($listSql);
            $tables = array_filter(array_map('trim', explode("\n", $tablesStr)));

            foreach ($tables as $table) {
                // Quote identifiers safely using format('%I.%I', …) inside PG
                $dropSql = sprintf(
                    "DO $$ BEGIN EXECUTE 'DROP TABLE IF EXISTS ' || format('%%I.%%I','public',%s) || ' CASCADE'; END $$;",
                    // pass table name as a literal; PG will quote identifier via format('%I')
                    sprintf("'%s'", str_replace("'", "''", $table))
                );
                $this->cli->info(sprintf('Dropping table %s…', $table));
                $psql($dropSql);
            }
        } catch (ExecFailed $e) {
            throw new \RuntimeException('Failed to wipe database', 0, $e);
        }
    }

    public function dbeaverConnectionString(): string {
        if (empty($this->recipe->dbHostPort)) {
            throw new \Error('The recipe must have dbHostPort specified in order to generate a connection string');
        }
        $dbeavercmd = OS::isWindows() ? 'dbeaver.exe' : 'open -na "DBeaver" --args';
        $conString = sprintf(
            'driver=postgresql|host=localhost|port=%s|database=%s|user=%s|password=%s',
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
     * Return a pgAdmin connection string.
     * @return string
     */
    public function pgAdminConnectionString(): string {
        if (empty($this->recipe->dbHostPort)) {
            throw new \Error('The recipe must have dbHostPort specified in order to generate a connection string');
        }

        // pgAdmin connection via URL scheme
        $pgAdminCmd = OS::isWindows() ? 'pgAdmin4.exe' : 'open -a "pgAdmin 4"';
        $connectionUrl = sprintf(
            'postgresql://%s:%s@localhost:%s/%s',
            urlencode($this->recipe->dbUser),
            urlencode($this->recipe->dbPassword),
            $this->recipe->dbHostPort,
            urlencode($this->getDbName())
        );

        return $pgAdminCmd . ' --url=' . escapeshellarg($connectionUrl);
    }

    /**
     * Return a psql CLI connection command and environment (PGPASSWORD).
     * @return array (command / env)
     */
    public function psqlConnectionCommand(): array {
        if (empty($this->recipe->dbHostPort)) {
            throw new \Error('The recipe must have dbHostPort specified in order to generate a connection string');
        }

        return [sprintf(
            'psql -h localhost -p %s -U %s -d %s',
            intval($this->recipe->dbHostPort ?? 5432),
            escapeshellarg($this->recipe->dbUser),
            escapeshellarg($this->getDbName())
        ), ['PGPASSWORD' => $this->recipe->dbPassword]];
    }

    public function mysqlWorkbenchConnectionString(): string {
        throw new \InvalidArgumentException('MySQL Workbench can only be used with MySQL databases');
    }

    public function mysqlConnectionString(): string {
        throw new \InvalidArgumentException('mysql CLI can only be used with MySQL databases');
    }

    public function buildDBQueryDockerCommand(string $query, bool $isCheck = false): string {
        $mainService   = Main::instance($this->cli);
        $dbContainer   = $mainService->getDockerDatabaseContainerName();
        $recipe        = $this->recipe;
        $dbCommand     = 'docker exec ';
        if (OS::isWindows()) {
            // For Windows, `cmd` is used with `/c` to execute the command
            $dbCommand .= escapeshellarg($dbContainer) . ' cmd /c ';
        } else {
            // For Linux, use `sh` as the shell
            $dbCommand .= escapeshellarg($dbContainer) . ' sh -c ';
        }
        $dbCommand .= escapeshellarg('psql -U ' . $recipe->dbUser . ' -d ' . $recipe->dbName . ' -c "' . $query . '" > /dev/null 2>&1');

        if ($isCheck) {
            $dbCommand .= ' || exit 1';
        }

        return $dbCommand;
    }

}
