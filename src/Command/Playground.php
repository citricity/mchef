<?php

namespace App\Command;

use App\Service\QrCodeService;
use App\StaticVars;
use App\Traits\ExecTrait;
use App\Traits\SingletonTrait;
use splitbrain\phpcli\Options;

final class Playground extends AbstractCommand {

    use SingletonTrait;
    use ExecTrait;

    // Service.
    public QrCodeService $qrCodeService;

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
        echo "\n\n".$this->qrCodeService->generateQrCode('Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.')."\n\n";
    }

   protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'Establish a bash shell on the moodle container');
        $options->registerArgument('instance', 'Instance name for Moodle playground (optional if instance selected, or run from project directory).', false, self::COMMAND_NAME);
    }
}
