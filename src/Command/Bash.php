<?php

namespace App\Command;

use App\Service\Main;
use App\StaticVars;
use App\Traits\ExecTrait;
use App\Traits\SingletonTrait;
use splitbrain\phpcli\Options;

final class Bash extends AbstractCommand {

    use SingletonTrait;
    use ExecTrait;

    const COMMAND_NAME = 'bash';

    public static function instance(): Bash {
        return self::setup_singleton();
    }

    public function execute(Options $options): void {
        $this->setStaticVarsFromOptions($options);
        $instance = StaticVars::$instance;
        $instanceName = $instance->containerPrefix;
        $containerName = $this->mainService->getDockerMoodleContainerName($instanceName);
        $cmd = 'docker exec -it '.$containerName.' bash';
        $this->execInteractive($cmd);
    }

   protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'Establish a bash shell on the moodle container');
        $options->registerArgument('instance', 'Instance name for Moodle bash shell (optional if instance selected, or run from project directory).', false, self::COMMAND_NAME);
    }
}
