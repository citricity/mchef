<?php

namespace App\Tests;

use App\Constants\SampleDataSize;
use App\Model\Recipe;
use App\Model\SampleData as SampleDataModel;
use App\Service\Moodle;
use App\Service\SampleData;
use App\Traits\CallRestrictedMethodTrait;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test double for SampleData that tracks execPassthru calls
 */
class TestableSampleData extends SampleData {
    public array $execPassthruCalls = [];
    
    protected function execPassthru(string $cmd, ?string $errorMsg = null): void {
        $this->execPassthruCalls[] = [
            'cmd' => $cmd,
            'errorMsg' => $errorMsg
        ];
        // Don't actually execute - just track the call
    }
}

final class SampleDataTest extends MchefTestCase {
    use CallRestrictedMethodTrait;

    private TestableSampleData $sampleDataService;
    private MockObject $moodleService;

    protected function setUp(): void {
        parent::setUp();
        
        // Use reflection to reset and get instance
        $reflection = new \ReflectionClass(TestableSampleData::class);
        $method = $reflection->getMethod('setup_singleton');
        
        // Create instance with reset to clear any previous singleton
        $this->sampleDataService = $method->invoke(null, true);
        $this->moodleService = $this->createMock(Moodle::class);
        
        // Inject mocked Moodle service directly using reflection
        // Need to get property from parent class SampleData
        $parentReflection = new \ReflectionClass(SampleData::class);
        $property = $parentReflection->getProperty('moodleService');
        $property->setValue($this->sampleDataService, $this->moodleService);
        
        // Reset call log
        $this->sampleDataService->execPassthruCalls = [];
    }
    
    protected function tearDown(): void {
        // Reset singleton after each test
        $reflection = new \ReflectionClass(TestableSampleData::class);
        $method = $reflection->getMethod('setup_singleton');
        $method->invoke(null, true);
        
        parent::tearDown();
    }

    public function testGenerateSampleDataWithEmptySampleData(): void {
        $recipe = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            sampleData: null
        );

        // Should return early without calling any methods
        $this->moodleService->expects($this->never())
            ->method('getDockerMoodlePath');

