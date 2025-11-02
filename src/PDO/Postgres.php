<?php

namespace App\PDO;

use App\Service\Main;
use App\StaticVars;

use PDO;

class Postgres extends PDO {
    public function __construct($options = null) {
        $recipe = StaticVars::$recipe;
        if ($recipe->dbType !== 'pgsql') {
            throw new \Exception('Database type is not pgsql!');
        }
        
        $host = 'localhost';
        $port = $recipe->dbHostPort ?? 5432;

        parent::__construct("pgsql:host=$host;port=$port;dbname=$recipe->dbName",
            $recipe->dbUser,
            $recipe->dbPassword, $options);
    }

}
