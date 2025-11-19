<?php

namespace App\Tests;

use App\Model\Plugin;
use App\Model\RecipePlugin;
use App\Model\Volume;
use App\Model\PluginsInfo;
use App\Model\Recipe;
use PHPUnit\Framework\TestCase;
use App\Service\Plugins;
use \App\Traits\CallRestrictedMethodTrait;

final class PluginsServiceTest extends MchefTestCase {
    use CallRestrictedMethodTrait;

    public function testGetMoodlePluginPath(): void {
        $pluginsService = Plugins::instance();
        
        // Test without recipe (should work as before)
        $path = $this->callRestricted($pluginsService, 'getMoodlePluginPath', ['local_test', null]);
        $this->assertEquals('/local/test', $path);
        
        // Test with recipe that doesn't use public folder (Moodle 5.0)
        $recipe = new Recipe(
            moodleTag: 'v5.0.0',
            phpVersion: '8.0'
        );
        $path = $this->callRestricted($pluginsService, 'getMoodlePluginPath', ['local_test', $recipe]);
        $this->assertEquals('/local/test', $path);
        
        // Note: We can't easily test public folder behavior in unit tests 
        // as it requires remote Git calls. This would be covered by integration tests.
    }

    public function testGetPluginsInfoFromRecipe(): void {
        $pluginsService = Plugins::instance();

        // Test with null plugins - this should return null without any complex processing
        $recipeWithoutPlugins = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            plugins: null
        );
        $recipeWithoutPlugins->setRecipePath('/tmp/test-recipe.json');
        $result = $pluginsService->getPluginsInfoFromRecipe($recipeWithoutPlugins);
        $this->assertNull($result);