        $this->sampleDataService->generateSampleData($recipe, 'test-container');
    }

    public function testGenerateSampleDataSiteModeDefaultSize(): void {
        $recipe = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            sampleData: new SampleDataModel(
                mode: 'site'
            )
        );

        $this->moodleService->expects($this->once())
            ->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn('/var/www/html/moodle');

        $this->cli->expects($this->atLeastOnce())
            ->method('notice');
        $this->cli->expects($this->once())
            ->method('success')
            ->with('Sample data generation completed.');

        $this->sampleDataService->generateSampleData($recipe, 'test-container');
        
        // Verify execPassthru was called with correct parameters
        $this->assertExecPassthruCalled(
            'test-container',
            '/var/www/html/moodle',
            'admin/tool/generator/cli/maketestsite.php',
            ['--size=M']
        );
    }

    public function testGenerateSampleDataSiteModeWithSize(): void {
        $recipe = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            sampleData: new SampleDataModel(
                mode: 'site',
                size: 'L'
            )
        );

        $this->moodleService->expects($this->once())
            ->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn('/var/www/html/moodle');

        $this->sampleDataService->generateSampleData($recipe, 'test-container');
        
        $this->assertExecPassthruCalled(
            'test-container',
            '/var/www/html/moodle',
            'admin/tool/generator/cli/maketestsite.php',
            ['--size=L']
        );
    }

    public function testGenerateSampleDataSiteModeWithFixedDataset(): void {
        $recipe = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            sampleData: new SampleDataModel(
                mode: 'site',
                size: 'S',
                fixeddataset: true
            )
        );

        $this->moodleService->expects($this->once())
            ->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn('/var/www/html/moodle');

        $this->sampleDataService->generateSampleData($recipe, 'test-container');
        
        $this->assertExecPassthruCalled(
            'test-container',
            '/var/www/html/moodle',
            'admin/tool/generator/cli/maketestsite.php',
            ['--size=S', '--fixeddataset']
        );
    }

    public function testGenerateSampleDataSiteModeWithFileSizeLimit(): void {
        $recipe = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            sampleData: new SampleDataModel(
                mode: 'site',
                size: 'M',
                filesizelimit: 1048576
            )
        );

        $this->moodleService->expects($this->once())
            ->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn('/var/www/html/moodle');

        $this->sampleDataService->generateSampleData($recipe, 'test-container');
        
        $this->assertExecPassthruCalled(
            'test-container',
            '/var/www/html/moodle',
            'admin/tool/generator/cli/maketestsite.php',
            ['--size=M', '--filesizelimit=1048576']
        );
    }

    public function testGenerateSampleDataSiteModeWithAllOptions(): void {
        $recipe = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            sampleData: new SampleDataModel(
                mode: 'site',
                size: 'XL',
                fixeddataset: true,
                filesizelimit: 2048576
            )
        );

        $this->moodleService->expects($this->once())
            ->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn('/var/www/html/moodle');

        $this->sampleDataService->generateSampleData($recipe, 'test-container');
        
        $this->assertExecPassthruCalled(
            'test-container',
            '/var/www/html/moodle',
            'admin/tool/generator/cli/maketestsite.php',
            ['--size=XL', '--fixeddataset', '--filesizelimit=2048576']
        );
    }

    public function testGenerateSampleDataCourseModeDefault(): void {
        $recipe = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            sampleData: new SampleDataModel(
                mode: 'course'
            )
        );

        $this->moodleService->expects($this->once())
            ->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn('/var/www/html/moodle');

        $this->sampleDataService->generateSampleData($recipe, 'test-container');
        
        // Should generate 10 courses by default
        $this->assertExecPassthruCalledMultiple(
            'test-container',
            '/var/www/html/moodle',
            'admin/tool/generator/cli/maketestcourse.php',
            10,
            function ($index) {
                return [
                    '--shortname=testcourse_' . $index,
                    '--size=M'
                ];
            }
        );
    }

    public function testGenerateSampleDataCourseModeWithCustomCount(): void {
        $recipe = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            sampleData: new SampleDataModel(
                mode: 'course',
                courses: 5,
                size: 'S'
            )
        );

        $this->moodleService->expects($this->once())
            ->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn('/var/www/html/moodle');

        $this->sampleDataService->generateSampleData($recipe, 'test-container');
        
        // Should generate 5 courses
        $this->assertExecPassthruCalledMultiple(
            'test-container',
            '/var/www/html/moodle',
            'admin/tool/generator/cli/maketestcourse.php',
            5,
            function ($index) {
                return [
                    '--shortname=testcourse_' . $index,
                    '--size=S'
                ];
            }
        );
    }

    public function testGenerateSampleDataCourseModeWithAdditionalModules(): void {
        $recipe = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            sampleData: new SampleDataModel(
                mode: 'course',
                courses: 3,
                size: 'M',
                additionalmodules: ['quiz', 'forum']
            )
        );

        $this->moodleService->expects($this->once())
            ->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn('/var/www/html/moodle');

        $this->sampleDataService->generateSampleData($recipe, 'test-container');
        
        // Should generate 3 courses with additional modules
        $this->assertExecPassthruCalledMultiple(
            'test-container',
            '/var/www/html/moodle',
            'admin/tool/generator/cli/maketestcourse.php',
            3,
            function ($index) {
                return [
                    '--shortname=testcourse_' . $index,
                    '--size=M',
                    '--additionalmodules=quiz,forum'
                ];
            }
        );
    }

    public function testGenerateSampleDataCourseModeWithAllOptions(): void {
        $recipe = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            sampleData: new SampleDataModel(
                mode: 'course',
                courses: 2,
                size: 'L',
                fixeddataset: true,
                filesizelimit: 524288,
                additionalmodules: ['assign', 'quiz']
            )
        );

        $this->moodleService->expects($this->once())
            ->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn('/var/www/html/moodle');

        $this->sampleDataService->generateSampleData($recipe, 'test-container');
        
        // Should generate 2 courses with all options
        $this->assertExecPassthruCalledMultiple(
            'test-container',
            '/var/www/html/moodle',
            'admin/tool/generator/cli/maketestcourse.php',
            2,
            function ($index) {
                return [
                    '--shortname=testcourse_' . $index,
                    '--size=L',
                    '--fixeddataset',
                    '--filesizelimit=524288',
                    '--additionalmodules=assign,quiz'
                ];
            }
        );
    }

    public function testGenerateSampleDataAutoDetectsCourseMode(): void {
        // When courses is specified but mode is not, should default to course mode
        $recipe = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            sampleData: new SampleDataModel(
                courses: 3
            )
        );

        $this->moodleService->expects($this->once())
            ->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn('/var/www/html/moodle');

        $this->sampleDataService->generateSampleData($recipe, 'test-container');
        
        // Should generate courses, not site
        $this->assertExecPassthruCalledMultiple(
            'test-container',
            '/var/www/html/moodle',
            'admin/tool/generator/cli/maketestcourse.php',
            3,
            function ($index) {
                return [
                    '--shortname=testcourse_' . $index,
                    '--size=M'
                ];
            }
        );
    }

    public function testNormalizeConfigurationWithInvalidSize(): void {
        $sampleData = new SampleDataModel(
            mode: 'site',
            size: 'INVALID_SIZE'
        );

        $recipe = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            sampleData: $sampleData
        );

        $this->moodleService->expects($this->once())
            ->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn('/var/www/html/moodle');

        $this->cli->expects($this->once())
            ->method('warning')
            ->with($this->stringContains("Invalid size value 'INVALID_SIZE', defaulting to M"));

        $this->sampleDataService->generateSampleData($recipe, 'test-container');
        
        // Should default to M
        $this->assertExecPassthruCalled(
            'test-container',
            '/var/www/html/moodle',
            'admin/tool/generator/cli/maketestsite.php',
            ['--size=M']
        );

        // Verify size was normalized
        $this->assertEquals('M', $sampleData->size);
    }

    public function testNormalizeConfigurationWithValidSize(): void {
        $sampleData = new SampleDataModel(
            mode: 'site',
            size: 'xs'  // lowercase should be normalized
        );

        $recipe = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            sampleData: $sampleData
        );

        $this->moodleService->expects($this->once())
            ->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn('/var/www/html/moodle');

        $this->sampleDataService->generateSampleData($recipe, 'test-container');
        
        // Should normalize to uppercase
        $this->assertExecPassthruCalled(
            'test-container',
            '/var/www/html/moodle',
            'admin/tool/generator/cli/maketestsite.php',
            ['--size=XS']
        );

        // Verify size was normalized to uppercase
        $this->assertEquals('XS', $sampleData->size);
    }

    public function testNormalizeConfigurationDefaultsToSiteMode(): void {
        // When neither mode nor courses is specified, should default to site mode
        $recipe = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            sampleData: new SampleDataModel()
        );

        $this->moodleService->expects($this->once())
            ->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn('/var/www/html/moodle');

        $this->sampleDataService->generateSampleData($recipe, 'test-container');
        
        // Should use site mode
        $this->assertExecPassthruCalled(
            'test-container',
            '/var/www/html/moodle',
            'admin/tool/generator/cli/maketestsite.php',
            ['--size=M']
        );
    }

    public function testNormalizeConfigurationDefaultsSize(): void {
        // When size is not specified, should default to M
        $recipe = new Recipe(
            moodleTag: 'v4.1.0',
            phpVersion: '8.0',
            sampleData: new SampleDataModel(
                mode: 'site'
            )
        );

        $this->moodleService->expects($this->once())
            ->method('getDockerMoodlePath')
            ->with($recipe)
            ->willReturn('/var/www/html/moodle');

        $this->sampleDataService->generateSampleData($recipe, 'test-container');
        
        // Should default to M
        $this->assertExecPassthruCalled(
            'test-container',
            '/var/www/html/moodle',
            'admin/tool/generator/cli/maketestsite.php',
            ['--size=M']
        );
    }

    public function testAllValidSizes(): void {
        $sizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
        
        foreach ($sizes as $size) {
            // Reset call log for each iteration
            $this->sampleDataService->execPassthruCalls = [];
            
            $recipe = new Recipe(
                moodleTag: 'v4.1.0',
                phpVersion: '8.0',
                sampleData: new SampleDataModel(
                    mode: 'site',
                    size: strtolower($size)  // Test case normalization
                )
            );

            $moodleService = $this->createMock(Moodle::class);
            $moodleService->expects($this->once())
                ->method('getDockerMoodlePath')
                ->with($recipe)
                ->willReturn('/var/www/html/moodle');
            
            // Inject the new mock using reflection
            $parentReflection = new \ReflectionClass(SampleData::class);
            $property = $parentReflection->getProperty('moodleService');
            $property->setValue($this->sampleDataService, $moodleService);

            $this->sampleDataService->generateSampleData($recipe, 'test-container');
            
            $this->assertExecPassthruCalled(
                'test-container',
                '/var/www/html/moodle',
                'admin/tool/generator/cli/maketestsite.php',
                ['--size=' . $size]
            );
        }
    }

    /**
     * Verify that executeMoodleCli was called with correct parameters
     * by checking the execPassthru call log
     */
    private function assertExecPassthruCalled(
        string $expectedContainer,
        string $expectedMoodlePath,
        string $expectedScriptPath,
        array $expectedArgs
    ): void {
        $calls = $this->sampleDataService->execPassthruCalls;
        $this->assertNotEmpty($calls, "execPassthru should have been called");
        
        $call = $calls[0]; // Get the first (or only) call
        $cmd = $call['cmd'];
        
        // Verify the command contains expected parts
        $this->assertStringContainsString("docker exec", $cmd);
        $this->assertStringContainsString($expectedContainer, $cmd);
        $this->assertStringContainsString($expectedMoodlePath, $cmd);
        $this->assertStringContainsString($expectedScriptPath, $cmd);
        
        // Verify all expected args are in the command
        foreach ($expectedArgs as $arg) {
            $this->assertStringContainsString($arg, $cmd, "Expected argument '{$arg}' not found in command: {$cmd}");
        }
    }

    /**
     * Verify multiple executeMoodleCli calls for course generation
     */
    private function assertExecPassthruCalledMultiple(
        string $expectedContainer,
        string $expectedMoodlePath,
        string $expectedScriptPath,
        int $expectedCount,
        callable $argsGenerator
    ): void {
        $calls = $this->sampleDataService->execPassthruCalls;
        $this->assertCount($expectedCount, $calls, "execPassthru should have been called {$expectedCount} times");
        
        for ($i = 0; $i < $expectedCount; $i++) {
            $call = $calls[$i];
            $cmd = $call['cmd'];
            $expectedArgs = $argsGenerator($i + 1); // Course numbers start at 1
            
            // Verify the command contains expected parts
            $this->assertStringContainsString("docker exec", $cmd);
            $this->assertStringContainsString($expectedContainer, $cmd);
            $this->assertStringContainsString($expectedMoodlePath, $cmd);
            $this->assertStringContainsString($expectedScriptPath, $cmd);
            
            // Verify all expected args are in the command
            foreach ($expectedArgs as $arg) {
                $this->assertStringContainsString($arg, $cmd, "Expected argument '{$arg}' not found in command {$i}: {$cmd}");
            }
        }
    }
}
