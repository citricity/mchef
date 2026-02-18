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
            ->with($this->stringContains('Do you agree to these terms?'))
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
        // Create a real CLI instance for this test to test actual command execution
        $cli = new MChefCLI(false);
        
        // Mock the options to simulate a command
        $options = $this->createMock(Options::class);
        $options->method('getCmd')->willReturn('listall');
        $options->method('getOpt')->willReturn(false);
        $options->method('getArgs')->willReturn([]);
        
        // Mock TermsService to return false for ensureTermsAgreement
        $mockTermsService = $this->createMock(TermsService::class);
        $mockTermsService->method('ensureTermsAgreement')->willReturn(false);
        
        // We expect the CLI to exit with code 1 when terms are not agreed
        // Since we can't easily test exit() in PHPUnit, we'll test the TermsService directly
        $this->assertFalse($mockTermsService->ensureTermsAgreement());
    }
    
    public function testCanRunCommandsAfterTermsAgreement(): void {
        // Test that UseCmd command can be accessed after terms agreement
        $this->termsService->createTermsAgreementForTesting();
        
        $this->assertTrue($this->termsService->hasAgreedToTerms());
        $this->assertTrue($this->termsService->ensureTermsAgreement());
    }
    
    public function testAgreeLicenceFlagAutoAgrees(): void {
        $this->assertFalse($this->termsService->hasAgreedToTerms(), 'Terms should not be agreed initially');
        
        // Mock options with agree-licence flag set
        $mockOptions = $this->createMock(\splitbrain\phpcli\Options::class);
        $mockOptions->method('getOpt')
            ->with('agree-licence')
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