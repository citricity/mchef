<?php

namespace App\PDO;

use App\Service\Main;
use App\Traits\WithRecipe;

use PDO;

class Mysql extends PDO {
    use WithRecipe;
    public function __construct($options = null) {
        $recipe = $this->getParsedRecipe();
        if ($recipe->dbType !== 'mysql') {
            throw new \Exception('Database type is not mysql!');
        }

        $mainService = Main::instance();
        $dbContainer = $mainService->getDockerDatabaseContainerName();

        parent::__construct("mysql:host=$dbContainer;port=3306;dbname=$recipe->dbName",
            $recipe->dbUser,
            $recipe->dbPassword, $options);
    }

}
