<?php

namespace App\Command;

use App\Database\DatabaseInterface;
use App\Database\Mysql;
use App\Database\Postgres;
use App\Model\Recipe;
use App\Model\RegistryInstance;
use App\StaticVars;
use App\Traits\ExecTrait;
use App\Traits\SingletonTrait;
use splitbrain\phpcli\Options;

class Database extends AbstractCommand {

    use SingletonTrait;
    use ExecTrait;

    // Other properties.
    private RegistryInstance $instance;
    private Recipe $recipe;
    private Mysql | Postgres $database;

    // Constants.
    const COMMAND_NAME = 'database';

    final public static function instance(): Database {
        return self::setup_singleton();
    }

    private function execDatabase() {
        $this->cli->notice('TODO: Exec onto database...');
    }

    private function wipeDatabase() {
        $this->cli->promptYesNo("ADVICE: TAKE A BACKUP FIRST!\n".
            "Are you sure you want to wipe your db?",
            function() {
                try {
                    $this->database->wipe();
                } catch (\Throwable $e) {
                    throw new \RuntimeException('Failed to wipe database', previous: $e);
                }
                $this->cli->success('All tables should be wiped from database');
            }
        );
    }

    private function openDatabaseClient(?string $client): void {
        $cmd = match ($client) {
            'dbeaver' => $this->database->dbeaverConnectionString(),
            'pgadmin' => $this->getPgAdminCommand(),
            'mysql workbench' => $this->getMysqlWorkbenchCommand(),
            'psql (cli)', 'psql' => $this->getPsqlCommand(),
            'mysql (cli)', 'mysql' => $this->getMysqlCommand(),
            default => throw new \InvalidArgumentException("Unsupported client: $client")
        };

        if (is_string($cmd)) {
            $this->exec($cmd);
        } else if (is_array($cmd)) {
            $this->execInteractive($cmd[0], $cmd[1]);
        }
    }

    private function getPgAdminCommand(): string {
        if ($this->recipe->dbType !== 'pgsql') {
            throw new \InvalidArgumentException('pgAdmin can only be used with PostgreSQL databases');
        }
        return $this->database->pgAdminConnectionString();
    }

    private function getMysqlWorkbenchCommand(): string {
        if ($this->recipe->dbType !== 'mysql') {
            throw new \InvalidArgumentException('MySQL Workbench can only be used with MySQL databases');
        }
        return $this->database->mysqlWorkbenchConnectionString();
    }

    private function getPsqlCommand(): array {
        if ($this->recipe->dbType !== 'pgsql') {
            throw new \InvalidArgumentException('psql can only be used with PostgreSQL databases');
        }
        return $this->database->psqlConnectionCommand();
    }

    private function getMysqlCommand(): string {
        if ($this->recipe->dbType !== 'mysql') {
            throw new \InvalidArgumentException('mysql CLI can only be used with MySQL databases');
        }
        return $this->database->mysqlConnectionString();
    }

    private function info() {
        $dbName = ($this->recipe->containerPrefix ?? 'mc').'-moodle';
        $dbContainer = ($this->recipe->containerPrefix ?? 'mc').'-db';
        $localPortInfo = $this->recipe->dbHostPort ? " Local port = {$this->recipe->dbHostPort} " : '';
        $this->cli->info("Container = $dbContainer{$localPortInfo}Database = $dbName User {$this->recipe->dbUser} Password {$this->recipe->dbPassword}");
    }

    private function resolveDatabase(): DatabaseInterface {
        return match ($this->recipe->dbType) {
            'pgsql' => new Postgres($this->recipe, $this->cli),
            'mysql' => new Mysql($this->recipe, $this->cli),
            'mariadb' => new Mysql($this->recipe, $this->cli),
            default => throw new \InvalidArgumentException(
                "Unsupported database type {$this->recipe->dbType}"
            ),
        };
    }

    public function execute(Options $options): void {
        $this->setStaticVarsFromOptions($options);

        $this->instance = StaticVars::$instance;
        $this->recipe = $this->mainService->getRecipe($this->instance->recipePath);
        $this->database = $this->resolveDatabase();

        if (!empty($options->getOpt('wipe'))) {
            $this->wipeDatabase();
            return;
        }
        if (!empty($options->getOpt('exec'))) {
            $this->execDatabase();
            return;
        }
        if (!empty($options->getOpt('info'))) {
            $this->info();
            return;
        }
        if (!empty($options->getOpt('client'))) {
            $clientValue = $this->getOptionalOptValueFromArgv('client', 'c');
            if (!$clientValue) {
                // Check for configured default client
                $defaultClient = $this->resolveDbClient();
                if ($defaultClient !== null) {
                    $this->openDatabaseClient($defaultClient);
                    return;
                }

                $this->cli->error('No database client configured. Use "mchef config --dbclient" to configure one.');
                $clientValue = $defaultClient;
            }
            $this->openDatabaseClient($clientValue);
            return;
        }
    }

    private function resolveDbClient(): ?string {
        $config = $this->configuratorService->getMainConfig();

        // First priority: dbClient global config
        if (!empty($config->dbClient)) {
            return $config->dbClient;
        }

        // Second priority: database-specific clients
        if ($this->recipe->dbType === 'mysql' && !empty($config->dbClientMysql)) {
            return $config->dbClientMysql;
        }

        if ($this->recipe->dbType === 'pgsql' && !empty($config->dbClientPgsql)) {
            return $config->dbClientPgsql;
        }

        // No client configured
        return null;
    }

   protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'Database utilities');
        $options->registerOption('exec', 'Exec onto database', 'e', false, self::COMMAND_NAME);
        $options->registerOption('wipe', 'Wipe database', 'w', false, self::COMMAND_NAME);
        $options->registerOption('info', 'Get db connection info', 'i', false, self::COMMAND_NAME);
        $options->registerOption('client', 'Specify database client (dbeaver, pgadmin, mysql workbench, psql (cli), mysql (cli))', 'c', false, self::COMMAND_NAME);
    }
}
