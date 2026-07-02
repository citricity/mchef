<?php

namespace App\Service;

use App\Model\Recipe;
use App\StaticVars;
use App\Traits\ExecTrait;

final class PostInstall extends AbstractService {
    protected Main $mainService;

    use ExecTrait;

    public static function instance(): PostInstall {
        return self::setup_singleton();
    }

    protected function step_setup_theme(Recipe $recipe): void {
        if ($recipe->config->theme) {
            $this->cli->info('Setting up theme: '.$recipe->config->theme);
        }
        $instanceName = StaticVars::$instance->containerPrefix ?? $recipe->containerPrefix ?? null;
        if (empty($instanceName)) {
            throw new \RuntimeException('Instance name is not set. Cannot set theme.');
        }
        $containerName = $this->mainService->getDockerMoodleContainerName($instanceName);
        $cmd = 'docker exec -it '.$containerName.' php /var/www/html/moodle/admin/cli/cfg.php --name=theme --set='.$recipe->config->theme;
        $this->exec($cmd, 'Failed to set theme for '.$instanceName);
        $this->cli->success('Theme successfully set for '.$instanceName);
    }

    public function executePostInstallSteps(Recipe $recipe): void {
        $steps = [];

        if ($recipe->config->theme) {
            $steps[] = function() use ($recipe) {
                $this->step_setup_theme($recipe);
            };
        }

        $errorCount = 0;
        if (!empty($steps)) {
            $this->cli->info('Executing post-install steps...');
            foreach ($steps as $step) {
                try {
                    $step();
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->cli->error('Error executing post-install step: ' . $e->getMessage());
                }
            }
            $this->cli->success('Post-install steps completed with ' . $errorCount . ' errors.');
        } else {
            $this->cli->info('No post-install steps to execute.');
        }
    }
}
