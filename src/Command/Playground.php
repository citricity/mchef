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

        $this->cli->debug('Published redirect to: ' . $result['resourceUrl']);
        $this->cli->success('Playground short URL: ' . $result['shortUrl']);
        // Note - intentinoally not using the cli->info() method here, as we want the QR code in white.
        echo("\n" . $this->qrCodeService->generateQrCode($result['shortUrl']) . "\n");
    }

    private function buildPlaygroundUrl(string $instanceName, string $recipePath): string {
        // TODO: Convert recipe to bluePrint.json format for playground.
        return 'https://ateeducacion.github.io/moodle-playground/?blueprint=ewogICIkc2NoZW1hIjogIi4vYmx1ZXByaW50LXNjaGVtYS5qc29uIiwKICAicHJlZmVycmVkVmVyc2lvbnMiOiB7CiAgICAicGhwIjogIjguNCIsCiAgICAibW9vZGxlIjogIjUuMiIKICB9LAogICJsYW5kaW5nUGFnZSI6ICIvY291cnNlL3ZpZXcucGhwP2lkPTIiLAogICJzdGVwcyI6IFsKICAgIHsgInN0ZXAiOiAiaW5zdGFsbE1vb2RsZSIsICJvcHRpb25zIjogeyAic2l0ZU5hbWUiOiAiTXkgTW9vZGxlIiB9IH0sCiAgICB7ICJzdGVwIjogImxvZ2luIiwgInVzZXJuYW1lIjogImFkbWluIiB9LAogICAgeyAic3RlcCI6ICJpbnN0YWxsVGhlbWUiLCAidXJsIjogImh0dHBzOi8vZ2l0aHViLmNvbS9namJhcm5hcmQvbW9vZGxlLXRoZW1lX2FkYXB0YWJsZS9hcmNoaXZlL3JlZnMvdGFncy9WNTAyLjEuMS56aXAiIH0sCiAgICB7ICJzdGVwIjogInNldFRoZW1lIiwgIm5hbWUiOiAiYWRhcHRhYmxlIiB9LAogICAgeyAic3RlcCI6ICJjcmVhdGVDb3Vyc2UiLCAiZnVsbG5hbWUiOiAiUGh5c2ljcyAxMDEiLCAic2hvcnRuYW1lIjogIlBIWVMxMDEiIH0sCiAgICB7ICJzdGVwIjogImFkZE1vZHVsZSIsICJtb2R1bGUiOiAibGFiZWwiLCAiY291cnNlIjogIlBIWVMxMDEiLCAibmFtZSI6ICJXZWxjb21lIiwgImludHJvIjogIjxwPkhlbGxvIFdvcmxkITwvcD4iIH0KICBdCn0=';
    }

   protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'Establish a bash shell on the moodle container');
        $options->registerArgument('instance', 'Instance name for Moodle playground (optional if instance selected, or run from project directory).', false, self::COMMAND_NAME);
    }
}
