<?php

namespace App\Database;

use App\Exceptions\NotImplementedException;
use App\MChefCLI;
use App\Model\Recipe;
use App\Helpers\OS;
use App\Traits\ExecTrait;

abstract class AbstractDatabase implements DatabaseInterface {
    use ExecTrait;

    public function __construct(protected Recipe $recipe, protected MChefCLI $cli) {
    }

    public function getDbName(): string {
        return ($this->recipe->containerPrefix ?? 'mc').'-moodle';
    }

    public function dropAllTables(): void {
        throw new NotImplementedException(__METHOD__);
    }

    protected function dbeaverConnectBaseCommand(): string {
        throw new NotImplementedException(__METHOD__);
    }

    public function dbeaverConnectCommand(): string {
        if (empty($this->recipe->dbHostPort)) {
            throw new \Error('The recipe must have dbHostPort specified in order to generate a connection string');
        }

        $baseConnStr = $this->dbeaverConnectBaseCommand();

        // Execute command to create connection first.
        // NOTE - this didn't work with just one command, hence - to create you need open -na and to open you need open -a.
        if (OS::isWindows()) {
            $dbeaverBaseCmd = 'start dbeaverc.exe';
           
        } else if (OS::isMac()) {
            $dbeaverBaseCmd = 'open -na "DBeaver" --args';
        } else if (OS::isLinux()) {
            $dbeaverBaseCmd = 'dbeaverc';
        } else {
            throw new \RuntimeException('Unsupported OS for DBeaver connection');
        }

        $cmd = $dbeaverBaseCmd . ' -con ' . escapeshellarg($baseConnStr . '|connect=true|name=' . $this->getDbName());

        return $cmd;
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
