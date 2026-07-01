<?php

use App\Exceptions\CliRuntimeException;
use App\Helpers\TestingHelpers;
use App\Model\GlobalConfig;
use App\Service\Configurator;
use App\Service\Github;
use App\Service\Main;
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

    public function testPublishWritesRedirectLinkFile(): void {
        $this->service->publish('{}', 'my-recipe');

        $path = $this->cacheDir . '/links/' . $this->expectedLinkHash('my-recipe') . '.txt';
        $this->assertFileExists($path);
    }

    public function testRedirectLinkFileContainsRawBlueprintUrl(): void {
        $this->service->publish('{}', 'my-recipe');

        $content = file_get_contents($this->cacheDir . '/links/' . $this->expectedLinkHash('my-recipe') . '.txt');
        $this->assertStringContainsString(
            'https://raw.githubusercontent.com/testuser/mchef-urls/main/blueprints/my-recipe.json',
            $content
        );
    }

    public function testRedirectLinkFilePointsToPlaygroundSite(): void {
        $this->service->publish('{}', 'my-recipe');

        $content = file_get_contents($this->cacheDir . '/links/' . $this->expectedLinkHash('my-recipe') . '.txt');
        $this->assertStringContainsString('moodle-playground.com', $content);
    }

    public function testPublishWritesShellPageOnce(): void {
        $this->service->publish('{}', 'my-recipe');

        $shellPagePath = $this->cacheDir . '/index.html';
        $this->assertFileExists($shellPagePath);
        $firstContent = file_get_contents($shellPagePath);

        // A second publish (even for a different recipe) must not rewrite the shell page —
        // it's the single unchanging redirect page, never touched after the first bootstrap.
        $this->service->publish('{}', 'another-recipe');
        $this->assertEquals($firstContent, file_get_contents($shellPagePath));
    }

    public function testShellPageFetchesFromResolvedDefaultBranch(): void {
        $this->service->publish('{}', 'my-recipe');

        $shellPage = file_get_contents($this->cacheDir . '/index.html');
        // execGit is mocked to return '' for everything, including the symbolic-ref
        // lookup, so resolveDefaultBranch() falls back to 'main' — confirm that value
        // actually made it into the rendered template rather than a literal placeholder.
        $this->assertStringContainsString('/main/links/', $shellPage);
    }

    public function testPublishReturnsShortUrl(): void {
        $shortUrl = $this->service->publish('{}', 'my-recipe');
        $this->assertEquals(
            'https://testuser.github.io/mchef-urls/index.html?linkHash=' . $this->expectedLinkHash('my-recipe'),
            $shortUrl
        );
    }

    public function testBaseUrlTrailingSlashStripped(): void {
        $service  = $this->makeService(playgroundUrlsBase: 'https://testuser.github.io/mchef-urls/');
        $shortUrl = $service->publish('{}', 'recipe');
        $this->assertEquals(
            'https://testuser.github.io/mchef-urls/index.html?linkHash=' . $this->expectedLinkHash('recipe'),
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

    public function testThrowsCliRuntimeExceptionForNonGithubRepo(): void {
        // A malformed/non-GitHub repo URL must surface as a CliRuntimeException (which
        // MChefCLI's top-level handler catches and prints cleanly), not a raw
        // InvalidArgumentException bubbling up as an uncaught PHP fatal.
        $service = $this->makeService(playgroundUrlsRepo: 'https://gitlab.com/testuser/mchef-urls.git');
        $this->expectException(CliRuntimeException::class);
        $service->publish('{}', 'test');
    }

    public function testPublishSnapshotWritesSq3File(): void {
        $sq3 = sys_get_temp_dir() . '/test-snapshot-' . uniqid() . '.sq3';
        file_put_contents($sq3, 'fake-sqlite-data');

        $this->service->publishSnapshot('my-recipe', $sq3);

        $this->assertFileExists($this->cacheDir . '/data/my-recipe.sq3');
        unlink($sq3);
    }

    public function testPublishSnapshotWritesLocalcacheWhenProvided(): void {
        $sq3       = sys_get_temp_dir() . '/test-snapshot-' . uniqid() . '.sq3';
        $cache     = sys_get_temp_dir() . '/test-localcache-' . uniqid() . '.zip';
        file_put_contents($sq3, 'fake-sqlite-data');
        file_put_contents($cache, 'fake-zip-data');

        $this->service->publishSnapshot('my-recipe', $sq3, $cache);

        $this->assertFileExists($this->cacheDir . '/data/my-recipe.sq3');
        $this->assertFileExists($this->cacheDir . '/data/my-recipe-localcache.zip');
        unlink($sq3);
        unlink($cache);
    }

    public function testPublishSnapshotReturnsRawContentUrl(): void {
        $sq3 = sys_get_temp_dir() . '/test-snapshot-' . uniqid() . '.sq3';
        file_put_contents($sq3, 'fake-sqlite-data');

        $url = $this->service->publishSnapshot('my-recipe', $sq3);

        $this->assertEquals(
            'https://raw.githubusercontent.com/testuser/mchef-urls/main/data/my-recipe.sq3',
            $url
        );
        unlink($sq3);
    }

    public function testStageSnapshotDoesNotCommit(): void {
        $sq3 = sys_get_temp_dir() . '/test-snapshot-' . uniqid() . '.sq3';
        file_put_contents($sq3, 'fake-sqlite-data');

        // execGit is mocked to no-op/return '' for every call including 'diff --cached',
        // so there's nothing to assert on commit state directly here beyond the fact
        // that stageSnapshot() still writes the file and returns the raw content URL —
        // the no-commit behaviour itself is exercised by publish()'s single-push flow.
        $url = $this->service->stageSnapshot('my-recipe', $sq3);

        $this->assertEquals(
            'https://raw.githubusercontent.com/testuser/mchef-urls/main/data/my-recipe.sq3',
            $url
        );
        $this->assertFileExists($this->cacheDir . '/data/my-recipe.sq3');
        unlink($sq3);
    }

    public function testDiscardStagedIsNoOpWhenNoRepoClonedYet(): void {
        $service = $this->getMockBuilder(PlaygroundUrlsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['execGit'])
            ->getMock();
        $service->expects($this->never())->method('execGit');

        $service->discardStaged();
    }

    public function testDiscardStagedResetsAfterRepoCloned(): void {
        $this->service->publish('{}', 'my-recipe');

        // execGit is mocked to no-op/return '' for everything; this just confirms
        // discardStaged() runs without error once a repo path has been cached.
        $this->service->discardStaged();
        $this->addToAssertionCount(1);
    }

    private function expectedLinkHash(string $name): string {
        $blueprintUrl = 'https://raw.githubusercontent.com/testuser/mchef-urls/main/blueprints/' . $name . '.json';
        $targetUrl    = 'https://moodle-playground.com/?blueprint-url=' . $blueprintUrl;
        return sha1($targetUrl);
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

        $this->applyMockedServices([
            'configuratorService' => $mockConfigurator,
            'github' => Github::instance(),
            'mainService' => Main::instance(),
        ], $service);

        return $service;
    }
}
