<?php

namespace App\Tests;

use App\Command\Destroy;
use App\MChefCLI;
use App\Model\GlobalConfig;
use App\Model\Recipe;
use App\Model\RegistryInstance;
use App\Service\Configurator;
use App\Service\Docker;
use App\Service\File;
use App\Service\Main;
use App\StaticVars;
use PHPUnit\Framework\TestCase;
use splitbrain\phpcli\Options;

class DestroyCommandTest extends MchefTestCase {

    private Destroy $destroyCommand;
    private $options;
    private $configurator;
    private $docker;
    private $main;
    private $file;

    protected function setUp(): void {
        parent::setUp();
        
        // Reset singleton
        $this->destroyCommand = Destroy::instance(true);
        
        // Create options mock
        $this->options = $this->createMock(Options::class);
        
        // Create service mocks
        $this->configurator = $this->createMock(Configurator::class);
        $this->docker = $this->createMock(Docker::class);
        $this->main = $this->getMockBuilder(Main::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getChefPath', 'getRecipe'])
            ->getMock();
        $this->file = $this->createMock(File::class);
        
        // Set up dependency injection manually for testing
        $reflection = new \ReflectionClass($this->destroyCommand);
        
        $cliProperty = $reflection->getProperty('cli');
        $cliProperty->setValue($this->destroyCommand, $this->cli);
        
        $configuratorProperty = $reflection->getProperty('configuratorService');
        $configuratorProperty->setValue($this->destroyCommand, $this->configurator);
        
        $dockerProperty = $reflection->getProperty('dockerService');
        $dockerProperty->setValue($this->destroyCommand, $this->docker);
        
        $mainProperty = $reflection->getProperty('mainService');
        $mainProperty->setValue($this->destroyCommand, $this->main);
        
        $fileProperty = $reflection->getProperty('fileService');
        $fileProperty->setValue($this->destroyCommand, $this->file);
    }

    public function testExecuteRequiresYesConfirmation(): void {
        // Test that destruction is cancelled if user doesn't type "yes"
        $instance = new RegistryInstance('uuid1', '/path/to/recipe.json', 'test-instance', null);
        
        $this->options->method('getArgs')->willReturn(['test-instance']);
        
        $this->configurator->method('getRegisteredInstance')
            ->with('test-instance')
            ->willReturn($instance);
        
        // Mock getRecipe call that setStaticVarsFromOptions makes
        $mockRecipe = $this->createMock(Recipe::class);
        $this->main->method('getRecipe')
            ->with('/path/to/recipe.json')
            ->willReturn($mockRecipe);
        
        $this->docker->method('getInstanceVolumes')
            ->with('test-instance')
            ->willReturn(['test-volume']);
        
        $this->cli->method('promptInput')
            ->willReturn('no'); // User types "no" instead of "yes"
        
        // Expect the CLI to show what will be destroyed (now includes the "Using instance" message)
        $this->cli->expects($this->exactly(6)) // Updated count to include "Using instance" message
            ->method('info');
        
        $this->cli->expects($this->once())
            ->method('warning');
        
        // Should not call any destruction methods
        $this->docker->expects($this->never())->method('removeVolume');
        $this->configurator->expects($this->never())->method('deregisterInstance');
        $this->file->expects($this->never())->method('deleteDir');
        
        $this->destroyCommand->execute($this->options);
    }

    public function testExecuteSuccessfulDestroy(): void {
        // Test successful destruction flow
        $instance = new RegistryInstance('uuid1', '/path/to/recipe.json', 'test-instance', null);
        $globalConfig = new GlobalConfig();
        $globalConfig->instance = 'test-instance';
        
        $this->options->method('getArgs')->willReturn(['test-instance']);
        
        $this->configurator->method('getRegisteredInstance')
            ->with('test-instance')
            ->willReturn($instance);
        
        $this->configurator->method('getMainConfig')
            ->willReturn($globalConfig);
        
        // Mock getRecipe call that setStaticVarsFromOptions makes
        $mockRecipe = $this->createMock(Recipe::class);
        $mockRecipe->name = 'test-recipe';
        $this->main->method('getRecipe')
            ->with('/path/to/recipe.json')
            ->willReturn($mockRecipe);
        
        $this->docker->method('getInstanceVolumes')
            ->with('test-instance')
            ->willReturn(['test-volume-1', 'test-volume-2']);
        
        $this->cli->method('promptInput')
            ->willReturn('yes'); // User confirms with "yes"
        
        // Expect volume removal calls
        $this->docker->expects($this->exactly(2))
            ->method('removeVolume')
            ->withConsecutive(['test-volume-1'], ['test-volume-2'])
            ->willReturn(true);
        
        // Expect instance deregistration
        $this->configurator->expects($this->once())
            ->method('setMainConfigField')
            ->with('instance', null);
        
        $this->configurator->expects($this->once())
            ->method('deregisterInstance')
            ->with('test-instance')
            ->willReturn(true);
        
        $this->cli->expects($this->atLeastOnce())
            ->method('success');
        
        $this->destroyCommand->execute($this->options);
    }

    public function testIsValidInstanceName(): void {
        // Test the validation method directly (now inherited from AbstractCommand)
        $reflection = new \ReflectionClass($this->destroyCommand);
        $method = $reflection->getMethod('isValidInstanceName');
        
        // Valid names
        $this->assertTrue($method->invoke($this->destroyCommand, 'test'));
        $this->assertTrue($method->invoke($this->destroyCommand, 'test-instance'));
        $this->assertTrue($method->invoke($this->destroyCommand, 'test_instance'));
        $this->assertTrue($method->invoke($this->destroyCommand, 'test123'));
        $this->assertTrue($method->invoke($this->destroyCommand, 'Test-Instance_123'));
        
        // Invalid names
        $this->assertFalse($method->invoke($this->destroyCommand, '')); // Empty
        $this->assertFalse($method->invoke($this->destroyCommand, 'test@instance')); // Special chars
        $this->assertFalse($method->invoke($this->destroyCommand, 'test instance')); // Space
        $this->assertFalse($method->invoke($this->destroyCommand, 'test.instance')); // Dot
        $this->assertFalse($method->invoke($this->destroyCommand, str_repeat('a', 65))); // Too long
    }

    public function testDestroyWithNoVolumes(): void {
        // Test destruction when no volumes are found
        $instance = new RegistryInstance('uuid1', '/path/to/recipe.json', 'test-instance', null);
        $globalConfig = new GlobalConfig();
        
        $this->options->method('getArgs')->willReturn(['test-instance']);
        
        $this->configurator->method('getRegisteredInstance')
            ->willReturn($instance);
        
        $this->configurator->method('getMainConfig')
            ->willReturn($globalConfig);
        
        // Mock getRecipe call that setStaticVarsFromOptions makes
        $mockRecipe = $this->createMock(Recipe::class);
        $mockRecipe->name = 'test-recipe';
        $this->main->method('getRecipe')
            ->with('/path/to/recipe.json')
            ->willReturn($mockRecipe);
        
        $this->docker->method('getInstanceVolumes')
            ->willReturn([]); // No volumes
        
        $this->cli->method('promptInput')
            ->willReturn('yes');
        
        // Should not try to remove any volumes
        $this->docker->expects($this->never())->method('removeVolume');
        
        // Should still deregister the instance
        $this->configurator->expects($this->once())
            ->method('deregisterInstance')
            ->willReturn(true);
        
        $this->destroyCommand->execute($this->options);
    }

    public function testDestroyWithInvalidMchefPath(): void {
        // Test destruction when mchef path is invalid
        $instance = new RegistryInstance('uuid1', '/path/to/recipe.json', 'test-instance', null);
        $globalConfig = new GlobalConfig();
        
        $this->options->method('getArgs')->willReturn(['test-instance']);
        
        $this->configurator->method('getRegisteredInstance')
            ->willReturn($instance);
        
        $this->configurator->method('getMainConfig')
            ->willReturn($globalConfig);
        
        // Mock getRecipe call that setStaticVarsFromOptions makes
        $mockRecipe = $this->createMock(Recipe::class);
        $mockRecipe->name = 'test-recipe';
        $this->main->method('getRecipe')
            ->with('/path/to/recipe.json')
            ->willReturn($mockRecipe);
        
        $this->docker->method('getInstanceVolumes')
            ->willReturn([]);
        
        $this->main->method('getChefPath')
            ->willReturn('/invalid/path');
        
        $this->cli->method('promptInput')
            ->willReturn('yes');
        
        // Should show warning for invalid path
        $this->cli->expects($this->atLeastOnce())
            ->method('warning');
        
        // Should not try to delete directory
        $this->file->expects($this->never())->method('deleteDir');
        
        // Should still deregister the instance
        $this->configurator->expects($this->once())
            ->method('deregisterInstance')
            ->willReturn(true);
        
        $this->destroyCommand->execute($this->options);
    }

    public function testDryRunShowsWhatWouldBeDestroyedWithoutActuallyDestroying(): void {
        // Test that dry-run shows destruction plan without performing any actions
        $instance = new RegistryInstance('uuid1', '/path/to/recipe.json', 'test-instance', null);
        $globalConfig = new GlobalConfig();
        
        $this->options->method('getArgs')->willReturn(['test-instance']);
        $this->options->method('getOpt')
            ->with('dry-run')
            ->willReturn(true); // Enable dry-run mode
        
        $this->configurator->method('getRegisteredInstance')
            ->willReturn($instance);
        
        $this->configurator->method('getMainConfig')
            ->willReturn($globalConfig);
        
        // Mock getRecipe call that setStaticVarsFromOptions makes
        $mockRecipe = $this->createMock(Recipe::class);
        $mockRecipe->name = 'test-recipe';
        $this->main->method('getRecipe')
            ->with('/path/to/recipe.json')
            ->willReturn($mockRecipe);
        
        $this->docker->method('getInstanceVolumes')
            ->willReturn(['test-volume-1', 'test-volume-2']);
        
        $this->main->method('getChefPath')
            ->willReturn('/mock/chef/path/.mchef');
        
        // Expect dry-run specific messages
        $this->cli->expects($this->atLeastOnce())
            ->method('info')
            ->withConsecutive(
                ["-- Using instance \"test-instance\" --"],
                ["DRY RUN: The following would be destroyed for instance 'test-instance':"],
                ["  - Container: test-instance-moodle"],
                ["  - Container: test-instance-db"],
                ["  - Volume: test-volume-1"],
                ["  - Volume: test-volume-2"],
                ["  - Instance registration"],
                ["  - Directory: /mock/chef/path/.mchef"]
            );
        
        $this->cli->expects($this->once())
            ->method('success')
            ->with("DRY RUN: No actual changes were made.");
        
        // Should NOT prompt for user input in dry-run mode
        $this->cli->expects($this->never())->method('promptInput');
        
        // Should NOT perform any destructive actions
        $this->docker->expects($this->never())->method('removeVolume');
        $this->configurator->expects($this->never())->method('deregisterInstance');
        $this->configurator->expects($this->never())->method('setMainConfigField');
        $this->file->expects($this->never())->method('deleteDir');
        
        $this->destroyCommand->execute($this->options);
    }
}
