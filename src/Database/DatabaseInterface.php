<?php

namespace App\Database;

interface DatabaseInterface {

    /**
     * Remove all tables from a database.
     * @return void
     */
    public function dropAllTables(): void;

    /**
     * Return a dbeaver connection string.
     * @return string
     */
    public function dbeaverConnectionString(): string;

    /**
     * Build a docker command (docker exec ...) to run a database query.
     *
     * @param string $query The SQL query to execute.
     * @param bool $isCheck Whether this is a check query (e.g., for existence).
     * @return string The constructed docker command.
     */
    public function buildDBQueryDockerCommand(string $query, bool $isCheck = false): string;
}
