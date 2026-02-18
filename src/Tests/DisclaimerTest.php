<?php

namespace App\Tests;

use App\Helpers\TestingHelpers;
use App\Service\Configurator;
use App\Service\TermsService;
use App\MChefCLI;
use App\StaticVars;
use PHPUnit\Framework\MockObject\MockObject;
use splitbrain\phpcli\Options;

class DisclaimerTest extends \PHPUnit\Framework\TestCase {
    
    private MockObject $mockCli;
    private TermsService $termsService;
    
    protected function setUp(): void {
        parent::setUp();
        
        TestingHelpers::setIsPHPUnit(true);
        
        // Create mock CLI without auto-creating terms agreement
        $this->mockCli = $this->createMock(MChefCLI::class);
        StaticVars::$cli = $this->mockCli;
        
        // Reset singleton and force terms checking for this test
        TermsService::resetInstance();
        TermsService::forceTermsCheck(true);
        TermsService::resetJustAgreedFlag();
        
        $this->termsService = TermsService::instance();
        
        // Ensure clean state by removing any existing terms file
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
    
    public function testTermsNotAgreedInitially(): void {
        $this->assertFalse($this->termsService->hasAgreedToTerms());
    }
    
    public function testTermsAgreementCreatesFile(): void {
        $this->termsService->createTermsAgreementForTesting();
        $this->assertTrue($this->termsService->hasAgreedToTerms());
    }
    
    public function testPromptForTermsAgreementWithYes(): void {
        $this->mockCli->expects($this->once())
            ->method('info')
            ->with($this->stringContains('MChef is provided "as is"'));
            
        $this->mockCli->expects($this->once())
            ->method('promptYesNo')
            ->with(
                $this->stringContains('Do you agree to these terms?'),
                null,
                null,
                'n'
            )
            ->willReturn(true);
            
        $this->mockCli->expects($this->once())
            ->method('success')
            ->with('Thank you for agreeing to the terms.');
        
        $result = $this->termsService->promptForTermsAgreement();
        
        $this->assertTrue($result);
        $this->assertTrue($this->termsService->hasAgreedToTerms());
        $this->assertTrue($this->termsService->wereTermsJustAgreed());
    }
    
    public function testPromptForTermsAgreementWithNo(): void {
        $this->mockCli->expects($this->once())
            ->method('info')
            ->with($this->stringContains('MChef is provided "as is"'));
            
        $this->mockCli->expects($this->once())
            ->method('promptYesNo')
            ->with(
                $this->stringContains('Do you agree to these terms?'),
                null,
                null,
                'n'
            )
            ->willReturn(false);
            
        $this->mockCli->expects($this->once())
            ->method('error')
            ->with('You must agree to the terms to use MChef.');
        
        $result = $this->termsService->promptForTermsAgreement();
        
        $this->assertFalse($result);
        $this->assertFalse($this->termsService->hasAgreedToTerms());
    }
    
    public function testEnsureTermsAgreementCallsPromptWhenNotAgreed(): void {
        $this->mockCli->expects($this->once())
            ->method('info')
            ->with($this->stringContains('MChef is provided "as is"'));
            
        $this->mockCli->expects($this->once())
            ->method('promptYesNo')
            ->with(
                $this->stringContains('Do you agree to these terms?'),
                null,
                null,
                'n'
            )
            ->willReturn(false);
            
        $this->mockCli->expects($this->once())
            ->method('error')
            ->with('You must agree to the terms to use MChef.');
        
        $result = $this->termsService->ensureTermsAgreement();
        
        $this->assertFalse($result);
        $this->assertFalse($this->termsService->hasAgreedToTerms());
    }
    
    public function testEnsureTermsAgreementWhenAlreadyAgreed(): void {
        $this->termsService->createTermsAgreementForTesting();
        
        // Should not prompt when terms are already agreed
        $this->mockCli->expects($this->never())
            ->method('promptYesNo');
        
        $result = $this->termsService->ensureTermsAgreement();
        $this->assertTrue($result);
    }
    
    public function testCannotRunCommandsWithoutTermsAgreement(): void {
        // Ensure no terms agreement exists by removing any existing file
        $this->removeExistingTermsFile();
        
        // Test that TermsService.ensureTermsAgreement returns false when no agreement exists
        // and no --agree-license flag is provided
        $mockOptions = $this->createMock(\splitbrain\phpcli\Options::class);
        $mockOptions->method('getOpt')->with('agree-license')->willReturn(false);
        
        // Should prompt and user declines
        $this->mockCli->expects($this->once())
            ->method('promptYesNo')
            ->willReturn(false);
        
        $this->mockCli->expects($this->once())
            ->method('error')
            ->with('You must agree to the terms to use MChef.');
        
        $result = $this->termsService->ensureTermsAgreement($mockOptions);
        
        $this->assertFalse($result, 'Should return false when user declines terms');
    }
    
    public function testCanRunCommandsAfterTermsAgreement(): void {
        // Create terms agreement first
        $this->termsService->createTermsAgreementForTesting();
        
        // Test that TermsService.ensureTermsAgreement returns true when agreement exists
        $mockOptions = $this->createMock(\splitbrain\phpcli\Options::class);
        
        // Should not prompt since terms are already agreed
        $this->mockCli->expects($this->never())
            ->method('promptYesNo');
        
        $result = $this->termsService->ensureTermsAgreement($mockOptions);
        
        $this->assertTrue($result, 'Should return true when terms are already agreed');
        $this->assertTrue($this->termsService->hasAgreedToTerms(), 'Terms should still be agreed');
        $this->assertFalse($this->termsService->wereTermsJustAgreed(), 'Should not have just agreed (already existed)');
    }
    
    public function testAgreeeLicenseFlagAutoAgrees(): void {
        // Ensure no terms agreement exists initially
        $this->removeExistingTermsFile();
        $this->assertFalse($this->termsService->hasAgreedToTerms());
        
        // Create a simple mock that just returns true for 'agree-license'
        $mockOptions = $this->createMock(\splitbrain\phpcli\Options::class);
        $mockOptions->expects($this->once())
            ->method('getOpt')
            ->with('agree-license')
            ->willReturn(true);
        
        // Should not prompt when flag is present
        $this->mockCli->expects($this->never())
            ->method('promptYesNo');
            
        $this->mockCli->expects($this->once())
            ->method('success')
            ->with('Terms automatically agreed via --agree-license flag.');
        
        $result = $this->termsService->ensureTermsAgreement($mockOptions);
        
        $this->assertTrue($result, 'Should auto-agree with flag');
        $this->assertTrue($this->termsService->hasAgreedToTerms(), 'Terms file should be created');
        $this->assertTrue($this->termsService->wereTermsJustAgreed(), 'Should track that terms were just agreed');
    }
}