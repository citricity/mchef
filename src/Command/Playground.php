<?php

namespace App\Command;

use App\StaticVars;
use App\Traits\ExecTrait;
use App\Traits\SingletonTrait;
use splitbrain\phpcli\Options;

final class Playground extends AbstractCommand {

    use SingletonTrait;
    use ExecTrait;

    const COMMAND_NAME = 'playground';

    public static function instance(): Playground {
        return self::setup_singleton();
    }

    public function execute(Options $options): void {
        $this->setStaticVarsFromOptions($options);
        $instance = StaticVars::$instance;
        $instanceName = $instance->containerPrefix;
        $recipe = $instance->recipePath;
        $this->cli->info('Starting playground for instance: '.$instanceName);
        $this->cli->info('TODO, translate recipe '.$recipe.' to playground blueprint.json');
    }

   protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'Establish a bash shell on the moodle container');
        $options->registerArgument('instance', 'Instance name for Moodle playground (optional if instance selected, or run from project directory).', false, self::COMMAND_NAME);
    }
}
