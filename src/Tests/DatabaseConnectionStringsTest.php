<?php

namespace App\Tests;

use App\Database\Mysql;
use App\Database\Postgres;
use App\Model\Recipe;
use App\StaticVars;

class DatabaseConnectionStringsTest extends MchefTestCase {

    public function testMysqlConnectionStringMethods(): void {
        $recipe = new Recipe(
            moodleTag: '4.0.1',
            phpVersion: '8.0',
            name: 'test-recipe',
            dbType: 'mysql',
            dbUser: 'testuser',
            dbPassword: 'testpass',
            host: 'localhost',
            dbHostPort: '3306'
        );

        $mysql = new Mysql($recipe, StaticVars::$cli);

        // Test DBeaver connection string
        $dbeaverCmd = $mysql->dbeaverConnectCommand();
        $this->assertStringContainsString('driver=mysql', $dbeaverCmd);
        $this->assertStringContainsString('port=3306', $dbeaverCmd);
        $this->assertStringContainsString('testuser', $dbeaverCmd);

        // Test MySQL Workbench connection string
        $workbenchCmd = $mysql->mysqlWorkbenchConnectionString();
        $this->assertStringContainsString('mysql://', $workbenchCmd);
        $this->assertStringContainsString('localhost:3306', $workbenchCmd);

        // Test MySQL CLI connection string
        $mysqlCmd = $mysql->mysqlConnectionString();
        $this->assertStringContainsString('mysql -h localhost', $mysqlCmd);
        $this->assertStringContainsString("-P '3306'", $mysqlCmd);
        $this->assertStringContainsString("-u 'testuser'", $mysqlCmd);

        // Test PostgreSQL-only methods throw exceptions
        $this->expectException(\InvalidArgumentException::class);
        $mysql->pgAdminConnectionString();
    }

    public function testMysqlPsqlMethodThrowsException(): void {
        $recipe = new Recipe(
            moodleTag: '4.0.1',
            phpVersion: '8.0',
            name: 'test-recipe',
            dbType: 'mysql',
            dbUser: 'testuser',
            dbPassword: 'testpass',
            host: 'localhost',
            dbHostPort: '3306'
        );

        $mysql = new Mysql($recipe, StaticVars::$cli);
        
        $this->expectException(\InvalidArgumentException::class);
        $mysql->psqlConnectionCommand();
    }

    public function testPostgresConnectionStringMethods(): void {
        $recipe = new Recipe(
            moodleTag: '4.0.1',
            phpVersion: '8.0',
            name: 'test-recipe',
            dbType: 'pgsql',
            dbUser: 'testuser',
            dbPassword: 'testpass',
            host: 'localhost',
            dbHostPort: '5432'
        );

        $postgres = new Postgres($recipe, StaticVars::$cli);

        // Test DBeaver connection string
        $dbeaverCmd = $postgres->dbeaverConnectCommand();
        $this->assertStringContainsString('driver=postgresql', $dbeaverCmd);
        $this->assertStringContainsString('port=5432', $dbeaverCmd);
        $this->assertStringContainsString('testuser', $dbeaverCmd);

        // Test pgAdmin connection string
        $pgAdminCmd = $postgres->pgAdminConnectionString();
        $this->assertStringContainsString('postgresql://', $pgAdminCmd);
        $this->assertStringContainsString('localhost:5432', $pgAdminCmd);

        // Test psql CLI connection string
        $psqlCmd = $postgres->psqlConnectionCommand();
        $this->assertIsArray($psqlCmd);
        $this->assertCount(2, $psqlCmd);
        $this->assertStringContainsString('psql -h localhost', $psqlCmd[0]);
        $this->assertStringContainsString("-p 5432", $psqlCmd[0]);
        $this->assertArrayHasKey('PGPASSWORD', $psqlCmd[1]);

        // Test MySQL-only methods throw exceptions
        $this->expectException(\InvalidArgumentException::class);
        $postgres->mysqlWorkbenchConnectionString();
    }

    public function testPostgresMysqlMethodThrowsException(): void {
        $recipe = new Recipe(
            moodleTag: '4.0.1',
            phpVersion: '8.0',
            name: 'test-recipe',
            dbType: 'pgsql',
            dbUser: 'testuser',
            dbPassword: 'testpass',
            host: 'localhost',
            dbHostPort: '5432'
        );

        $postgres = new Postgres($recipe, StaticVars::$cli);
        
        $this->expectException(\InvalidArgumentException::class);
        $postgres->mysqlConnectionString();
    }

    public function testMissingDbHostPortThrowsError(): void {
        $recipe = new Recipe(
            moodleTag: '4.0.1',
            phpVersion: '8.0',
            name: 'test-recipe',
            dbType: 'mysql',
            dbUser: 'testuser',
            dbPassword: 'testpass',
            host: 'localhost'
            // dbHostPort is missing
        );

        $mysql = new Mysql($recipe, StaticVars::$cli);
        
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('The recipe must have dbHostPort specified');
        $mysql->dbeaverConnectCommand();
    }
}