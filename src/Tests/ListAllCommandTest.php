<?php

namespace App\Tests;

use App\Command\ListAll;
use App\Helpers\Testing;
use App\Service\Configurator;
use App\Service\Main;
use App\Service\Docker;
use App\Service\RecipeService;
use App\Model\RegistryInstance;
use App\Model\GlobalConfig;
use App\MChefCLI;
use splitbrain\phpcli\Options;

class ListAllCommandTest extends MchefTestCase {
    public function testListAllShowsSelectedInstance() {
        $cli = $this->cli;
        $cli->method('warning')->willReturnCallback(function($msg) { echo $msg . "\n"; });
        $cli->method('info')->willReturnCallback(function($msg) { echo $msg . "\n"; });

        $configurator = $this->createMock(Configurator::class);
        $main = $this->createMock(Main::class);
        $docker = $this->createMock(Docker::class);
        $options = $this->createMock(Options::class);

        $instance1 = new RegistryInstance('uuid1', '/path/to/recipe1.json', 'prefix1', null);
        $instance2 = new RegistryInstance('uuid2', '/path/to/recipe2.json', 'prefix2', null);
        $instances = [$instance1, $instance2];

        $configurator->method('getInstanceRegistry')->willReturn($instances);
        $globalConfig = new GlobalConfig();
        $globalConfig->instance = 'prefix2';
        $configurator->method('getMainConfig')->willReturn($globalConfig);
        $main->method('getDockerMoodleContainerName')->willReturn('prefix1-moodle', 'prefix2-moodle');
        $docker->method('checkContainerRunning')->willReturn(true, false);

        // Capture output
        ob_start();
        $listAll = ListAll::instance();
        $recipeService = $this->createMock(\App\Service\RecipeService::class);
        // Provide required constructor arguments for Recipe
        $recipeService->method('parse')->willReturn(new \App\Model\Recipe('4.1.0', '8.0', 'dummy', '1.0'));
        Testing::setRestrictedProperty($listAll, 'recipeService', $recipeService);

        $listAll = ListAll::instance();
        $this->applyMockedServices([
            'configuratorService' => $configurator,
            'mainService' => $main,
            'dockerService' => $docker,
            'recipeService' => $recipeService
        ], $listAll);

        // Simulate execution
        $listAll->execute($options);
        $output = ob_get_clean();

        $this->assertStringContainsString("*SELECTED*", $output);
        $this->assertStringContainsString("prefix2", $output);
    }

    public function testListAllHandlesMissingRecipe() {
        $cli = $this->cli;
        $cli->method('warning')->willReturnCallback(function($msg) { echo $msg . "\n"; });
        $configurator = $this->createMock(Configurator::class);
        $main = $this->createMock(Main::class);
        $docker = $this->createMock(Docker::class);
        $options = $this->createMock(Options::class);

        $instance = new RegistryInstance('uuid1', '/missing/recipe.json', 'prefix1', null);
        $configurator->method('getInstanceRegistry')->willReturn([$instance]);
        $globalConfig = new GlobalConfig();
        $globalConfig->instance = 'prefix1';
        $configurator->method('getMainConfig')->willReturn($globalConfig);
        $main->method('getDockerMoodleContainerName')->willReturn('prefix1-moodle');
        $docker->method('checkContainerRunning')->willReturn(false);

        ob_start();
        $listAll = ListAll::instance();
        Testing::setRestrictedProperty($listAll, 'cli', $cli);
        $recipeService = $this->createMock(\App\Service\RecipeService::class);
        $recipeService->method('parse')->willReturn(new \App\Model\Recipe('4.1.0', '8.0', 'dummy', '1.0'));

        $listAll = ListAll::instance();
        $this->applyMockedServices([
            'configuratorService' => $configurator,
            'mainService' => $main,
            'dockerService' => $docker,
            'recipeService' => $recipeService
        ], $listAll);

        $listAll->execute($options);
        $output = ob_get_clean();
        $this->assertStringContainsString('⚠️ Recipe missing', $output);
    }

