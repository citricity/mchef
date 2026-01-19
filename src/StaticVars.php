<?php

namespace App;

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
    static ?string $ciDockerPath = null;
}
