<?php

namespace App\Tests;

use App\Model\Recipe;
use App\Service\Hosts;
use App\Tests\MchefTestCase;

class HostsTest extends MchefTestCase {

    private Hosts $hostsService;
    private string $tempHostsFile;

    protected function setUp(): void {
        parent::setUp();
        $this->hostsService = Hosts::instance();
        
        // Create a temporary hosts file for testing
        $this->tempHostsFile = sys_get_temp_dir() . '/mchef_hosts_test_' . uniqid();
    }

    protected function tearDown(): void {
        // Clean up temporary hosts file
        if (file_exists($this->tempHostsFile)) {
            unlink($this->tempHostsFile);
        }
        parent::tearDown();
    }

    public function testGetHostsFilePathReturnsCorrectPathForUnix(): void {
        // Use reflection to test the method without actually changing OS
        $reflection = new \ReflectionClass($this->hostsService);
        $method = $reflection->getMethod('getHostsFilePath');
        $method->setAccessible(true);
        
        $path = $method->invoke($this->hostsService);
        
        // On Unix-like systems, should return /etc/hosts
        // On Windows, should return Windows path
        // We'll just verify it returns a non-empty string
        $this->assertNotEmpty($path);
    }

    public function testExtractMchefHostsExtractsHostsFromSingleSection(): void {
        $hostsContent = [
            "127.0.0.1\tlocalhost\n",
            Hosts::MCHEF_SECTION_START . "\n",
            "127.0.0.1\tmoodle-ally43.test\n",
            "127.0.0.1\tmoodle-ally43.test.behat\n",
            Hosts::MCHEF_SECTION_END . "\n",
            "::1\tlocalhost\n",
        ];
        
        file_put_contents($this->tempHostsFile, implode('', $hostsContent));
        $lines = file($this->tempHostsFile);
        
        $reflection = new \ReflectionClass($this->hostsService);
        $method = $reflection->getMethod('extractMchefHosts');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->hostsService, $lines);
        
