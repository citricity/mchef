<?php

namespace App\Database;

use App\MChefCLI;
use App\Model\Recipe;

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
}
