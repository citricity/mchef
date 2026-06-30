<?php

use App\Exceptions\CliRuntimeException;
use App\Helpers\TestingHelpers;
use App\Model\GlobalConfig;
use App\Service\Configurator;
use App\Service\PlaygroundUrlsService;

final class PlaygroundUrlsServiceTest extends \App\Tests\MchefTestCase
{
    private PlaygroundUrlsService $service;
    private string $cacheDir;

    protected function setUp(): void {
        parent::setUp();

        $this->cacheDir = TestingHelpers::getTestDir() . '/playground-urls-cache';
        mkdir($this->cacheDir . '/.git', 0755, true);

        $this->service = $this->makeService();
    }

    public function testPublishWritesBlueprintJson(): void {
        $this->service->publish('{"steps":[]}', 'my-recipe');

        $path = $this->cacheDir . '/blueprints/my-recipe.json';
        $this->assertFileExists($path);
        $this->assertStringContainsString('"steps"', file_get_contents($path));
    }

    public function testPublishWritesRedirectHtml(): void {
        $this->service->publish('{}', 'my-recipe');

        $path = $this->cacheDir . '/links/my-recipe.html';
        $this->assertFileExists($path);
    }

    public function testRedirectHtmlContainsBlueprintUrl(): void {
        $this->service->publish('{}', 'my-recipe');

        $html = file_get_contents($this->cacheDir . '/links/my-recipe.html');
        $this->assertStringContainsString(
            'https://testuser.github.io/mchef-urls/blueprints/my-recipe.json',
            $html
        );
    }

    public function testRedirectHtmlPointsToPlaygroundSite(): void {
        $this->service->publish('{}', 'my-recipe');

        $html = file_get_contents($this->cacheDir . '/links/my-recipe.html');
        $this->assertStringContainsString('moodle-playground.com', $html);
    }

    public function testRedirectHtmlHasMetaRefresh(): void {
        $this->service->publish('{}', 'my-recipe');

        $html = file_get_contents($this->cacheDir . '/links/my-recipe.html');
        $this->assertStringContainsString('http-equiv="refresh"', $html);
    }

    public function testPublishReturnsShortUrl(): void {
        $shortUrl = $this->service->publish('{}', 'my-recipe');
        $this->assertEquals(
            'https://testuser.github.io/mchef-urls/links/my-recipe',
            $shortUrl
        );
    }

    public function testBaseUrlTrailingSlashStripped(): void {
        $service  = $this->makeService(playgroundUrlsBase: 'https://testuser.github.io/mchef-urls/');
        $shortUrl = $service->publish('{}', 'recipe');
        $this->assertEquals(
            'https://testuser.github.io/mchef-urls/links/recipe',
            $shortUrl
        );
    }

    public function testThrowsWhenRepoNotConfigured(): void {
        $service = $this->makeService(playgroundUrlsRepo: null);
        $this->expectException(CliRuntimeException::class);
        $service->publish('{}', 'test');
    }

    public function testThrowsWhenBaseNotConfigured(): void {
        $service = $this->makeService(playgroundUrlsBase: null);
        $this->expectException(CliRuntimeException::class);
        $service->publish('{}', 'test');
    }

    private function makeService(
        ?string $playgroundUrlsRepo = 'https://github.com/testuser/mchef-urls.git',
        ?string $playgroundUrlsBase = 'https://testuser.github.io/mchef-urls'
    ): PlaygroundUrlsService {
        $service = $this->getMockBuilder(PlaygroundUrlsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['execGit'])
            ->getMock();
        $service->method('execGit')->willReturn('');

        $mockConfig = new GlobalConfig(
            playgroundUrlsRepo: $playgroundUrlsRepo,
            playgroundUrlsBase: $playgroundUrlsBase,
        );
        $mockConfigurator = $this->createMock(Configurator::class);
        $mockConfigurator->method('getMainConfig')->willReturn($mockConfig);
        $mockConfigurator->method('configDir')->willReturn(TestingHelpers::getTestDir());

        $this->applyMockedServices(['configuratorService' => $mockConfigurator], $service);

        return $service;
    }
}
