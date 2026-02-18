<?php

namespace App\Tests;

use App\Command\ListAll;
use App\Command\UseCmd;
use App\Helpers\SplitbrainWrapper;
use App\Helpers\TestingHelpers;
use App\Service\TermsService;
use App\Service\Configurator;
use App\MChefCLI;
use App\StaticVars;
use PHPUnit\Framework\MockObject\MockObject;
use splitbrain\phpcli\Options;

/**
 * Test helper class to access protected methods
 */
class TestableMChefCLI extends MChefCLI {
    public function callMain(Options $options): void {
        $this->main($options);
    }
}

/**
 * Integration test to verify that commands cannot be executed without terms agreement
 * and that they work properly after agreement
 */
class TermsIntegrationTest extends \PHPUnit\Framework\TestCase {
    
    private MockObject $mockCli;
    private TermsService $termsService;
    private MockObject $mockOptions;
    
    protected function setUp(): void {
        parent::setUp();
        
        TestingHelpers::setIsPHPUnit(true);
        
        // Create mock CLI without auto-creating terms agreement
        $this->mockCli = $this->createMock(MChefCLI::class);
        StaticVars::$cli = $this->mockCli;
        
        // Create mock options with suppressed warnings
        $this->mockOptions = SplitbrainWrapper::suppressDeprecationWarnings(function() {
            return $this->createMock(Options::class);
        });
        
        // Reset singleton and force terms checking for integration tests
        TermsService::resetInstance();
        TermsService::forceTermsCheck(true);
        TermsService::resetJustAgreedFlag();
        
        $this->termsService = TermsService::instance();
        
        // Ensure clean state
        $this->removeExistingTermsFile();
    }
    
    protected function tearDown(): void {
        // Reset force terms check
        TermsService::forceTermsCheck(false);
        TestingHelpers::deleteTestDir();
        parent::tearDown();
    }
    
    private function removeExistingTermsFile(): void {
        $configurator = Configurator::instance();
        $termsFile = $configurator->configDir() . '/TERMSAGREED.txt';
        if (file_exists($termsFile)) {
            unlink($termsFile);
        }
    }
    
    public function testListAllCommandRequiresTermsAgreement(): void {
        $this->assertFalse($this->termsService->hasAgreedToTerms(), 'Terms should not be agreed initially');
        
        // Create a testable CLI instance
        $cli = new TestableMChefCLI(false);
        
        // Mock options for ListAll command
        $this->mockOptions->method('getCmd')->willReturn('listall');
        $this->mockOptions->method('getOpt')->willReturn(false);
        $this->mockOptions->method('getArgs')->willReturn([]);
        
        // Expect TermsNotAgreedException to be thrown
        $this->expectException(\App\Exceptions\TermsNotAgreedException::class);
        
        // This should throw TermsNotAgreedException because terms are not agreed
        $cli->callMain($this->mockOptions);
    }
    
    public function testUseCmdCommandRequiresTermsAgreement(): void {
        $this->assertFalse($this->termsService->hasAgreedToTerms(), 'Terms should not be agreed initially');
        
        // Create a testable CLI instance
        $cli = new TestableMChefCLI(false);
        
        // Mock options for UseCmd command
        $this->mockOptions->method('getCmd')->willReturn('use');
        $this->mockOptions->method('getOpt')->willReturn(false);
        $this->mockOptions->method('getArgs')->willReturn(['test-instance']);
        
        // Expect TermsNotAgreedException to be thrown
        $this->expectException(\App\Exceptions\TermsNotAgreedException::class);
        
        // This should throw TermsNotAgreedException because terms are not agreed
        $cli->callMain($this->mockOptions);
    }
    
    public function testCommandsWorkAfterTermsAgreement(): void {
        // Create terms agreement first
        $this->termsService->createTermsAgreementForTesting();
        $this->assertTrue($this->termsService->hasAgreedToTerms(), 'Terms should be agreed');
        
        // Create a testable CLI instance 
        $cli = new TestableMChefCLI(false);
        
        // Mock options for ListAll command (which should now work)
        $this->mockOptions->method('getCmd')->willReturn('listall');
        $this->mockOptions->method('getOpt')->willReturn(false);
        $this->mockOptions->method('getArgs')->willReturn([]);
        
        // This should not throw an exception because terms are agreed
        try {
            $cli->callMain($this->mockOptions);
        } catch (\App\Exceptions\TermsNotAgreedException $e) {
            $this->fail('TermsNotAgreedException should not be thrown when terms are agreed');
        } catch (\Exception $e) {
            // Other exceptions are fine - we just want to ensure it's not TermsNotAgreedException
            // The command may fail for other reasons (missing dependencies, etc.) but that's not what we're testing
        }
        
        // If we get here without TermsNotAgreedException, the test passes
        $this->assertTrue(true, 'Command execution proceeded past terms check');
    }
    
    public function testTermsFileContainsCorrectData(): void {
        $this->termsService->createTermsAgreementForTesting();
        
        $configurator = Configurator::instance();
        $termsFile = $configurator->configDir() . '/TERMSAGREED.txt';
        
        $this->assertTrue(file_exists($termsFile), 'Terms file should exist');
        
        $content = file_get_contents($termsFile);
        $this->assertStringContainsString('Terms agreed on:', $content, 'Terms file should contain agreement timestamp');
        $this->assertStringContainsString('User:', $content, 'Terms file should contain user information');
    }
}