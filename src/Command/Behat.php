<?php

namespace App\Command;

use App\Exceptions\ExecFailed;
use App\Service\Docker;
use App\Service\Main;
use App\Service\Plugins;
use App\StaticVars;
use App\Traits\ExecTrait;
use App\Traits\SingletonTrait;
use splitbrain\phpcli\Exception;
use splitbrain\phpcli\Options;

final class Behat extends AbstractCommand {

    use SingletonTrait;
    use ExecTrait;

    // Service dependencies.
    protected Plugins $pluginsService;
    protected Docker $dockerService;

    // Constants.
    const COMMAND_NAME = 'behat';
    const NETWORK_NAME = 'mc-network';

    protected string $browser = 'chrome'; // Not configurable for now.

    public static function instance(): Behat {
        return self::setup_singleton();
    }

    private function getBehatRunCodeFromInitOutput(string $initOutput): string {
        // First, test for actual line.
        if (stripos($initOutput, 'vendor/bin/behat') === 0) {
            return explode("\n", $initOutput)[0];
        }
        // Get match on success line.
        $pattern = '/Acceptance tests environment enabled on (.+), to run the tests use:/';
        $this->cli->info('test1');
        $matched = preg_match($pattern, $initOutput, $matches);
        if (!$matched) {
            throw new Exception('Behat initialization seems to have failed: '.$initOutput);
        }
        $fullMatch = trim($matches[0]);

        // Explode init output and try to find success line in it.
        $lines = array_map('trim', explode("\n", $initOutput));
        $pos = array_search($fullMatch, $lines);

        if (stripos($lines[$pos + 1], 'vendor/bin/behat') !== 0) {
            throw new Exception('Behat initialization seems to have failed: '.$initOutput);
        }

        return $lines[$pos + 1];
    }

    public function execute(Options $options): void {
        $this->setStaticVarsFromOptions($options);
        $instance = StaticVars::$instance;
        $instanceName = $instance->containerPrefix;

        $tags = $options->getOpt('tags');
        $this->verbose = !empty($options->getOpt('verbose'));
        $recipe = $this->mainService->getRecipe();
        if (!$recipe->includeBehat) {
            throw new Exception('This recipe does not have includeBehat set to true, OR you need to run mchef.php [recipefile] again.');
        }
        $dockerPs = $this->dockerService->getDockerPs();
        $alreadyRunning = false;
        // Note - the mchef prefix in the container name is hardcoded because it is intended to be shared across projects.
        $containerName = 'mchef-behat-'.$this->browser;
        foreach ($dockerPs as $container) {
            if ($container->names === $containerName) {
                // Already running docker container for behat chrome.
                $this->cli->info('Skipping starting behat container for '
                    .$this->browser.' as it is already running - container id = '
                    .$container->containerId);
                $alreadyRunning = true;
                break;
            }
        }

        $containerAlreadyExists = false;
        $dockerContainers = $this->dockerService->getDockerContainers();
        foreach ($dockerContainers as $container) {
            if ($container->names === $containerName) {
                $containerAlreadyExists = true;
                break;
            }
        }

        if (!$alreadyRunning) {
            if ($containerAlreadyExists) {
                $this->cli->info('Starting existing docker container '.$containerName);
                $cmd = "docker start $containerName";
            } else {
                $this->cli->info('Creating and starting docker container '.$containerName);
                $networkName = self::NETWORK_NAME;
                // Use seleniarm images for ARM64 Macs
                $seleniumImage = php_uname('m') === 'arm64' ?
                    'seleniarm/standalone-chromium:latest' :
                    "selenium/standalone-$this->browser:latest";
                $cmd = "docker run --name $containerName --network=$networkName -d -p 4444:4444 -p 7900:7900 --shm-size=\"2g\" $seleniumImage";
            }
            try {
                $this->exec($cmd);
            } catch (ExecFailed $e) {
                throw new Exception('Failed to start docker chrome');
            }
        }

        $this->cli->notice('Initializing behat');
        $moodleContainer = $this->mainService->getDockerMoodleContainerName($instanceName);
        $cmd = 'docker exec -it '.$moodleContainer.' php /var/www/html/moodle/admin/tool/behat/cli/init.php --axe';
        $this->execStream($cmd, 'Failed to initialize behat');
        // !NOTE AWFUL, AWFUL BUG FIX!
        // Have to do it twice because execStream only returns last line which can end up being performance information as opposed
        // to the command to execute behat.
        // Note - we use stream first because we want to see the table setup and exec the second time to get the full output.
        // Performance hit will be low as the main lift is in the first call.
        // Need to fix execStream so that it will return full output or more than just the last line.
        $verbose = $this->verbose;
        $this->verbose = false; // Stop command from being shown twice.
        $output = $this->exec($cmd);
        $this->verbose = $verbose;

        $behatRunCode = $this->getBehatRunCodeFromInitOutput($output);
        $behatRunCode = str_replace('vendor/bin/behat', '/var/www/html/moodle/vendor/bin/behat', $behatRunCode);

        $featureFile = null;
        if ($args = $options->getArgs()) {
            $featureFile = $args[0];
        }

        $plugins = $this->pluginsService->getPluginsCsvFromOptions($options);

        $runMsg = 'Executing behat tests';
        if (!empty($featureFile)) {
            if (!empty($plugins)) {
                $this->cli->warning('NOTE - --plugins option is ignored when a feature file is passed');
            }
            $runMsg .= " for featurefile $featureFile";
            if (!empty($tags)) {
                $runMsg .= " and tags ".$tags;
            }
        } else if (empty($tags) && !empty($plugins)) {
            $runMsg .= " for plugins ".implode(',', array_keys($plugins));
            $pluginTags = array_map(function($comp) {return '@'.$comp;}, array_keys($plugins));
            $tags = !empty($tags) ? $tags : implode(',', $pluginTags);
        } else if (!empty($tags)) {
            $runMsg .= " for tags ".$tags;
        }

        $profile = $options->getOpt('profile') ? $options->getOpt('profile') : 'headlesschrome' ;
        $this->cli->info('Profile '.$profile);

        $behatRunCode .= ' --profile="'.$profile.'"';
        if ($this->verbose) {
            $behatRunCode .= ' --format-settings=\'{"expand": true}\'';
        }
        if (!empty($tags)) {
            $behatRunCode .= ' --tags='.$tags;
        }
        if (!empty($featureFile)) {
            $behatRunCode .= ' '.$featureFile;
        }
        // $behatRunCode .= ' --stop-on-failure';
        $this->cli->notice($runMsg);
        $cmd = 'docker exec -it '.$moodleContainer.' '.$behatRunCode;

        $this->execPassthru($cmd, 'Tests failed '.$cmd);
    }

   protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'Allows behat tests to be run against plugins defined in the recipe file.');
        $options->registerArgument('feature', 'Specific feature file to run.', false, self::COMMAND_NAME);
        $options->registerOption('plugins',
            'Plugin frankenstyle names to run behat tests against. Omit this argument for all plugins. For multiple plugins, separate using a comma.',
            'p', 'plugins', self::COMMAND_NAME);
        $options->registerOption('tags', 'Limit your tests to features and steps containing specific tags - e.g @javascript',
            't', 'tags', self::COMMAND_NAME);
        $options->registerOption('verbose', 'Output more information', 'v', false, self::COMMAND_NAME);
        $options->registerOption('profile', 'Use a specific profile', null, 'profile', self::COMMAND_NAME);
    }
}
