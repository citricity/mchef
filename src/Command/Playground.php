<?php

namespace App\Command;

use App\Exceptions\CliRuntimeException;
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

        $globalConfig = $this->configuratorService->getMainConfig();
        if (empty($globalConfig->githubToken)) {
            $this->cli->warning('Missing global config field githubToken. Set it before using playground URL publishing.');
            return;
        }
        if (empty($globalConfig->githubUrlsRepo)) {
            $this->cli->warning('Missing global config field githubUrlsRepo. Format should be user(or org)/repo E.g. "citricity/mchef-urls". Set it before using playground URL publishing.');
            return;
        }

        $playgroundLongUrl = $this->buildPlaygroundUrl($instanceName, $recipe);

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

        $this->cli->success('Published redirect to: ' . $result['resourceUrl']);
        $this->cli->success('Playground short URL: ' . $result['shortUrl']);
        $this->cli->notice("\n" . $this->qrCodeService->generateQrCode($result['shortUrl']) . "\n");
    }

    private function buildPlaygroundUrl(string $instanceName, string $recipePath): string {
        // TODO: Convert recipe to bluePrint.json format for playground.
        return 'https://www.youtube.com/watch?v=YpBG8hNUrtM';
    }

   protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'Establish a bash shell on the moodle container');
        $options->registerArgument('instance', 'Instance name for Moodle playground (optional if instance selected, or run from project directory).', false, self::COMMAND_NAME);
    }
}
