<?php

namespace App\Helpers;

use App\Service\File;
use App\StaticVars;

/**
 * Testing utility functions for MChef
 */
class TestingHelpers {
    private static bool $phpunitTest = false;
    
    /**
     * Check if code is running in PHPUnit test environment
     * Similar to Moodle's defined('PHPUNIT') pattern
     * 
     * @return bool True if running in PHPUnit tests
     */
    public static function isPHPUnit(): bool {
        return !!static::$phpunitTest;
    }

    public static function setIsPHPUnit(bool $value): void {
        static::$phpunitTest = $value;
    }

    public static function getTestDir(): string {
        return realpath(sys_get_temp_dir()).'/mchef_test_config';
    }

    public static function deleteTestDir(): void {
        $testdir = self::getTestDir();
        if (file_exists($testdir) && is_dir($testdir)) {
            File::instance()->deleteDir($testdir);
        }
    }
    
}