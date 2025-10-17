<?php

namespace App\Tests;

use App\Command\CI;
use App\Exceptions\CliRuntimeException;
use App\Helpers\SplitbrainWrapper;
use App\Model\Recipe;
use App\Service\Main;
use App\Service\Docker;
use App\Service\Environment;
use App\Tests\MchefTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use splitbrain\phpcli\Options;

class CICommandTest extends MchefTestCase {

    private CI $ciCommand;
    /** @var Options&MockObject */
    private $options;
    private MockObject $main;
    private MockObject $docker;
    private MockObject $environment;
  
    protected function setUp(): void {
        parent::setUp(); // This initializes StaticVars::$cli
        
        $this->ciCommand = CI::instance();
        
        // Mock dependencies
        $this->options = SplitbrainWrapper::suppressDeprecationWarnings(function() {
            return $this->createMock(Options::class);
        });
        $this->main = $this->createMock(Main::class);
        $this->docker = $this->createMock(Docker::class);
        $this->environment = $this->createMock(Environment::class);
        
        // Inject mocked dependencies
        $reflection = new \ReflectionClass($this->ciCommand);
        
        $mainServiceProperty = $reflection->getProperty('mainService');
        $mainServiceProperty->setAccessible(true);
        $mainServiceProperty->setValue($this->ciCommand, $this->main);
        
        $dockerServiceProperty = $reflection->getProperty('dockerService');
        $dockerServiceProperty->setAccessible(true);
        $dockerServiceProperty->setValue($this->ciCommand, $this->docker);
        
        $environmentServiceProperty = $reflection->getProperty('environmentService');
        $environmentServiceProperty->setAccessible(true);
        $environmentServiceProperty->setValue($this->ciCommand, $this->environment);
        
        $cliProperty = $reflection->getParentClass()->getProperty('cli');
        $cliProperty->setAccessible(true);
        $cliProperty->setValue($this->ciCommand, $this->cli);
    }

    public function testExecuteFailsWithoutRecipeArgument(): void {
        $this->expectException(CliRuntimeException::class);
        $this->expectExceptionMessage('Recipe file path is required');
        
        $this->options->method('getArgs')->willReturn([]);
        
        $this->ciCommand->execute($this->options);
    }

    public function testExecuteFailsWithNonExistentRecipe(): void {
        $this->expectException(CliRuntimeException::class);
        $this->expectExceptionMessage('Recipe file does not exist: nonexistent.json');
        
        $this->options->method('getArgs')->willReturn(['nonexistent.json']);
        
        $this->ciCommand->execute($this->options);
    }

    public function testExecuteFailsWithoutPublishTag(): void {
        $this->expectException(CliRuntimeException::class);
        $this->expectExceptionMessage('Publish tag is required');
        
        $fixtureFile = __DIR__ . '/Fixtures/test-mrecipe.json';
        
        $this->options->method('getArgs')->willReturn([$fixtureFile]);
        $this->options->method('getOpt')->with('publish')->willReturn(null);
        
        $this->ciCommand->execute($this->options);
    }

    public function testExecuteSuccessfulBuildWithoutPublish(): void {
        $fixtureFile = __DIR__ . '/Fixtures/test-mrecipe.json';
        
        $this->options->method('getArgs')->willReturn([$fixtureFile]);
        $this->options->method('getOpt')->with('publish')->willReturn('v1.5.0');
        
        // Mock recipe loading and preparation
        $mockRecipe = $this->createMock(Recipe::class);
        $mockRecipe->name = 'example';
        $mockRecipe->publishTagPrefix = null;
        
        $this->main->expects($this->once())
            ->method('getRecipe')
            ->with($fixtureFile)
            ->willReturn($mockRecipe);
        
        $this->main->expects($this->once())
            ->method('buildDockerImage')
            ->with($mockRecipe, 'example:v1.5.0');
        
        // Mock environment variables (none set) 
        $this->environment->method('get')->willReturnMap([
            ['MCHEF_REGISTRY_URL', null, null],
            ['MCHEF_REGISTRY_USERNAME', null, null],
            ['MCHEF_REGISTRY_PASSWORD', null, null],
            ['MCHEF_REGISTRY_TOKEN', null, null]
        ]);
        
        // Expect warning about missing registry config
        $this->cli->expects($this->once())
            ->method('warning')
            ->with('Registry environment variables not configured - skipping publish');
        
        // Expect info messages
        $this->cli->expects($this->atLeastOnce())->method('info');
        $this->cli->expects($this->once())->method('success');
        
        $this->ciCommand->execute($this->options);
    }

