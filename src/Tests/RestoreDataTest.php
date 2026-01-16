<?php

namespace App\Tests;

use App\Model\Recipe;
use App\Model\RestoreStructure;
use App\Service\Docker;
use App\Service\Moodle;
use App\Service\RestoreData;
use App\Traits\CallRestrictedMethodTrait;
use PHPUnit\Framework\MockObject\MockObject;

class RestoreDataTest extends MchefTestCase {
    use CallRestrictedMethodTrait;

    private RestoreData $restoreDataService;
    private MockObject $dockerService;
    private MockObject $moodleService;

    protected function setUp(): void {
        parent::setUp();

        $this->dockerService = $this->createMock(Docker::class);
        $this->moodleService = $this->createMock(Moodle::class);

        $this->restoreDataService = RestoreData::instance();
        $this->applyMockedServices([
            'dockerService' => $this->dockerService,
            'moodleService' => $this->moodleService
        ], $this->restoreDataService);
    }

    private function createTestRecipe(?RestoreStructure $restoreStructure = null): Recipe {
        $recipe = new Recipe(
            moodleTag: '4.1.0',
            phpVersion: '8.0',
            name: 'test-recipe'
        );
        $recipe->setRecipePath('/tmp/test-recipe.json');
        $recipe->restoreStructure = $restoreStructure;
        return $recipe;
    }

    // Test helper methods

    public function testIsUrl(): void {
        $this->assertTrue($this->callRestricted($this->restoreDataService, 'isUrl', ['http://example.com']));
        $this->assertTrue($this->callRestricted($this->restoreDataService, 'isUrl', ['https://example.com/file.csv']));
        $this->assertFalse($this->callRestricted($this->restoreDataService, 'isUrl', ['/path/to/file.csv']));
        $this->assertFalse($this->callRestricted($this->restoreDataService, 'isUrl', ['relative/path.csv']));
    }

    public function testDecodeJsonSafely(): void {
        $validJson = '{"key": "value", "number": 123}';
        $result = $this->callRestricted($this->restoreDataService, 'decodeJsonSafely', [$validJson, 'test']);
        
        $this->assertIsArray($result);
        $this->assertEquals('value', $result['key']);
        $this->assertEquals(123, $result['number']);
    }

    public function testDecodeJsonSafelyWithInvalidJson(): void {
        $invalidJson = '{invalid json}';
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to decode test');
        $this->callRestricted($this->restoreDataService, 'decodeJsonSafely', [$invalidJson, 'test']);
    }

    public function testDecodeJsonSafelyWithNonArray(): void {
        $nonArrayJson = '"just a string"';
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('expected array');
        $this->callRestricted($this->restoreDataService, 'decodeJsonSafely', [$nonArrayJson, 'test']);
    }

    public function testEncodeJsonSafely(): void {
        $data = ['key' => 'value', 'number' => 123];
        $result = $this->callRestricted($this->restoreDataService, 'encodeJsonSafely', [$data, 'test']);
        
        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertEquals($data, $decoded);
    }

    public function testValidateScriptOutputWithNonZeroReturnCode(): void {
        $output = 'Some error output';
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('script returned exit code 1');
        $this->callRestricted($this->restoreDataService, 'validateScriptOutput', [$output, 1]);
    }

    public function testValidateScriptOutputWithErrorPrefix(): void {
        $output = 'ERROR: Something went wrong';
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ERROR: Something went wrong');
        $this->callRestricted($this->restoreDataService, 'validateScriptOutput', [$output, 0]);
    }

    public function testValidateScriptOutputWithPhpError(): void {
        $output = 'PHP Fatal error: Call to undefined function';
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('PHP error in script');
        $this->callRestricted($this->restoreDataService, 'validateScriptOutput', [$output, 0]);
    }

