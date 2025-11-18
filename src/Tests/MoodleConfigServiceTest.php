<?php

namespace App\Tests;

use App\Service\MoodleConfig;
use App\Model\Recipe;
use App\Model\DockerData;
use App\Model\Volume;
use PHPUnit\Framework\MockObject\MockObject;
use App\Service\Main;
use App\Service\Environment;

/**
 * @covers \App\Service\MoodleConfig
 */
class MoodleConfigServiceTest extends MchefTestCase
{

    public function setUp(): void
    {
        parent::setUp();

        // Ensure singleton is created so StaticVars::$cli is injected via constructor.
        MoodleConfig::instance();
    }

    use \App\Traits\CallRestrictedMethodTrait;

    /**
     * Recursively remove a directory and all its contents.
     */
    private function removeDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            if (is_dir($path)) {
                $this->removeDirectoryRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testProcessConfigFileCopiesCustomConfigFileWhenRegistryPresent(): void
    {
        $moodleConfig = MoodleConfig::instance();

        $base = sys_get_temp_dir().'/mchef_copy_'.uniqid();
        $assetsPath = $base.'/docker/assets';
        mkdir($assetsPath, 0755, true);

        // Use a real Main instance so Twig namespaces are available
        $realMain = Main::instance();
        $this->setRestrictedProperty($realMain, 'chefPath', $base);
        if (!is_dir($base.'/docker/assets')) {
            mkdir($base.'/docker/assets', 0755, true);
        }

        $envMock = $this->getMockBuilder(Environment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRegistryConfig'])
            ->getMock();
        $envMock->method('getRegistryConfig')->willReturn(['some' => 'config']);

        $this->applyMockedServices(
            [
                'mainService' => $realMain,
                'environmentService' => $envMock,
            ],
            $moodleConfig
        );
        $tempFile = tempnam(sys_get_temp_dir(), 'mchef_cfg_');
        file_put_contents($tempFile, "<?php // test config\n");

        $recipe = new Recipe('v4.5.0', '8.0');
        $recipe->configFile = $tempFile;
        $recipe->mountPlugins = false;
        $recipe->includeBehat = true;

        // Execute - should copy and then render twig templates
        $moodleConfig->processConfigFile($recipe);

        $this->assertTrue($recipe->copyCustomConfigFile);
        $this->assertEquals('/var/www/html/moodle/config-local.php', $recipe->customConfigFile);
        $this->assertFileExists($realMain->getAssetsPath().'/config-local.php');
        $this->assertFileExists($realMain->getAssetsPath().'/config.php');

        // Cleanup
        unlink($tempFile);
        $assetsPath = $realMain->getAssetsPath();
        $this->removeDirectoryRecursive($assetsPath);
        $dockerDir = dirname($assetsPath);
        if (is_dir($dockerDir)) {
            rmdir($dockerDir);
        }
        if (is_dir($base)) {
            rmdir($base);
        }
    }

    public function testProcessConfigFileMountsCustomConfigFileWhenMountPluginsAndNoRegistry(): void
    {
        $moodleConfig = MoodleConfig::instance();

        $base = sys_get_temp_dir().'/mchef_mount_'.uniqid();
        $assetsPath = $base.'/docker/assets';
        mkdir($assetsPath, 0755, true);

        // Use a real Main instance so Twig namespaces and DockerData work
        $realMain = Main::instance();
        $this->setRestrictedProperty($realMain, 'chefPath', $base);
        if (!is_dir($base.'/docker/assets')) {
            mkdir($base.'/docker/assets', 0755, true);
        }

        $envMock = $this->getMockBuilder(Environment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRegistryConfig'])
            ->getMock();
        $envMock->method('getRegistryConfig')->willReturn(null);

        $tempFile = tempnam(sys_get_temp_dir(), 'mchef_cfg_');
        file_put_contents($tempFile, "<?php // mount config\n");

        $recipe = new Recipe('v4.5.0', '8.0');
        $recipe->configFile = $tempFile;
        $recipe->mountPlugins = true;
        $recipe->includeBehat = true;
        $recipe->setRecipePath($base);

        // Make sure Main has a recipe so establishDockerData() can use it
        $this->setRestrictedProperty($realMain, 'recipe', $recipe);

        $this->applyMockedServices(
            [
                'mainService' => $realMain,
                'environmentService' => $envMock,
            ],
            $moodleConfig
        );

        $moodleConfig->processConfigFile($recipe);

        $dockerData = $realMain->getDockerData();

        $this->assertEquals('/var/www/html/moodle/config-local.php', $recipe->customConfigFile);
        $this->assertCount(1, $dockerData->volumes);
        $this->assertInstanceOf(Volume::class, $dockerData->volumes[0]);
        $this->assertEquals($tempFile, $dockerData->volumes[0]->hostPath);

        // Cleanup
        unlink($tempFile);
        $assetsPath = $realMain->getAssetsPath();
        $this->removeDirectoryRecursive($assetsPath);
        $dockerDir = dirname($assetsPath);
        if (is_dir($dockerDir)) {
            rmdir($dockerDir);
        }
        if (is_dir($base)) {
            rmdir($base);
        }
    }
}