    public function testExecuteSuccessfulBuildAndPublish(): void {
        $fixtureFile = __DIR__ . '/Fixtures/test-mrecipe.json';
        
        $this->options->method('getArgs')->willReturn([$fixtureFile]);
        $this->options->method('getOpt')->with('publish')->willReturn('v1.5.0');
        
        // Mock recipe loading and preparation
        $mockRecipe = $this->createMock(Recipe::class);
        $mockRecipe->name = 'example';
        $mockRecipe->publishTagPrefix = 'my-custom-app';
        
        $this->main->expects($this->once())
            ->method('getRecipe')
            ->with($fixtureFile)
            ->willReturn($mockRecipe);
        
        $this->main->expects($this->once())
            ->method('buildDockerImage')
            ->with($mockRecipe, 'my-custom-app:v1.5.0');
        
        // Mock environment variables (all set)
        $this->environment->method('get')->willReturnMap([
            ['MCHEF_REGISTRY_URL', null, 'https://registry.example.com'],
            ['MCHEF_REGISTRY_USERNAME', null, 'testuser'],
            ['MCHEF_REGISTRY_PASSWORD', null, 'testpass'],
            ['MCHEF_REGISTRY_TOKEN', null, null]
        ]);
        
        // Mock Docker registry operations
        $this->docker->expects($this->once())
            ->method('loginToRegistry')
            ->with([
                'url' => 'https://registry.example.com',
                'username' => 'testuser',
                'password' => 'testpass',
                'token' => null
            ]);
        
        $this->docker->expects($this->once())
            ->method('tagImage')
            ->with('my-custom-app:v1.5.0', 'https://registry.example.com/my-custom-app:v1.5.0');
        
        $this->docker->expects($this->once())
            ->method('pushImage')
            ->with('https://registry.example.com/my-custom-app:v1.5.0');
        
        // Expect success messages
        $this->cli->expects($this->atLeastOnce())->method('info');
        $this->cli->expects($this->atLeastOnce())->method('success');
        
        $this->ciCommand->execute($this->options);
    }

    public function testGetImageBaseNameUsesPublishTagPrefix(): void {
        $reflection = new \ReflectionClass($this->ciCommand);
        $method = $reflection->getMethod('getImageBaseName');
        $method->setAccessible(true);
        
        $mockRecipe = $this->createMock(Recipe::class);
        $mockRecipe->publishTagPrefix = 'my-custom-prefix';
        $mockRecipe->name = 'recipe-name';
        
        $result = $method->invoke($this->ciCommand, $mockRecipe);
        $this->assertEquals('my-custom-prefix', $result);
    }

    public function testGetImageBaseNameFallsBackToRecipeName(): void {
        $reflection = new \ReflectionClass($this->ciCommand);
        $method = $reflection->getMethod('getImageBaseName');
        $method->setAccessible(true);
        
        $mockRecipe = $this->createMock(Recipe::class);
        $mockRecipe->publishTagPrefix = null;
        $mockRecipe->name = 'My Recipe Name!';
        
        $result = $method->invoke($this->ciCommand, $mockRecipe);
        $this->assertEquals('my-recipe-name', $result);
    }

    public function testSanitizeImageName(): void {
        $reflection = new \ReflectionClass($this->ciCommand);
        $method = $reflection->getMethod('sanitizeImageName');
        $method->setAccessible(true);
        
        // Test various sanitization scenarios
        $this->assertEquals('my-app', $method->invoke($this->ciCommand, 'My App!'));
        $this->assertEquals('test-123-app', $method->invoke($this->ciCommand, 'Test@123#App'));
        $this->assertEquals('hello-world', $method->invoke($this->ciCommand, 'Hello World'));
        $this->assertEquals('moodle-app', $method->invoke($this->ciCommand, '--moodle-app--'));
    }

