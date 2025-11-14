<?php

namespace App\Tests;

use App\Model\Recipe;
use App\Service\Moodle;
use App\Tests\MchefTestCase;

class MoodleTest extends MchefTestCase {

    private Moodle $moodleService;
    private string $testProjectDir;

    protected function setUp(): void {
        parent::setUp();
        $this->moodleService = Moodle::instance();
        
        // Create a temporary directory for testing
        $this->testProjectDir = sys_get_temp_dir() . '/mchef-moodle-test-' . uniqid();
        mkdir($this->testProjectDir, 0755, true);
    }

    protected function tearDown(): void {
        // Clean up test directory
        if (is_dir($this->testProjectDir)) {
            $this->deleteDirectory($this->testProjectDir);
        }
        parent::tearDown();
    }

    private function deleteDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testProvideMoodleDirectoryCreatesDefaultDirectory(): void {
        $recipe = $this->createMockRecipe();
        $recipePath = $this->testProjectDir . '/recipe.json';
        
        $result = $this->moodleService->provideMoodleDirectory($recipe, $recipePath);
        
        $expectedPath = $this->testProjectDir . '/moodle';
        $this->assertEquals($expectedPath, $result);
        $this->assertTrue(is_dir($expectedPath));
    }

    public function testProvideMoodleDirectoryCreatesCustomDirectory(): void {
        $recipe = $this->createMockRecipe();
        $recipe->moodleDirectory = 'custom-moodle';
        $recipePath = $this->testProjectDir . '/recipe.json';
        
        $result = $this->moodleService->provideMoodleDirectory($recipe, $recipePath);
        
        $expectedPath = $this->testProjectDir . '/custom-moodle';
        $this->assertEquals($expectedPath, $result);
        $this->assertTrue(is_dir($expectedPath));
    }

    public function testProvideMoodleDirectoryDoesNotRecreateExistingDirectory(): void {
        $recipe = $this->createMockRecipe();
        $recipePath = $this->testProjectDir . '/recipe.json';
        
        // Create directory first time
        $firstResult = $this->moodleService->provideMoodleDirectory($recipe, $recipePath);
        
        // Create a test file in the directory
        $testFile = $firstResult . '/test.txt';
        file_put_contents($testFile, 'test content');
        
        // Call again - should not recreate
        $secondResult = $this->moodleService->provideMoodleDirectory($recipe, $recipePath);
        
        $this->assertEquals($firstResult, $secondResult);
        $this->assertTrue(file_exists($testFile));
        $this->assertEquals('test content', file_get_contents($testFile));
    }

    public function testGetMoodleDirectoryPathDoesNotCreateDirectory(): void {
        $recipe = $this->createMockRecipe();
        $recipePath = $this->testProjectDir . '/recipe.json';
        
        $result = $this->moodleService->getMoodleDirectoryPath($recipe, $recipePath);
        
        $expectedPath = $this->testProjectDir . '/moodle';
        $this->assertEquals($expectedPath, $result);
        $this->assertFalse(is_dir($expectedPath)); // Should not create the directory
    }

    public function testGetMoodleDirectoryPathWithCustomDirectory(): void {
        $recipe = $this->createMockRecipe();
        $recipe->moodleDirectory = 'my-moodle';
        $recipePath = $this->testProjectDir . '/recipe.json';
        
        $result = $this->moodleService->getMoodleDirectoryPath($recipe, $recipePath);
        
        $expectedPath = $this->testProjectDir . '/my-moodle';
        $this->assertEquals($expectedPath, $result);
        $this->assertFalse(is_dir($expectedPath)); // Should not create the directory
    }

    public function testProvideMoodleDirectoryThrowsExceptionOnFailure(): void {
        $recipe = $this->createMockRecipe();
        // Use an invalid path that cannot be created
        $recipePath = '/invalid/path/that/cannot/be/created/recipe.json';
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create moodle directory');
        
        $this->moodleService->provideMoodleDirectory($recipe, $recipePath);
    }

    private function createMockRecipe(): Recipe {
        return new Recipe(
            moodleTag: '4.1.0',
            phpVersion: '8.0'
        );
    }
}