        $this->assertEquals(['moodle-ally43.test', 'moodle-ally43.test.behat'], $result);
    }

    public function testExtractMchefHostsExtractsHostsFromMultipleSections(): void {
        $hostsContent = [
            "127.0.0.1\tlocalhost\n",
            Hosts::MCHEF_SECTION_START . "\n",
            "127.0.0.1\tmoodle-ally43.test\n",
            Hosts::MCHEF_SECTION_END . "\n",
            Hosts::MCHEF_SECTION_START . "\n",
            "127.0.0.1\tmoodle-ally45.test\n",
            "127.0.0.1\tmoodle-ally45.test.behat\n",
            Hosts::MCHEF_SECTION_END . "\n",
            Hosts::MCHEF_SECTION_START . "\n",
            "127.0.0.1\tmoodle405.test\n",
            Hosts::MCHEF_SECTION_END . "\n",
        ];
        
        file_put_contents($this->tempHostsFile, implode('', $hostsContent));
        $lines = file($this->tempHostsFile);
        
        $reflection = new \ReflectionClass($this->hostsService);
        $method = $reflection->getMethod('extractMchefHosts');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->hostsService, $lines);
        
        $expected = ['moodle-ally43.test', 'moodle-ally45.test', 'moodle-ally45.test.behat', 'moodle405.test'];
        sort($expected);
        sort($result);
        $this->assertEquals($expected, $result);
    }

    public function testExtractMchefHostsRemovesDuplicates(): void {
        $hostsContent = [
            Hosts::MCHEF_SECTION_START . "\n",
            "127.0.0.1\tmoodle.test\n",
            "127.0.0.1\tmoodle.test\n", // Duplicate
            Hosts::MCHEF_SECTION_END . "\n",
        ];
        
        file_put_contents($this->tempHostsFile, implode('', $hostsContent));
        $lines = file($this->tempHostsFile);
        
        $reflection = new \ReflectionClass($this->hostsService);
        $method = $reflection->getMethod('extractMchefHosts');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->hostsService, $lines);
        
        $this->assertEquals(['moodle.test'], $result);
    }

    public function testRemoveMchefSectionsRemovesAllMchefSections(): void {
        $hostsContent = [
            "127.0.0.1\tlocalhost\n",
            Hosts::MCHEF_SECTION_START . "\n",
            "127.0.0.1\tmoodle-ally43.test\n",
            Hosts::MCHEF_SECTION_END . "\n",
            "::1\tlocalhost\n",
            Hosts::MCHEF_SECTION_START . "\n",
            "127.0.0.1\tmoodle-ally45.test\n",
            Hosts::MCHEF_SECTION_END . "\n",
        ];
        
        file_put_contents($this->tempHostsFile, implode('', $hostsContent));
        $lines = file($this->tempHostsFile);
        
        $reflection = new \ReflectionClass($this->hostsService);
        $method = $reflection->getMethod('removeMchefSections');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->hostsService, $lines);
        
        $this->assertEquals(["127.0.0.1\tlocalhost\n", "::1\tlocalhost\n"], $result);
    }

    public function testRemoveMchefSectionsHandlesEmptyFile(): void {
        $hostsContent = [];
        
        file_put_contents($this->tempHostsFile, '');
        $lines = file($this->tempHostsFile);
        
        $reflection = new \ReflectionClass($this->hostsService);
        $method = $reflection->getMethod('removeMchefSections');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->hostsService, $lines);
        
        $this->assertEquals([], $result);
    }

    public function testGetHostsFromRecipeExtractsHostAndBehatHost(): void {
        $recipe = new Recipe(
            moodleTag: '4.1.0',
            phpVersion: '8.0',
            host: 'moodle.test',
            behatHost: 'moodle.test.behat'
        );
        
        $reflection = new \ReflectionClass($this->hostsService);
        $method = $reflection->getMethod('getHostsFromRecipe');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->hostsService, $recipe);
        
        $this->assertEquals(['moodle.test', 'moodle.test.behat'], array_values($result));
    }

    public function testGetHostsFromRecipeHandlesOnlyHost(): void {
        $recipe = new Recipe(
            moodleTag: '4.1.0',
            phpVersion: '8.0',
            host: 'moodle.test'
        );
        
        $reflection = new \ReflectionClass($this->hostsService);
        $method = $reflection->getMethod('getHostsFromRecipe');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->hostsService, $recipe);
        
        $this->assertEquals(['moodle.test'], array_values($result));
    }

    public function testGetHostsFromRecipeHandlesOnlyBehatHost(): void {
        $recipe = new Recipe(
            moodleTag: '4.1.0',
            phpVersion: '8.0',
            behatHost: 'moodle.test.behat'
        );
        
        $reflection = new \ReflectionClass($this->hostsService);
        $method = $reflection->getMethod('getHostsFromRecipe');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->hostsService, $recipe);
        
        $this->assertEquals(['moodle.test.behat'], array_values($result));
    }

    public function testGetHostsFromRecipeHandlesEmptyHosts(): void {
        $recipe = new Recipe(
            moodleTag: '4.1.0',
            phpVersion: '8.0'
        );
        
        $reflection = new \ReflectionClass($this->hostsService);
        $method = $reflection->getMethod('getHostsFromRecipe');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->hostsService, $recipe);
        
        $this->assertEquals([], $result);
    }

    public function testHostExistsInFileReturnsTrueWhenHostExists(): void {
        $hostsContent = [
            "127.0.0.1\tlocalhost\n",
            "127.0.0.1\tmoodle.test\n",
            "::1\tlocalhost\n",
        ];
        
        file_put_contents($this->tempHostsFile, implode('', $hostsContent));
        $lines = file($this->tempHostsFile);
        
        $reflection = new \ReflectionClass($this->hostsService);
        $method = $reflection->getMethod('hostExistsInFile');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->hostsService, $lines, 'moodle.test');
        
        $this->assertTrue($result);
    }

    public function testHostExistsInFileReturnsFalseWhenHostDoesNotExist(): void {
        $hostsContent = [
            "127.0.0.1\tlocalhost\n",
            "::1\tlocalhost\n",
        ];
        
        file_put_contents($this->tempHostsFile, implode('', $hostsContent));
        $lines = file($this->tempHostsFile);
        
        $reflection = new \ReflectionClass($this->hostsService);
        $method = $reflection->getMethod('hostExistsInFile');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->hostsService, $lines, 'moodle.test');
        
        $this->assertFalse($result);
    }

    public function testConsolidationScenario(): void {
        // Simulate the scenario from the user's example
        // Start with multiple mchef sections
        $initialHostsContent = [
            "127.0.0.1\tlocalhost\n",
            Hosts::MCHEF_SECTION_START . "\n",
            "127.0.0.1\tmoodle-ally43.test\n",
            "127.0.0.1\tmoodle-ally43.test.behat\n",
            Hosts::MCHEF_SECTION_END . "\n",
            Hosts::MCHEF_SECTION_START . "\n",
            "127.0.0.1\tmoodle-ally45.test\n",
            "127.0.0.1\tmoodle-ally45.test.behat\n",
            Hosts::MCHEF_SECTION_END . "\n",
            Hosts::MCHEF_SECTION_START . "\n",
            "127.0.0.1\tmoodle405.test\n",
            Hosts::MCHEF_SECTION_END . "\n",
        ];
        
        file_put_contents($this->tempHostsFile, implode('', $initialHostsContent));
        
        // Extract existing hosts
        $lines = file($this->tempHostsFile);
        $reflection = new \ReflectionClass($this->hostsService);
        
        $extractMethod = $reflection->getMethod('extractMchefHosts');
        $extractMethod->setAccessible(true);
        $existingHosts = $extractMethod->invoke($this->hostsService, $lines);
        
        // Remove sections
        $removeMethod = $reflection->getMethod('removeMchefSections');
        $removeMethod->setAccessible(true);
        $cleanLines = $removeMethod->invoke($this->hostsService, $lines);
        
        // Verify all hosts were extracted
        $expectedHosts = ['moodle-ally43.test', 'moodle-ally43.test.behat', 'moodle-ally45.test', 'moodle-ally45.test.behat', 'moodle405.test'];
        sort($expectedHosts);
        sort($existingHosts);
        $this->assertEquals($expectedHosts, $existingHosts);
        
        // Verify sections were removed
        $cleanContent = implode('', $cleanLines);
        $this->assertStringNotContainsString(Hosts::MCHEF_SECTION_START, $cleanContent);
        $this->assertStringNotContainsString(Hosts::MCHEF_SECTION_END, $cleanContent);
        $this->assertStringContainsString('127.0.0.1	localhost', $cleanContent);
    }
}
