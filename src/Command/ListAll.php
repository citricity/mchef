<?php

namespace App\Command;

use App\Service\Configurator;
use App\Service\Docker;
use App\Service\Main;
use App\Service\RecipeService;
use App\Traits\ExecTrait;
use App\Traits\SingletonTrait;
use splitbrain\phpcli\Options;

final class ListAll extends AbstractCommand {

    use SingletonTrait;
    use ExecTrait;

    // Service Dependencies
    private Docker $dockerService;
    private RecipeService $recipeService;

    // Constants
    const COMMAND_NAME = 'list';

    public static function instance(bool $reset = false): ListAll {
        return self::setup_singleton($reset);
    }

    public function execute(Options $options): void {
        $instances = $this->configuratorService->getInstanceRegistry();
        if (empty($instances)) {
            $this->cli->info("No mchef instances have been registered.");
            return;
        }
        $config = $this->configuratorService->getMainConfig();
        $selectedInstance = $config->instance ?? null;
        foreach ($instances as $instance) {
            if (!file_exists($instance->recipePath)) {
                $this->cli->warning('⚠️ Recipe missing '.$instance->recipePath);
            }

            $recipe = $this->recipeService->parse($instance->recipePath);
            $moodleContainerName = $this->mainService->getDockerMoodleContainerName(null, $recipe);

            try {
                $running = $this->dockerService->checkContainerRunning($moodleContainerName);
            } catch (\Exception $e) {
                $running = false; // Assume not running.
            }

            $symbol = $running ? '✅' : '⏸️ '; // Not sure why but we need an extra space after pause!
            $selectedMark = ($selectedInstance && $instance->containerPrefix === $selectedInstance)
                ? " \033[32m*SELECTED*\033[0m" : '';
            echo($symbol.' '.$instance->containerPrefix.' - '.($running ? 'up' : 'inactive').$selectedMark."\n");
        }
    }

   protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'List all mchef recipes and statuses');
    }
}