        // Test with empty plugins array - this should also return null
        $recipeWithEmptyPlugins = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            plugins: []
        );
        $recipeWithEmptyPlugins->setRecipePath('/tmp/test-recipe.json');
        $result = $pluginsService->getPluginsInfoFromRecipe($recipeWithEmptyPlugins);
        $this->assertNull($result);

        // For more complex cases involving actual plugin processing,
        // those would require integration tests with actual file system setup
        // The basic null/empty validation is the main logic we want to verify here
    }

    public function testGetPluginComponentFromVersionFile(): void {
        $pluginsService = Plugins::instance();
        $component = $pluginsService->getPluginComponentFromVersionFile(__DIR__.'/Fixtures/version-test.php');
        $this->assertEquals('mod_assign', $component);
    }

    public function testFindMoodleVersionFiles(): void {
        $pluginsService = Plugins::instance();

        $tempDir = sys_get_temp_dir() . '/moodle_test_' . uniqid();
        mkdir($tempDir);
        mkdir($tempDir . '/subdir1');
        mkdir($tempDir . '/subdir2');
        file_put_contents($tempDir . '/version.php', '<?php $plugin->version = 2022020801;');
        file_put_contents($tempDir . '/subdir1/version.php', '<?php $plugin->version = 2022020800;');
        file_put_contents($tempDir . '/subdir2/otherfile.php', '<?php // not a version file');

        $versionFiles = $pluginsService->findMoodleVersionFiles($tempDir);

        unlink($tempDir . '/version.php');
        unlink($tempDir . '/subdir1/version.php');
        unlink($tempDir . '/subdir2/otherfile.php');
        rmdir($tempDir . '/subdir1');
        rmdir($tempDir . '/subdir2');
        rmdir($tempDir);

        $expectedFiles = [
            $tempDir . '/version.php',
            $tempDir . '/subdir1/version.php'
        ];
        sort($versionFiles);
        sort($expectedFiles);
        $this->assertEquals($expectedFiles, $versionFiles);
    }

    public function testGetPluginByComponentName(): void {
        $pluginsService = Plugins::instance();

        $plugin1 = new Plugin(
            'mod_assign',
            '/path/to/mod_assign',
            '/full/path/to/mod_assign',
            $this->createMock(Volume::class),
            'https://github.com/moodle/mod_assign',
            Plugin::TYPE_SINGLE
        );

        $plugin2 = new Plugin(
            'mod_forum',
            '/path/to/mod_forum',
            '/full/path/to/mod_forum',
            $this->createMock(Volume::class),
            'https://github.com/moodle/mod_forum',
            Plugin::TYPE_SINGLE
        );

        $pluginsInfo = new PluginsInfo(
            [], // Pass an empty array or mock volumes here
            [$plugin1, $plugin2]
        );

        $result = $pluginsService->getPluginByComponentName('mod_assign', $pluginsInfo);
        $this->assertSame($plugin1, $result);

        $result = $pluginsService->getPluginByComponentName('mod_nonexistent', $pluginsInfo);
        $this->assertNull($result);

        $pluginsInfo->plugins[] = new Plugin(
            'mod_assign',
            '/path/to/another_mod_assign',
            '/full/path/to/another_mod_assign',
            $this->createMock(Volume::class),
            'https://github.com/moodle/another_mod_assign',
            Plugin::TYPE_SINGLE
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Found more than one plugin entry for component "mod_assign" this should not happen');
        $pluginsService->getPluginByComponentName('mod_assign', $pluginsInfo);
    }

    public function testExtractRepoInfoFromPluginString(): void {
        $pluginsService = Plugins::instance();

        // Test simple URL string
        $result = $this->callRestricted($pluginsService, 'extractRepoInfoFromPlugin', ['https://github.com/user/repo.git']);
        $this->assertInstanceOf(RecipePlugin::class, $result);
        $this->assertEquals('https://github.com/user/repo.git', $result->repo);
        $this->assertEquals('main', $result->branch);
        $this->assertNull($result->upstream);

        // Test URL with branch
        $result = $this->callRestricted($pluginsService, 'extractRepoInfoFromPlugin', ['https://github.com/user/repo.git~develop']);
        $this->assertInstanceOf(RecipePlugin::class, $result);
        $this->assertEquals('https://github.com/user/repo.git', $result->repo);
        $this->assertEquals('develop', $result->branch);
        $this->assertNull($result->upstream);
    }

    public function testExtractRepoInfoFromPluginObject(): void {
        $pluginsService = Plugins::instance();

        // Test object with all properties
        $plugin = (object)[
            'repo' => 'https://github.com/user/repo.git',
            'branch' => 'feature-branch',
            'upstream' => 'https://github.com/upstream/repo.git'
        ];
        $result = $this->callRestricted($pluginsService, 'extractRepoInfoFromPlugin', [$plugin]);
        $this->assertInstanceOf(RecipePlugin::class, $result);
        $this->assertEquals('https://github.com/user/repo.git', $result->repo);
        $this->assertEquals('feature-branch', $result->branch);
        $this->assertEquals('https://github.com/upstream/repo.git', $result->upstream);

        // Test object with minimal properties
        $plugin = (object)[
            'repo' => 'https://github.com/user/repo.git'
        ];
        $result = $this->callRestricted($pluginsService, 'extractRepoInfoFromPlugin', [$plugin]);
        $this->assertInstanceOf(RecipePlugin::class, $result);
        $this->assertEquals('https://github.com/user/repo.git', $result->repo);
        $this->assertEquals('main', $result->branch);
        $this->assertNull($result->upstream);
    }

    public function testExtractRepoInfoFromPluginWithUpstream(): void {
        $pluginsService = Plugins::instance();

        // Test the example from the recipe JSON
        $plugin = (object)[
            'repo' => 'https://github.com/gthomas2/moodle-theme_snap.git',
            'upstream' => 'https://github.com/open-lms-open-source/moodle-theme_snap.git',
            'branch' => 'MOODLE_401_STABLE'
        ];
        $result = $this->callRestricted($pluginsService, 'extractRepoInfoFromPlugin', [$plugin]);
        $this->assertInstanceOf(RecipePlugin::class, $result);
        $this->assertEquals('https://github.com/gthomas2/moodle-theme_snap.git', $result->repo);
        $this->assertEquals('MOODLE_401_STABLE', $result->branch);
        $this->assertEquals('https://github.com/open-lms-open-source/moodle-theme_snap.git', $result->upstream);
    }
}