    public function testListAllWithNoInstances() {
        $cli = $this->cli;
        $cli->method('info')->willReturnCallback(function($msg) { echo $msg . "\n"; });
        $configurator = $this->createMock(Configurator::class);
        $main = $this->createMock(Main::class);
        $docker = $this->createMock(Docker::class);
        $options = $this->createMock(Options::class);
        $configurator->method('getInstanceRegistry')->willReturn([]);
        $globalConfig = new GlobalConfig();
        $globalConfig->instance = null;
        $configurator->method('getMainConfig')->willReturn($globalConfig);
        $recipeService = $this->createMock(RecipeService::class);
        $listAll = ListAll::instance(true);
        $this->applyMockedServices([
            'configuratorService' => $configurator,
            'mainService' => $main,
            'dockerService' => $docker,
            'recipeService' => $recipeService
        ], $listAll);
        ob_start();
        $listAll->execute($options);
        $output = ob_get_clean();
        $this->assertStringContainsString('No mchef instances have been registered.', $output);
    }

    public function testListAllWithAllInactiveInstances() {
        $cli = $this->cli;
        $cli->method('info')->willReturnCallback(function($msg) { echo $msg . "\n"; });
        $configurator = $this->createMock(Configurator::class);
        $main = $this->createMock(Main::class);
        $docker = $this->createMock(Docker::class);
        $options = $this->createMock(Options::class);
        $instance1 = new RegistryInstance('uuid1', '/path/to/recipe1.json', 'prefix1', null);
        $instance2 = new RegistryInstance('uuid2', '/path/to/recipe2.json', 'prefix2', null);
        $instances = [$instance1, $instance2];
        $configurator->method('getInstanceRegistry')->willReturn($instances);
        $globalConfig = new GlobalConfig();
        $globalConfig->instance = null;
        $configurator->method('getMainConfig')->willReturn($globalConfig);
        $main->method('getDockerMoodleContainerName')->willReturn('prefix1-moodle', 'prefix2-moodle');
        $docker->method('checkContainerRunning')->willReturn(false, false);
        $recipeService = $this->createMock(RecipeService::class);
        $recipeService->method('parse')->willReturn(new \App\Model\Recipe('4.1.0', '8.0', 'dummy', '1.0'));

        $listAll = ListAll::instance();
        $this->applyMockedServices([
            'configuratorService' => $configurator,
            'mainService' => $main,
            'dockerService' => $docker,
            'recipeService' => $recipeService
        ], $listAll);

        ob_start();
        $listAll->execute($options);
        $output = ob_get_clean();
        $this->assertStringContainsString('⏸️', $output);
        $this->assertStringNotContainsString('*SELECTED*', $output);
    }

    public function testListAllWithSelectedInactiveInstance() {
        $cli = $this->cli;
        $cli->method('info')->willReturnCallback(function($msg) { echo $msg . "\n"; });
        $configurator = $this->createMock(Configurator::class);
        $main = $this->createMock(Main::class);
        $docker = $this->createMock(Docker::class);
        $options = $this->createMock(Options::class);
        $instance1 = new RegistryInstance('uuid1', '/path/to/recipe1.json', 'prefix1', null);
        $configurator->method('getInstanceRegistry')->willReturn([$instance1]);
        $globalConfig = new GlobalConfig();
        $globalConfig->instance = 'prefix1';
        $configurator->method('getMainConfig')->willReturn($globalConfig);
        $main->method('getDockerMoodleContainerName')->willReturn('prefix1-moodle');
        $docker->method('checkContainerRunning')->willReturn(false);
        $recipeService = $this->createMock(RecipeService::class);
        $recipeService->method('parse')->willReturn(new \App\Model\Recipe('4.1.0', '8.0', 'dummy', '1.0'));
        $listAll = ListAll::instance();
        $this->applyMockedServices([
            'configuratorService' => $configurator,
            'mainService' => $main,
            'dockerService' => $docker,
            'recipeService' => $recipeService
        ], $listAll);
        ob_start();
        $listAll->execute($options);
        $output = ob_get_clean();
        $this->assertStringContainsString('*SELECTED*', $output);
        $this->assertStringContainsString('⏸️', $output);
    }
}
