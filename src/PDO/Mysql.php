<?php

namespace App\PDO;

use App\Service\Main;
use App\StaticVars;

use PDO;

class Mysql extends PDO {

    public function __construct($options = null) {
        $recipe = StaticVars::$recipe;
        if ($recipe->dbType !== 'mysql') {
            throw new \Exception('Database type is not mysql!');
        }

        $host = 'localhost';
        $port = $recipe->dbHostPort ?? 3306;

        parent::__construct("mysql:host=$host;port=$port;dbname=$recipe->dbName",
            $recipe->dbUser,
            $recipe->dbPassword, $options);
    }

}
