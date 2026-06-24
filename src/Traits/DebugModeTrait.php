<?php

namespace App\Traits;

use App\Enums\DebugMode;
use App\Service\Configurator;

trait DebugModeTrait {
    protected bool $verbose = false;
    protected function getDebugMode(): ?DebugMode {
        if ($this->verbose) {
            return DebugMode::VERBOSE;
        }
        $config = Configurator::instance();
        $debugMode = getenv('MCHEF_DEBUG_MODE') ?: $config->getMainConfig()->debugMode;
        if ($debugMode === null) {
            return null;
        }
        if ($debugMode instanceof DebugMode) {
            return $debugMode;
        }
        return DebugMode::tryFrom($debugMode);
    }

    protected function verboseCmdDebug(string $cmd): void {
        if ($this->getDebugMode() === DebugMode::VERBOSE && !empty($this->cli)) {
            $this->cli->info("Debug VERBOSE: command: $cmd");
        }
    }

    protected function errorCmdDebug(string $cmd): void {
        if ($this->getDebugMode() === DebugMode::ERROR && !empty($this->cli)) {
            $this->cli->info("Debug ERROR: command: $cmd");
        }
    }
}