    public function testRecipeProductionOverrides(): void {
        $fixtureFile = __DIR__ . '/Fixtures/test-mrecipe.json';
        
        $reflection = new \ReflectionClass($this->ciCommand);
        $method = $reflection->getMethod('loadAndPrepareRecipe');
        $method->setAccessible(true);
        
        $mockRecipe = $this->createMock(Recipe::class);
        
        $this->main->expects($this->once())
            ->method('getRecipe')
            ->with($fixtureFile)
            ->willReturn($mockRecipe);
        
        $this->cli->expects($this->atLeastOnce())->method('info');
        
        $result = $method->invoke($this->ciCommand, $fixtureFile);
        
        // Verify all production overrides are applied
        $this->assertFalse($result->cloneRepoPlugins);
        $this->assertFalse($result->mountPlugins);
        $this->assertFalse($result->developer);
        $this->assertFalse($result->includePhpUnit);
        $this->assertFalse($result->includeBehat);
        $this->assertFalse($result->includeXdebug);
    }

    public function testGetRegistryConfigWithTokenAuth(): void {
        $this->environment->method('get')->willReturnMap([
            ['MCHEF_REGISTRY_URL', null, 'https://ghcr.io'],
            ['MCHEF_REGISTRY_USERNAME', null, 'github-user'],
            ['MCHEF_REGISTRY_PASSWORD', null, null],
            ['MCHEF_REGISTRY_TOKEN', null, 'ghp_token123']
        ]);
        
        $reflection = new \ReflectionClass($this->ciCommand);
        $method = $reflection->getMethod('getRegistryConfig');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->ciCommand);
        
        $this->assertEquals([
            'url' => 'https://ghcr.io',
            'username' => 'github-user',
            'password' => null,
            'token' => 'ghp_token123'
        ], $result);
    }

    public function testGetRegistryConfigWithIncompleteEnvironment(): void {
        $this->environment->method('get')->willReturnMap([
            ['MCHEF_REGISTRY_URL', null, 'https://registry.com'],
            ['MCHEF_REGISTRY_USERNAME', null, 'user'],
            ['MCHEF_REGISTRY_PASSWORD', null, null],
            ['MCHEF_REGISTRY_TOKEN', null, null] // Missing both password and token
        ]);
        
        $reflection = new \ReflectionClass($this->ciCommand);
        $method = $reflection->getMethod('getRegistryConfig');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->ciCommand);
        $this->assertNull($result);
    }

    public function testGetRegistryConfigWithPasswordAuth(): void {
        $this->environment->method('get')->willReturnMap([
            ['MCHEF_REGISTRY_URL', null, 'https://docker.io'],
            ['MCHEF_REGISTRY_USERNAME', null, 'dockerhub-user'],
            ['MCHEF_REGISTRY_PASSWORD', null, 'password123'],
            ['MCHEF_REGISTRY_TOKEN', null, null]
        ]);
        
        $reflection = new \ReflectionClass($this->ciCommand);
        $method = $reflection->getMethod('getRegistryConfig');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->ciCommand);
        
        $this->assertEquals([
            'url' => 'https://docker.io',
            'username' => 'dockerhub-user',
            'password' => 'password123',
            'token' => null
        ], $result);
    }

    public function testGetRegistryConfigMissingUrl(): void {
        $this->environment->method('get')->willReturnMap([
            ['MCHEF_REGISTRY_URL', null, null], // Missing URL
            ['MCHEF_REGISTRY_USERNAME', null, 'user'],
            ['MCHEF_REGISTRY_PASSWORD', null, 'password'],
            ['MCHEF_REGISTRY_TOKEN', null, null]
        ]);
        
        $reflection = new \ReflectionClass($this->ciCommand);
        $method = $reflection->getMethod('getRegistryConfig');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->ciCommand);
        $this->assertNull($result);
    }
}
