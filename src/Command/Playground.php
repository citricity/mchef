<?php

namespace App\Command;

use App\Exceptions\CliRuntimeException;
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

    // Service.
    private PlaygroundUrlsService $playgroundUrlsService;
    private PlaygroundSnapshotService $playgroundSnapshotService;
    private QrCodeService $qrCodeService;

    const COMMAND_NAME = 'playground';

    public static function instance(): Playground {
        return self::setup_singleton();
    }

    public function execute(Options $options): void {
        $this->setStaticVarsFromOptions($options);
        $instance = StaticVars::$instance;
        $instanceName = $instance->containerPrefix;
        $recipePath = $instance->recipePath;
        $this->cli->info('Starting playground for instance: '.$instanceName);
        
        $globalConfig = $this->configuratorService->getMainConfig();
        if (empty($globalConfig->githubToken)) {
            $this->cli->warning('Missing global config field githubToken. Set it before using playground URL publishing.');
            return;
        }
        if (empty($globalConfig->githubUrlsRepo)) {
            $this->cli->warning('Missing global config field githubUrlsRepo. Format should be user(or org)/repo E.g. "citricity/mchef-urls". Set it before using playground URL publishing.');
            return;
        }

        $args   = $options->getArgs();
        $source = $args[0] ?? $recipePath;
        $snapshot = $options->getOpt('snapshot');
        $recipe = Recipe::fromJSONFile($source);
        $playgroundLongUrl = $this->buildPlaygroundUrl($recipe);

        try {
            $result = $this->qrCodeService->publishRedirectUrl(
                $playgroundLongUrl,
                $globalConfig->githubUrlsRepo,
                $globalConfig->githubToken
            );
        } catch (CliRuntimeException $e) {
            $this->cli->error('Failed to publish playground URL: ' . $e->getMessage());
            return;
        }

        $this->cli->debug('Published redirect to: ' . $result['resourceUrl']);
        $this->cli->success('Playground short URL: ' . $result['shortUrl']);
        // Note - intentinoally not using the cli->info() method here, as we want the QR code in white.
        echo("\n" . $this->qrCodeService->generateQrCode($result['shortUrl']) . "\n");
    }

    private function buildPlaygroundUrl(Recipe $recipe, ?string $snapshotUrl = null): string {
        $converter = BlueprintConverter::instance();
        $blueprint = $converter->convert($recipe, $snapshotUrl);
        $globalConfig = $this->configuratorService->getMainConfig();
        $playgroundUrlsBase = $globalConfig->playgroundUrl ?? 'https://ateeducacion.github.io/moodle-playground';
        return $playgroundUrlsBase . '/?blueprint=' . urlencode(json_encode($blueprint, JSON_UNESCAPED_SLASHES));
    }

    private function advancedBuildTEMP_CODE(bool $snapshot, ?string $source, \splitbrain\phpcli\Options $options): string {

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
        $publish    = $options->getOpt('publish');

        try {
            $snapshotUrl = null;
            if ($snapshot) {
                if (!$publish) {
                    $this->cli->warning("--snapshot requires --publish to publish the snapshot. Skipping snapshot build.");
                } else {
                    // publishOnly=true: snapshot files are publishd but not committed yet.
                    // publishBlueprint() will commit everything (snapshot + blueprint) in one push.
                    $snapshotUrl = $this->playgroundSnapshotService->buildAndPublish($recipe, $slug, publishOnly: true);
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
            } elseif (!$publish) {
                echo $json . PHP_EOL;
            }

            if ($publish) {
                $this->publishBlueprint($json, $recipe, $slug, (bool)$options->getOpt('qr'));
            }
        } catch (\Throwable $e) {
            if ($publish) {
                // Best-effort: if a snapshot was published (git add) but we never reached the
                // final commit+push in publishBlueprint(), don't leave the local repo clone
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

    private function publishBlueprint(string $json, Recipe $recipe, string $slug, bool $qr): void {
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
        $options->registerOption('publish', 'Publish blueprint to your configured mchef-urls repo', 's', false, self::COMMAND_NAME);
        $options->registerOption('snapshot', 'Build and upload a database snapshot alongside the blueprint (requires --stage)', null, false, self::COMMAND_NAME);
        $options->registerOption('qr', 'Render a QR code for the published short URL (requires --stage)', null, false, self::COMMAND_NAME);
    }
}
