<?php

namespace App;

use App\Helpers\TestingHelpers;
use App\Model\Recipe;
use App\Model\RegistryInstance;
use PHPUnit\Framework\MockObject\MockObject;

class StaticVars {
    /** @var MChefCLI|null */
    static null|MChefCLI|MockObject $cli = null;
    static ?RegistryInstance $instance = null;
    static ?Recipe $recipe = null;
    static ?bool $noCache = false;
    static ?bool $ciMode = false; // Are we building in CI mode?
    private static ?string $_ciDockerPath = null;

    public static function setCiDockerPath(string $path): void {
        if (self::$_ciDockerPath !== null && !TestingHelpers::isPhpUnit()) {
            throw new \RuntimeException('CI Docker path has already been set.');
        }
        self::$_ciDockerPath = $path;
    }

    public static function getCiDockerPath(): ?string {
        return self::$_ciDockerPath;
    }
}
