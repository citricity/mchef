<?php

namespace App\Service;

use App\Helpers\OS;
use App\Helpers\TestingHelpers;

class TermsService extends AbstractService {

    // Service dependencies.
    private File $file;
    
    private const TERMS_FILE = 'TERMSAGREED.txt';
    private static bool $forceTermsCheck = false;
    private static bool $termsJustAgreed = false;
    
    final public static function instance(): TermsService {
        return self::setup_singleton();
    }
    
    /**
     * Force terms checking even during tests (for testing purposes)
     */
    public static function forceTermsCheck(bool $force = true): void {
        self::$forceTermsCheck = $force;
    }
    
    /**
     * Check if terms were just agreed to in this session
     */
    public function wereTermsJustAgreed(): bool {
        return self::$termsJustAgreed;
    }
    
    /**
     * Reset the "just agreed" flag (for testing)
     */
    public static function resetJustAgreedFlag(): void {
        self::$termsJustAgreed = false;
    }
    
    /**
     * Reset singleton instance (for testing purposes)
     */
    public static function resetInstance(): void {
        if (TestingHelpers::isPHPUnit()) {
            // Use the reset parameter in setup_singleton
            self::setup_singleton(true);
        }
    }
    
    /**
     * Check if user has agreed to terms. If not, prompt them to agree.
     * @param \splitbrain\phpcli\Options|null $options CLI options to check for --agree-license flag
     * @return bool True if terms are agreed, false if user declined
     */
    public function ensureTermsAgreement($options = null): bool {
        // Skip terms check during testing unless explicitly testing terms functionality or forced
        if ((TestingHelpers::isPHPUnit() && !self::$forceTermsCheck) || getenv('MCHEF_I_ACCEPT_TERMS') === '1' ) {
            return true;
        }
        
        if ($this->hasAgreedToTerms()) {
            return true;
        }
        
        // Check for --agree-license flag and auto-agree if present
        if ($options && $options->getOpt('agree-license')) {
            $this->saveTermsAgreement();
            self::$termsJustAgreed = true;
            $this->cli->success("Terms automatically agreed via --agree-license flag.");
            return true;
        }
        
        return $this->promptForTermsAgreement();
    }
    
    /**
     * Check if user has previously agreed to terms
     */
    public function hasAgreedToTerms(): bool {
        $termsFile = $this->getTermsFilePath();
        return file_exists($termsFile);
    }
    
    /**
     * Prompt user to agree to terms and save agreement if accepted
     */
    public function promptForTermsAgreement(): bool {
        $this->displayDisclaimer();
        
        $agreement = $this->cli->promptYesNo("\nDo you agree to these terms?", null, null, 'n');
        
        if ($agreement) {
            $this->saveTermsAgreement();
            self::$termsJustAgreed = true;
            $this->cli->success("Thank you for agreeing to the terms.");
            return true;
        }
        
        $this->cli->error("You must agree to the terms to use MChef.");
        return false;
    }
    
    /**
     * Display the disclaimer text
     */
    private function displayDisclaimer(): void {
        $disclaimer = <<<'_DISCLAIMER_'
MChef © Citricity Limited 2024 onwards. www.citricity.com. All rights reserved.

DISCLAIMER
==========

MChef is provided "as is" without warranty of any kind, express or implied.

By using MChef, you acknowledge and agree that:

You use this software entirely at your own risk.

The author(s) shall not be held liable for any data loss, database corruption, system damage, service interruption, loss of earnings, loss of business opportunity, or any other direct or indirect damages arising from its use.

It is your responsibility to ensure appropriate backups are taken before running commands that modify or rebuild environments.

MChef is intended for development and testing purposes. It should not be used in production environments without proper review, safeguards, and understanding of its behaviour.

No guarantee is made regarding compatibility with specific versions of Moodle, Docker, operating systems, or third-party services.

This disclaimer is in addition to the terms set out in the LICENSE file.
Please review the LICENSE for the full legal terms governing use of this software.

If you do not agree with these terms, do not use this software.

_DISCLAIMER_;
        
        $this->cli->info($disclaimer);
    }
    
    /**
     * Save terms agreement to file
     */
    private function saveTermsAgreement(): void {
        $configurator = Configurator::instance(); // This should automatically create config directory if it doesn't exist.
        $configDir = $configurator->configDir();
        if (!is_dir($configDir)) {
            throw new \RuntimeException("Config directory does not exist: $configDir");
        }
        
        $username = $this->getCurrentUsername();
        $timestamp = date('Y-m-d H:i:s');
        $content = "Terms agreed on: $timestamp\nUser: $username\n";

        $termsFilePath = $this->getTermsFilePath();

        try {
            $this->file->putContents($termsFilePath, $content);
        } catch (\Exception $e) {
            $this->cli->error("Failed to write terms agreement file: {$e->getMessage()}");
            throw new \RuntimeException("Failed to write terms agreement file: {$e->getMessage()}", 0, $e);
        }
    }
    
    /**
     * Get current system username
     */
    private function getCurrentUsername(): string {
        return $_SERVER['USER'] ?? $_SERVER['USERNAME'] ?? 'unknown';
    }
    
    /**
     * Get path to terms agreement file
     */
    private function getTermsFilePath(): string {
        $configurator = Configurator::instance();
        return OS::path($configurator->configDir() . '/' . self::TERMS_FILE);
    }
    
    /**
     * Create terms agreement file for testing purposes
     */
    public function createTermsAgreementForTesting(): void {
        if (!TestingHelpers::isPHPUnit()) {
            throw new \Error('This method should only be called during testing');
        }
        
        $this->saveTermsAgreement();
    }
}