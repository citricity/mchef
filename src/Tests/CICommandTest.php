<?php

namespace App\Tests;

use App\Command\CI;
use App\Exceptions\CliRuntimeException;
use App\Helpers\SplitbrainWrapper;
use App\Model\Recipe;
use App\Service\Docker;
use App\Service\Environment;
use App\Service\Main;
use App\Service\RecipeService;
use App\Tests\MchefTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use splitbrain\phpcli\Options;

class CICommandTest extends MchefTestCase {

    private CI $ciCommand;
    /** @var Options&MockObject */
    private $options;
    private MockObject $main;
    private MockObject $docker;
    private MockObject $environment;
    private MockObject $recipeService;

    protected function setUp(): void {
        parent::setUp();

        $this->ciCommand = CI::instance();

        $this->options = SplitbrainWrapper::suppressDeprecationWarnings(function() {
            return $this->createMock(Options::class);
        });
        $this->main = $this->createMock(Main::class);
        $this->docker = $this->createMock(Docker::class);
        $this->environment = $this->createMock(Environment::class);
        $this->recipeService = $this->createMock(RecipeService::class);

        $reflection = new ReflectionClass($this->ciCommand);

        $mainServiceProperty = $reflection->getProperty('mainService');
        $mainServiceProperty->setValue($this->ciCommand, $this->main);

        $dockerServiceProperty = $reflection->getProperty('dockerService');
        $dockerServiceProperty->setValue($this->ciCommand, $this->docker);

        $environmentServiceProperty = $reflection->getProperty('environmentService');
        $environmentServiceProperty->setValue($this->ciCommand, $this->environment);

        $recipeServiceProperty = $reflection->getProperty('recipeService');
        $recipeServiceProperty->setValue($this->ciCommand, $this->recipeService);

        $cliProperty = $reflection->getParentClass()->getProperty('cli');
        $cliProperty->setValue($this->ciCommand, $this->cli);

        $this->cli->method('resolveRecipePath')->willReturnCallback(function(string $arg): string {
            return $arg;
        });
    }

    public function testExecuteFailsWithoutRecipeArgument(): void {
        $this->expectException(CliRuntimeException::class);
        $this->expectExceptionMessage('Recipe file path(s) required');

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
        $this->expectExceptionMessage('Publish tag or tag only is required');

        $fixtureFile = __DIR__ . '/Fixtures/test-mrecipe.json';
        $this->options->method('getArgs')->willReturn([$fixtureFile]);
        $this->options->method('getOpt')->willReturnCallback(function($option, $default = false) {
            if ($option === 'tag' || $option === 'publish') {
                return null;
            }
            return $default;
        });

        $mockRecipe = $this->createMock(Recipe::class);
        $this->recipeService->expects($this->once())
            ->method('parse')
            ->willReturn($mockRecipe);

        $this->ciCommand->execute($this->options);
    }

    public function testExecuteFailsWhenBothTagAndPublishProvided(): void {
        $this->expectException(CliRuntimeException::class);
        $this->expectExceptionMessage('Cannot use both --tag and --publish options together');

        $fixtureFile = __DIR__ . '/Fixtures/test-mrecipe.json';
        $this->options->method('getArgs')->willReturn([$fixtureFile]);
        $this->options->method('getOpt')->willReturnCallback(function($option, $default = false) {
            if ($option === 'tag' || $option === 'publish') {
                return 'v1.5.0';
            }
            return $default;
        });

        $mockRecipe = $this->createMock(Recipe::class);
        $this->recipeService->expects($this->once())
            ->method('parse')
            ->willReturn($mockRecipe);

        $this->ciCommand->execute($this->options);
    }

    public function testExecuteSuccessfulBuildWithoutPublish(): void {
        $fixtureFile = __DIR__ . '/Fixtures/test-mrecipe.json';
        $this->options->method('getArgs')->willReturn([$fixtureFile]);
        $this->options->method('getOpt')->willReturnCallback(function($option, $default = false) {
            if ($option === 'tag') {
                return null;
            }
            if ($option === 'publish') {
                return 'v1.5.0';
            }
            return $default;
        });

        $mockRecipe = $this->createMock(Recipe::class);
        $mockRecipe->name = 'example';
        $mockRecipe->publishTagPrefix = null;

        $this->recipeService->expects($this->once())
            ->method('parse')
            ->willReturn($mockRecipe);

        $this->main->expects($this->once())
            ->method('buildDockerCiImage')
            ->with($mockRecipe, 'example:v1.5.0');

        $this->environment->method('getRegistryConfig')->willReturn(null);

        $this->cli->expects($this->once())
            ->method('warning')
            ->with('Registry environment variables not configured - skipping publish');

        $this->cli->expects($this->atLeastOnce())->method('info');
        $this->cli->expects($this->once())->method('success');

        $this->ciCommand->execute($this->options);
    }

