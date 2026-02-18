<?php

namespace App\Tests;

use App\Command\ListAll;
use App\Command\UseCmd;
use App\Helpers\TestingHelpers;
use App\Service\TermsService;
use App\Service\Configurator;
use App\MChefCLI;
use App\StaticVars;
use PHPUnit\Framework\MockObject\MockObject;
use splitbrain\phpcli\Options;

/**
 * Integration test to verify that commands cannot be executed without terms agreement
 * and that they work properly after agreement
 */
class TermsIntegrationTest extends \PHPUnit\Framework\TestCase {
    
    private MockObject $mockCli;
    private TermsService $termsService;
    
    protected function setUp(): void {
        parent::setUp();
        
        TestingHelpers::setIsPHPUnit(true);
        
        // Create mock CLI without auto-creating terms agreement
        $this->mockCli = $this->createMock(MChefCLI::class);
        StaticVars::$cli = $this->mockCli;
        
        // Reset singleton and force terms checking for integration tests
        TermsService::resetInstance();
        TermsService::forceTermsCheck(true);
        TermsService::resetJustAgreedFlag();
        
        $this->termsService = TermsService::instance($this->mockCli);
        
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
    
    public function testTermsServiceBehavesCorrectlyWhenForced(): void {
        $this->assertFalse($this->termsService->hasAgreedToTerms(), 'Terms should not be agreed initially');
        
        // Mock the CLI to simulate user declining terms
        $this->mockCli->expects($this->once())
            ->method('info')
            ->with($this->stringContains('MChef is provided "as is"'));
            
        $this->mockCli->expects($this->once())
            ->method('promptYesNo')
            ->willReturn(false);
        
        $this->mockCli->expects($this->once())
            ->method('error')
            ->with('You must agree to the terms to use MChef.');
        
        // Ensure terms agreement fails
        $result = $this->termsService->ensureTermsAgreement();
        $this->assertFalse($result, 'Terms agreement should fail when user says no');
    }
    
    public function testTermsServiceWorksAfterAgreement(): void {
        // Create terms agreement
        $this->termsService->createTermsAgreementForTesting();
        $this->assertTrue($this->termsService->hasAgreedToTerms(), 'Terms should be agreed');
        
        // Should not prompt when terms are already agreed
        $this->mockCli->expects($this->never())
            ->method('promptYesNo');
        
        // Ensure terms agreement succeeds
        $result = $this->termsService->ensureTermsAgreement();
        $this->assertTrue($result, 'Terms agreement should succeed when terms file exists');
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