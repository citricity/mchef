<?php

namespace App\Database;

use App\MChefCLI;
use App\Model\Recipe;
use App\Helpers\OS;

abstract class AbstractDatabase implements DatabaseInterface {
    public function __construct(protected Recipe $recipe, protected MChefCLI $cli) {
    }

    public function getDbName(): string {
        return ($this->recipe->containerPrefix ?? 'mc').'-moodle';
    }

    public function dropAllTables(): void {
        throw new NotImplementedException(__METHOD__);
    }

    public function dbeaverConnectionString(): string {
        throw new NotImplementedException(__METHOD__);
    }

    public function buildDBQueryDockerCommand(string $query, bool $isCheck = false): string {
        throw new NotImplementedException(__METHOD__);
    }

    /**
     * Builds a properly escaped command to run inside a container.
     * @param string $command Inner command to run
     * @return string
     */
    public function buildEscapedDockerCommand(string $command): string {
        if (OS::isWindows()) {
            return ' cmd /c ' . escapeshellarg($command);
        }
        return ' sh -c ' . escapeshellarg($command);
    }

    /**
     * Builds a docker exec command to run a command inside a container.
     * @param string $containerName Name of the container
     * @param string $command Inner command to run
     * @return string
     */
    public function buildExecDockerCommand(string $containerName, string $command): string {
        return 'docker exec ' . escapeshellarg($containerName) . ' ' . $this->buildEscapedDockerCommand($command);
    }
}
