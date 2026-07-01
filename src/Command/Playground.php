<?php

namespace App\Command;

use App\Model\Recipe;
use App\Service\BlueprintConverter;
use App\Service\PlaygroundSnapshotService;
use App\Service\PlaygroundUrlsService;
use App\Service\QrCodeService;
use App\StaticVars;
use App\Traits\SingletonTrait;
use splitbrain\phpcli\Options;

final class Playground extends AbstractCommand {

    use SingletonTrait;

    const COMMAND_NAME = 'playground';

    private PlaygroundUrlsService $playgroundUrlsService;
    private PlaygroundSnapshotService $playgroundSnapshotService;
    private QrCodeService $qrCodeService;

    public static function instance(): Playground {
        return self::setup_singleton();
    }

    public function execute(Options $options): void {
        $args   = $options->getArgs();
        $source = $args[0] ?? null;

        if ($source !== null && is_file($source)) {
            $recipe = Recipe::fromJSONFile($source);
        } else {
            $this->setStaticVarsFromOptions($options);
            $instance = StaticVars::$instance;
            $this->cli->info('Starting playground for instance: ' . $instance->containerPrefix);
            $recipe = $this->mainService->getRecipe($instance->recipePath);
        }

        $this->checkManifestExists($recipe);

        $slug     = $this->deriveSlug($recipe);
        $snapshot = $options->getOpt('snapshot');
        $stage    = $options->getOpt('stage');

        try {
            $snapshotUrl = null;
            if ($snapshot) {
                if (!$stage) {
                    $this->cli->warning("--snapshot requires --stage to publish the snapshot. Skipping snapshot build.");
                } else {
                    // stageOnly=true: snapshot files are staged but not committed yet.
                    // stageBlueprint() will commit everything (snapshot + blueprint) in one push.
                    $snapshotUrl = $this->playgroundSnapshotService->buildAndPublish($recipe, $slug, stageOnly: true);
                    $this->cli->success("Snapshot staged: $snapshotUrl");
                }
            }

            $converter = BlueprintConverter::instance();
            $blueprint = $converter->convert($recipe, $snapshotUrl);

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
            } elseif (!$stage) {
                echo $json . PHP_EOL;
            }

            if ($stage) {
                $this->stageBlueprint($json, $recipe, $slug, (bool)$options->getOpt('qr'));
            }
        } catch (\Throwable $e) {
            if ($stage) {
                // Best-effort: if a snapshot was staged (git add) but we never reached the
                // final commit+push in stageBlueprint(), don't leave the local repo clone
                // dirty for the next run. Swallow cleanup failures — surface the original
                // error, not this one.
                try {
                    $this->playgroundUrlsService->discardStaged();
                } catch (\Throwable) {
                }
            }
            throw $e;
        }
    }

    /**
     * Warn if the recipe's moodleTag has no published bundle in the local moodle-playground.
     * Offers to run build-moodle-bundle.sh if the user agrees.
     */
    private function checkManifestExists(Recipe $recipe): void {
        $playgroundPath = $this->playgroundSnapshotService->resolvePlaygroundPath();
        if ($playgroundPath === null) {
            return;
        }

        $channel = $this->playgroundSnapshotService->deriveChannel($recipe->moodleTag);
        if ($channel === null) {
            $this->cli->warning(
                "Cannot determine playground bundle channel from moodleTag '{$recipe->moodleTag}'. " .
                "Version availability could not be verified."
            );
            return;
        }

        $manifestPath = $playgroundPath . '/assets/manifests/' . $channel . '.json';
        if (file_exists($manifestPath)) {
            return;
        }

        $this->cli->warning(
            "No bundle manifest found for '{$channel}' in moodle-playground ({$playgroundPath}).\n" .
            "The playground will fail to load or fall back to the wrong Moodle version."
        );
        $this->cli->promptYesNo(
            "Build a bundle for {$channel} now? (runs build-moodle-bundle.sh — takes several minutes)",
            onYes: fn() => $this->playgroundSnapshotService->buildBundle($recipe->moodleTag),
            default: 'n'
        );
    }

    private function stageBlueprint(string $json, Recipe $recipe, string $slug, bool $qr): void {
        $shortUrl = $this->playgroundUrlsService->publish($json, $slug);
        $this->cli->success("Blueprint published!");
        $this->cli->notice("Share this URL: $shortUrl");

        if ($qr) {
            // Note - intentionally not using cli->info() here, as we want the QR code in white.
            echo "\n" . $this->qrCodeService->generateQrCode($shortUrl) . "\n";
        }
    }

    private function deriveSlug(Recipe $recipe): string {
        $name = $recipe->name ?: 'mchef';
        return trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9_-]/', '-', strtolower($name))), '-');
    }

    protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'Convert a mchef recipe to a Moodle Playground blueprint');
        $options->registerArgument('source', 'Path to a recipe JSON file, or a running instance name', false, self::COMMAND_NAME);
        $options->registerOption('output', 'Write blueprint to a file instead of stdout', 'o', 'PATH', self::COMMAND_NAME);
        $options->registerOption('stage', 'Publish blueprint to your configured mchef-urls repo', 's', false, self::COMMAND_NAME);
        $options->registerOption('snapshot', 'Build and upload a database snapshot alongside the blueprint (requires --stage)', null, false, self::COMMAND_NAME);
        $options->registerOption('qr', 'Render a QR code for the published short URL (requires --stage)', null, false, self::COMMAND_NAME);
    }
}
