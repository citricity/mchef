<?php

namespace App\Tests;

use App\Service\Environment;
use App\Tests\MchefTestCase;

class EnvironmentTest extends MchefTestCase {

    private Environment $environment;

    protected function setUp(): void {
        parent::setUp();
        $this->environment = Environment::instance();
    }

    public function testGetReturnsEnvironmentVariable(): void {
        // Set a test environment variable
        putenv('TEST_MCHEF_VAR=test_value');
        
        $result = $this->environment->get('TEST_MCHEF_VAR');
        $this->assertEquals('test_value', $result);
        
        // Clean up
        putenv('TEST_MCHEF_VAR');
    }

    public function testGetReturnsDefaultWhenVariableNotSet(): void {
        $result = $this->environment->get('NONEXISTENT_VAR', 'default_value');
        $this->assertEquals('default_value', $result);
    }

    public function testGetReturnsNullWhenVariableNotSetAndNoDefault(): void {
        $result = $this->environment->get('NONEXISTENT_VAR');
        $this->assertNull($result);
    }

    public function testHasReturnsTrueWhenVariableExists(): void {
        putenv('TEST_MCHEF_HAS=some_value');
        
        $result = $this->environment->has('TEST_MCHEF_HAS');
        $this->assertTrue($result);
        
        // Clean up
        putenv('TEST_MCHEF_HAS');
    }

    public function testHasReturnsFalseWhenVariableDoesNotExist(): void {
        $result = $this->environment->has('NONEXISTENT_VAR');
        $this->assertFalse($result);
    }

    public function testHasReturnsFalseWhenVariableIsEmpty(): void {
        putenv('TEST_MCHEF_EMPTY=');
        
        $result = $this->environment->has('TEST_MCHEF_EMPTY');
        $this->assertFalse($result);
        
        // Clean up
        putenv('TEST_MCHEF_EMPTY');
    }

    public function testGetMultipleReturnsArrayOfValues(): void {
        putenv('TEST_VAR1=value1');
        putenv('TEST_VAR2=value2');
        
        $result = $this->environment->getMultiple(['TEST_VAR1', 'TEST_VAR2', 'NONEXISTENT']);
        
        $this->assertEquals([
            'TEST_VAR1' => 'value1',
            'TEST_VAR2' => 'value2',
            'NONEXISTENT' => null
        ], $result);
        
        // Clean up
        putenv('TEST_VAR1');
        putenv('TEST_VAR2');
    }

    public function testGetRegistryConfig(): void {
        putenv('MCHEF_REGISTRY_URL=testurl');
        putenv('MCHEF_REGISTRY_USERNAME=testusername');
        putenv('MCHEF_REGISTRY_PASSWORD=testpassword');
        putenv('MCHEF_REGISTRY_TOKEN=testtoken');

        $result = $this->environment->getRegistryConfig();

        $this->assertEquals([
            'url' => 'testurl',
            'username' => 'testusername',
            'password' => 'testpassword',
            'token' => 'testtoken',
        ], $result);
        
        // Clean up
        putenv('MCHEF_REGISTRY_URL');
        putenv('MCHEF_REGISTRY_USERNAME');
        putenv('MCHEF_REGISTRY_PASSWORD');
        putenv('MCHEF_REGISTRY_TOKEN');
    }

    public function testGetRegistryConfigMissing(): void {
        $result = $this->environment->getRegistryConfig();

        $this->assertNull($result);
    }
}
