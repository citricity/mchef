<?php

namespace App\Tests\Integration;

use App\Model\Recipe;
use App\Service\Git;
use App\Service\Plugins;
use App\Tests\MchefTestCase;

/**
 * Integration test for public folder support in Moodle 5.1+
 */
class PublicFolderIntegrationTest extends MchefTestCase {

    public function testMoodlePublicFolderDetection(): void {
        $gitService = Git::instance();
        
        // Test that Moodle 5.0 does NOT have public folder
        $hasPublicFolder = $gitService->moodleHasPublicFolder('v5.0.0');
        $this->assertFalse($hasPublicFolder, 'Moodle 5.0 should not have public folder');
        
        // Test that a non-existent tag returns false
        $hasPublicFolder = $gitService->moodleHasPublicFolder('v999.999.999');
        $this->assertFalse($hasPublicFolder, 'Non-existent Moodle version should return false');
        
        // Test that Moodle 5.1+ DOES have public folder
        $hasPublicFolder = $gitService->moodleHasPublicFolder('v5.1.0');
        $this->assertTrue($hasPublicFolder, 'Moodle 5.1+ should have public folder');
    }

    public function testPluginPathGenerationWithoutPublicFolder(): void {
        $pluginsService = Plugins::instance();
        
        // Create a recipe for Moodle 5.0 (no public folder)
        $recipe = new Recipe(
            moodleTag: 'v5.0.0',
            phpVersion: '8.0'
        );
        
        // Test that plugin paths are generated without public/ prefix
        $filterPath = $this->callRestricted($pluginsService, 'getMoodlePluginPath', ['filter_imageopt', $recipe]);
        $this->assertEquals('/filter/imageopt', $filterPath);
        
        $localPath = $this->callRestricted($pluginsService, 'getMoodlePluginPath', ['local_test', $recipe]);
        $this->assertEquals('/local/test', $localPath);
        
        $themePath = $this->callRestricted($pluginsService, 'getMoodlePluginPath', ['theme_boost', $recipe]);
        $this->assertEquals('/theme/boost', $themePath);
    }

    public function testPluginPathGenerationWithPublicFolder(): void {
        $pluginsService = Plugins::instance();
        
        // Create a recipe for Moodle 5.1.0 (has public folder)
        $recipe = new Recipe(
            moodleTag: 'v5.1.0',
            phpVersion: '8.0'
        );
        
        // Test that plugin paths are generated WITH public/ prefix for Moodle 5.1+
        $filterPath = $this->callRestricted($pluginsService, 'getMoodlePluginPath', ['filter_imageopt', $recipe]);
        $this->assertEquals('/public/filter/imageopt', $filterPath);
        
        $localPath = $this->callRestricted($pluginsService, 'getMoodlePluginPath', ['local_test', $recipe]);
        $this->assertEquals('/public/local/test', $localPath);
        
        $themePath = $this->callRestricted($pluginsService, 'getMoodlePluginPath', ['theme_boost', $recipe]);
        $this->assertEquals('/public/theme/boost', $themePath);
    }

    public function testFullPluginInstallationWorkflow(): void {
        // This test would verify the complete workflow:
        // 1. Recipe with plugin configuration
        // 2. Plugin gets installed to correct path based on Moodle version
        // 3. Files are copied/mounted to the right location
        
        $recipe = new Recipe(
            moodleTag: 'v5.0.0',
            phpVersion: '8.0',
            plugins: [
                [
                    'repo' => 'https://github.com/gthomas2/moodle-filter_imageopt',
                    'branch' => 'master'
                ]
            ]
        );
        
        // This would require a full integration environment setup
        // For now, we'll just validate the recipe structure
        $this->assertNotNull($recipe->plugins);
        $this->assertCount(1, $recipe->plugins);
        
        $this->markTestIncomplete('Full integration test requires Docker environment setup');
    }

    /**
     * Helper method to access protected methods for testing
     */
    private function callRestricted($object, $method, $args = []) {
        $reflectionClass = new \ReflectionClass($object);
        $reflectionMethod = $reflectionClass->getMethod($method);
        return $reflectionMethod->invokeArgs($object, $args);
    }
}