    public function testExecuteSuccessfulBuildAndPublish(): void {
        $fixtureFile = __DIR__ . '/Fixtures/test-mrecipe.json';
        $this->options->method('getArgs')->willReturn([$fixtureFile]);
        $this->options->method('getOpt')->willReturnCallback(function($option, $default = false) {
            if ($option === 'tag') {
                return null;
            }
            if ($option === 'publish') {
                return 'v1.5.0';
            }
            return $default;
        });

        $mockRecipe = $this->createMock(Recipe::class);
        $mockRecipe->name = 'example';
        $mockRecipe->publishTagPrefix = 'my-custom-app';

        $this->recipeService->expects($this->once())
            ->method('parse')
            ->willReturn($mockRecipe);

        $this->main->expects($this->once())
            ->method('buildDockerCiImage')
            ->with($mockRecipe, 'my-custom-app:v1.5.0');

        $this->environment->method('getRegistryConfig')->willReturn([
            'url' => 'https://registry.example.com',
            'username' => 'testuser',
            'password' => 'testpass',
            'token' => null,
        ]);

        $this->docker->expects($this->once())
            ->method('loginToRegistry')
            ->with([
                'url' => 'https://registry.example.com',
                'username' => 'testuser',
                'password' => 'testpass',
                'token' => null,
            ]);

        $this->docker->expects($this->once())
            ->method('tagImage')
            ->with('my-custom-app:v1.5.0', 'https://registry.example.com/testuser/my-custom-app:v1.5.0');

        $this->docker->expects($this->once())
            ->method('pushImage')
            ->with('https://registry.example.com/testuser/my-custom-app:v1.5.0');

        $this->cli->expects($this->atLeastOnce())->method('info');
        $this->cli->expects($this->atLeastOnce())->method('success');

        $this->ciCommand->execute($this->options);
    }

    public function testExecuteWithTagBuildsButSkipsPublishFlow(): void {
        $fixtureFile = __DIR__ . '/Fixtures/test-mrecipe.json';
        $this->options->method('getArgs')->willReturn([$fixtureFile]);
        $this->options->method('getOpt')->willReturnCallback(function($option, $default = false) {
            if ($option === 'tag') {
                return 'v1.5.0';
            }
            if ($option === 'publish') {
                return null;
            }
            return $default;
        });

        $mockRecipe = $this->createMock(Recipe::class);
        $mockRecipe->name = 'example';
        $mockRecipe->publishTagPrefix = null;

        $this->recipeService->expects($this->once())
            ->method('parse')
            ->willReturn($mockRecipe);

        $this->main->expects($this->once())
            ->method('buildDockerCiImage')
            ->with($mockRecipe, 'example:v1.5.0');

        $this->environment->expects($this->never())->method('getRegistryConfig');
        $this->docker->expects($this->never())->method('loginToRegistry');
        $this->docker->expects($this->never())->method('tagImage');
        $this->docker->expects($this->never())->method('pushImage');

        $this->cli->expects($this->atLeastOnce())->method('info');
        $this->cli->expects($this->once())->method('success');

        $this->ciCommand->execute($this->options);
    }

    public function testGetImageBaseNameUsesPublishTagPrefix(): void {
        $reflection = new ReflectionClass($this->ciCommand);
        $method = $reflection->getMethod('getImageBaseName');

        $mockRecipe = $this->createMock(Recipe::class);
        $mockRecipe->publishTagPrefix = 'my-custom-prefix';
        $mockRecipe->name = 'recipe-name';

        $result = $method->invoke($this->ciCommand, $mockRecipe);
        $this->assertEquals('my-custom-prefix', $result);
    }

    public function testGetImageBaseNameFallsBackToRecipeName(): void {
        $reflection = new ReflectionClass($this->ciCommand);
        $method = $reflection->getMethod('getImageBaseName');

        $mockRecipe = $this->createMock(Recipe::class);
        $mockRecipe->publishTagPrefix = null;
        $mockRecipe->name = 'My Recipe Name!';

        $result = $method->invoke($this->ciCommand, $mockRecipe);
        $this->assertEquals('my-recipe-name', $result);
    }

    public function testSanitizeImageName(): void {
        $reflection = new ReflectionClass($this->ciCommand);
        $method = $reflection->getMethod('sanitizeImageName');

        $this->assertEquals('my-app', $method->invoke($this->ciCommand, 'My App!'));
        $this->assertEquals('test-123-app', $method->invoke($this->ciCommand, 'Test@123#App'));
        $this->assertEquals('hello-world', $method->invoke($this->ciCommand, 'Hello World'));
        $this->assertEquals('moodle-app', $method->invoke($this->ciCommand, '--moodle-app--'));
    }

    public function testRecipeProductionOverrides(): void {
        $reflection = new ReflectionClass($this->ciCommand);
        $method = $reflection->getMethod('prepareRecipe');

        $mockRecipe = $this->createMock(Recipe::class);

        $this->cli->expects($this->atLeastOnce())->method('info');

        $result = $method->invoke($this->ciCommand, $mockRecipe);

        $this->assertFalse($result->mountPlugins);
        $this->assertFalse($result->developer);
        $this->assertFalse($result->includePhpUnit);
        $this->assertFalse($result->includeBehat);
        $this->assertFalse($result->includeXdebug);
    }
}
