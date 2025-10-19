<?php

namespace App;

use App\Model\Recipe;
use App\Model\RegistryInstance;
use PHPUnit\Framework\MockObject\MockObject;

class StaticVars {
    /** @var MChefCLI|null */
    static MChefCLI|MockObject $cli;
    static ?RegistryInstance $instance;
    static ?Recipe $recipe;
    static ?bool $noCache;
}