    public function testValidateScriptOutputWithValidOutput(): void {
        $output = '{"category1": 1, "category2": 2}';
        
        // Should not throw exception
        $this->callRestricted($this->restoreDataService, 'validateScriptOutput', [$output, 0]);
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testExtractJsonFromOutput(): void {
        $output = "Some debug output\n{\"category1\": 1, \"category2\": 2}\nMore output";
        $result = $this->callRestricted($this->restoreDataService, 'extractJsonFromOutput', [$output]);
        
        $this->assertStringContainsString('{"category1": 1, "category2": 2}', $result);
    }

    public function testExtractJsonFromOutputWithOnlyJson(): void {
        $output = '{"category1": 1, "category2": 2}';
        $result = $this->callRestricted($this->restoreDataService, 'extractJsonFromOutput', [$output]);
        
        $this->assertEquals($output, trim($result));
    }

    public function testParseCategoryMapFromOutput(): void {
        $output = "Debug message\n{\"Category 1\": 1, \"Category 2\": 2}";
        $result = $this->callRestricted($this->restoreDataService, 'parseCategoryMapFromOutput', [$output]);
        
        $this->assertIsArray($result);
        $this->assertEquals(1, $result['Category 1']);
        $this->assertEquals(2, $result['Category 2']);
    }

    public function testResolveFilePathWithUrl(): void {
        $url = 'https://example.com/file.csv';
        $recipeDir = '/tmp';
        
        $result = $this->callRestricted($this->restoreDataService, 'resolveFilePath', [$url, $recipeDir]);
        $this->assertEquals($url, $result);
    }

    public function testResolveFilePathWithAbsolutePath(): void {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        $recipeDir = '/tmp';
        
        $result = $this->callRestricted($this->restoreDataService, 'resolveFilePath', [$tempFile, $recipeDir]);
        $this->assertEquals($tempFile, $result);
        
        unlink($tempFile);
    }

    public function testResolveFilePathWithRelativePath(): void {
        $tempDir = sys_get_temp_dir();
        $testFile = $tempDir . '/test_file.csv';
        file_put_contents($testFile, 'test');
        
        $relativePath = basename($testFile);
        $result = $this->callRestricted($this->restoreDataService, 'resolveFilePath', [$relativePath, $tempDir]);
        
        $this->assertEquals($testFile, $result);
        
        unlink($testFile);
    }

    public function testResolveFilePathWithNonExistentFile(): void {
        $nonExistentFile = '/nonexistent/path/file.csv';
        $recipeDir = '/tmp';
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found');
        $this->callRestricted($this->restoreDataService, 'resolveFilePath', [$nonExistentFile, $recipeDir]);
    }

    // Test processRestoreStructure

    public function testProcessRestoreStructureWithEmptyRestoreStructure(): void {
        $recipe = $this->createTestRecipe(null);
        
        // Should return early without doing anything
        $this->restoreDataService->processRestoreStructure($recipe, 'test-container');
        
        // Verify no Docker service methods were called
        $this->dockerService->expects($this->never())->method('copyFileToContainer');
        $this->dockerService->expects($this->never())->method('downloadFileInContainer');
    }

    public function testProcessRestoreStructureWithUsersOnly(): void {
        // Create a temporary directory and CSV file
        $tempDir = sys_get_temp_dir() . '/mchef_test_' . uniqid();
        mkdir($tempDir);
        $csvFile = $tempDir . '/users.csv';
        file_put_contents($csvFile, 'test,data');
        
        $restoreStructure = new RestoreStructure(users: 'users.csv');
        $recipe = $this->createTestRecipe($restoreStructure);
        $recipeFile = $tempDir . '/recipe.json';
        file_put_contents($recipeFile, json_encode(['name' => 'test']));
        $recipe->setRecipePath($recipeFile);
        
        $moodlePath = '/var/www/html';
        $this->moodleService->expects($this->once())
            ->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn($moodlePath);
        
        $this->moodleService->expects($this->once())
            ->method('shouldUsePublicFolder')
            ->with($recipe)
            ->willReturn(false);
        
        $this->dockerService->expects($this->once())
            ->method('copyFileToContainer')
            ->with($csvFile, 'test-container', '/tmp/users.csv');
        
        $this->dockerService->expects($this->once())
            ->method('executePhpScriptPassthru')
            ->with(
                'test-container',
                $moodlePath . '/admin/tool/uploaduser/cli/uploaduser.php',
                $this->arrayHasKey(0)
            );
        
        $this->cli->method('notice');
        $this->cli->method('success');
        
        $this->restoreDataService->processRestoreStructure($recipe, 'test-container');
        
        unlink($csvFile);
        unlink($recipeFile);
        rmdir($tempDir);
    }

    public function testProcessRestoreStructureWithUsersFromUrl(): void {
        $restoreStructure = new RestoreStructure(users: 'https://example.com/users.csv');
        $recipe = $this->createTestRecipe($restoreStructure);
        
        $moodlePath = '/var/www/html';
        $this->moodleService->expects($this->once())
            ->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn($moodlePath);
        
        $this->moodleService->expects($this->once())
            ->method('shouldUsePublicFolder')
            ->with($recipe)
            ->willReturn(false);
        
        $this->dockerService->expects($this->once())
            ->method('downloadFileInContainer')
            ->with('test-container', 'https://example.com/users.csv', '/tmp/users.csv');
        
        $this->dockerService->expects($this->once())
            ->method('normalizeCsvFileInContainer')
            ->with('test-container', '/tmp/users.csv');
        
        $this->dockerService->expects($this->once())
            ->method('executePhpScriptPassthru');
        
        $this->restoreDataService->processRestoreStructure($recipe, 'test-container');
    }

    public function testProcessRestoreStructureWithCourseCategories(): void {
        $courseCategories = [
            'Category 1' => ['course1.mbz', 'course2.mbz']
        ];
        $restoreStructure = new RestoreStructure(courseCategories: $courseCategories);
        $recipe = $this->createTestRecipe($restoreStructure);
        
        // Create a temporary recipe file
        $recipeFile = tempnam(sys_get_temp_dir(), 'recipe_');
        file_put_contents($recipeFile, json_encode(['name' => 'test']));
        $recipe->setRecipePath($recipeFile);
        
        $moodlePath = '/var/www/html';
        $this->moodleService->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn($moodlePath);
        
        $this->moodleService->method('shouldUsePublicFolder')
            ->with($recipe)
            ->willReturn(false);
        
        // Mock category creation script execution
        $categoryMapJson = '{"Category 1": 1}';
        $this->dockerService->method('copyFileToContainer');
        
        $this->dockerService->expects($this->once())
            ->method('executeInContainerWithEnv')
            ->willReturn([$categoryMapJson, 0]);
        
        // Mock course restore (will fail on file not found, but we're testing the flow)
        $this->dockerService->method('executePhpScriptPassthru');
        
        $this->cli->method('info')->willReturn(null);
        $this->cli->method('notice');
        $this->cli->method('success');
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found');
        $this->restoreDataService->processRestoreStructure($recipe, 'test-container');
        
        unlink($recipeFile);
    }

    public function testProcessRestoreStructureWithNestedCategories(): void {
        $courseCategories = [
            'Parent Category' => [
                'Child Category' => ['course1.mbz']
            ]
        ];
        $restoreStructure = new RestoreStructure(courseCategories: $courseCategories);
        $recipe = $this->createTestRecipe($restoreStructure);
        
        $recipeFile = tempnam(sys_get_temp_dir(), 'recipe_');
        file_put_contents($recipeFile, json_encode(['name' => 'test']));
        $recipe->setRecipePath($recipeFile);
        
        $moodlePath = '/var/www/html';
        $this->moodleService->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn($moodlePath);
        
        $this->moodleService->method('shouldUsePublicFolder')
            ->with($recipe)
            ->willReturn(false);
        
        $categoryMapJson = '{"Parent Category": 1, "Child Category": 2}';
        $this->dockerService->method('copyFileToContainer');
        
        $this->dockerService->expects($this->once())
            ->method('executeInContainerWithEnv')
            ->willReturn([$categoryMapJson, 0]);
        
        $this->dockerService->method('executePhpScriptPassthru');
        
        $this->cli->method('info');
        $this->cli->method('notice');
        $this->cli->method('success');
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found');
        $this->restoreDataService->processRestoreStructure($recipe, 'test-container');
        
        unlink($recipeFile);
    }

    public function testProcessRestoreStructureWithCourseFromUrl(): void {
        $courseCategories = [
            'Category 1' => ['https://example.com/course.mbz']
        ];
        $restoreStructure = new RestoreStructure(courseCategories: $courseCategories);
        $recipe = $this->createTestRecipe($restoreStructure);
        
        $recipeFile = tempnam(sys_get_temp_dir(), 'recipe_');
        file_put_contents($recipeFile, json_encode(['name' => 'test']));
        $recipe->setRecipePath($recipeFile);
        
        $moodlePath = '/var/www/html';
        $this->moodleService->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn($moodlePath);
        
        $this->moodleService->method('shouldUsePublicFolder')
            ->with($recipe)
            ->willReturn(false);
        
        $categoryMapJson = '{"Category 1": 1}';
        $this->dockerService->method('copyFileToContainer');
        
        $this->dockerService->expects($this->once())
            ->method('executeInContainerWithEnv')
            ->willReturn([$categoryMapJson, 0]);
        
        $this->dockerService->expects($this->once())
            ->method('downloadFileInContainer')
            ->with('test-container', 'https://example.com/course.mbz', $this->stringContains('/tmp/'));
        
        $this->dockerService->expects($this->once())
            ->method('executePhpScriptPassthru')
            ->willThrowException(new \Exception('Script execution failed'));
        
        $this->cli->method('info')->willReturn(null);
        $this->cli->method('notice');
        $this->cli->method('success');
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Script execution failed');
        $this->restoreDataService->processRestoreStructure($recipe, 'test-container');
        
        unlink($recipeFile);
    }
}

