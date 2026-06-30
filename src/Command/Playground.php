<?php

namespace App\Command;

use App\Model\Recipe;
use App\Service\BlueprintConverter;
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
        $recipe = $this->mainService->getRecipe($instance->recipePath);
        $this->cli->info('Starting playground for instance: '.$instanceName);

        $converter = BlueprintConverter::instance();
        $blueprint = $converter->convert($recipe);

        foreach ($converter->getWarnings() as $warning) {
            $this->cli->notice($warning);
        }

        $json = json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $outputPath = $options->getOpt('output');
        if ($outputPath) {
            $written = file_put_contents($outputPath, $json . PHP_EOL);
            if ($written === false) {
                throw new \RuntimeException("Failed to write blueprint to: $outputPath");
            }
            $this->cli->success("Blueprint written to: $outputPath");
        } else {
            echo $json . PHP_EOL;
        }
    }

    protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'Convert a mchef recipe to a Moodle Playground blueprint');
        $options->registerArgument('instance', 'Instance name for Moodle playground (optional if instance selected, or run from project directory).', false, self::COMMAND_NAME);
        $options->registerOption('output', 'Write blueprint to a file instead of stdout', 'o', 'PATH', self::COMMAND_NAME);
    }
}
