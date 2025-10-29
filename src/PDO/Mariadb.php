<?php

namespace App\PDO;

use App\Service\Main;
use App\Traits\WithRecipe;

use PDO;

class Mariadb extends PDO {
    use WithRecipe;
    public function __construct($options = null) {
        $recipe = $this->getParsedRecipe();
        if ($recipe->dbType !== 'mariadb') {
            throw new \Exception('Database type is not mariadb!');
        }

        $mainService = Main::instance();
        $dbContainer = $mainService->getDockerDatabaseContainerName();

        parent::__construct("mariadb:host=$dbContainer;port=3306;dbname=$recipe->dbName",
            $recipe->dbUser,
            $recipe->dbPassword, $options);
    }

}
