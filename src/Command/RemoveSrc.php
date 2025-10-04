<?php

namespace App\Command;

use App\Model\Recipe;
use App\Service\Project;
use App\StaticVars;
use App\Traits\ExecTrait;
use App\Traits\SingletonTrait;
use splitbrain\phpcli\Options;

class RemoveSrc extends AbstractCommand {

    use SingletonTrait;
    use ExecTrait;

    // Service dependencies.
    private Project $projectService;

    // Models.
    protected Recipe $recipe;

    // Constants.
    const COMMAND_NAME = 'removesrc';

    final public static function instance(): RemoveSrc {
        return self::setup_singleton();
    }

    public function execute(Options $options): void {
        $this->cli->promptYesNo(
            "Remove all your moodle source files. Continue?",
            null,
            function() {
                die;
            });

        $this->setStaticVarsFromOptions($options);
        $instanceName = StaticVars::$instance->containerPrefix;

        $this->projectService->purgeProjectFolderOfNonPluginCode($instanceName);
    }

   protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'Remove Moodle source from project folder');
    }
}
