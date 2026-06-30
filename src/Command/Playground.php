<?php

namespace App\Command;

use App\Model\Recipe;
use App\Service\BlueprintConverter;
use App\Service\PlaygroundUrlsService;
use App\StaticVars;
use App\Traits\SingletonTrait;
use splitbrain\phpcli\Options;

final class Playground extends AbstractCommand {

    use SingletonTrait;

    const COMMAND_NAME = 'playground';

    private PlaygroundUrlsService $playgroundUrlsService;

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
        $stage = $options->getOpt('stage');

        if ($outputPath) {
            $written = file_put_contents($outputPath, $json . PHP_EOL);
            if ($written === false) {
                throw new \RuntimeException("Failed to write blueprint to: $outputPath");
            }
            $this->cli->success("Blueprint written to: $outputPath");
        } elseif (!$stage) {
            echo $json . PHP_EOL;
        }

        if ($stage) {
            $this->stageBlueprint($json, $recipe);
        }
    }

    private function stageBlueprint(string $json, Recipe $recipe): void {
        $name = $recipe->name ?: 'mchef';
        $slug = trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9_-]/', '-', strtolower($name))), '-');
        $shortUrl = $this->playgroundUrlsService->publish($json, $slug);
        $this->cli->success("Blueprint published!");
        $this->cli->notice("Share this URL: $shortUrl");
    }

    protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'Convert a mchef recipe to a Moodle Playground blueprint');
        $options->registerArgument('instance', 'Instance name for Moodle playground (optional if instance selected, or run from project directory).', false, self::COMMAND_NAME);
        $options->registerOption('output', 'Write blueprint to a file instead of stdout', 'o', 'PATH', self::COMMAND_NAME);
        $options->registerOption('stage', 'Publish blueprint to your configured mchef-urls repo', 's', false, self::COMMAND_NAME);
    }
}
