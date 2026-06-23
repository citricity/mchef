<?php

namespace App\Command;

use App\Exceptions\ExecFailed;
use App\Helpers\OS;
use App\Service\Docker;
use App\Service\Moodle;
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
    protected Moodle $moodleService;

    // Constants.
    const COMMAND_NAME = 'behat';
    const NETWORK_NAME = 'mc-network';
    const VIEW_URL = 'http://localhost:7900/?autoconnect=1&resize=scale&password=secret';
    const SELENIUM_STATUS_TIMEOUT_SECONDS = 5;
    const SELENIUM_READY_RETRIES = 8;
    const SELENIUM_READY_RETRY_DELAY_US = 500000;

    protected string $browser = 'chrome'; // Not configurable for now.
    /** @var null|callable(string): void */
    private $viewUrlOpener = null;
    /** @var null|callable(string,string): bool */
    private $seleniumReadyProbe = null;
    /** @var null|callable(string): void */
    private $seleniumRestarter = null;

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

    private function openViewInDefaultBrowser(): void {
        if (is_callable($this->viewUrlOpener)) {
            call_user_func($this->viewUrlOpener, self::VIEW_URL);
            return;
        }

        $url = OS::escShellArg(self::VIEW_URL);

        if (OS::isMac()) {
            $this->execDetached("open $url");
            return;
        }

        if (OS::isLinux()) {
            $this->execDetached("xdg-open $url");
            return;
        }

        if (OS::isWindows()) {
            $this->execDetached($url);
            return;
        }

        $this->cli->warning('Unsupported OS for opening browser automatically. Open this URL manually: '.self::VIEW_URL);
    }

    private function maybeOpenView(Options $options): void {
        if (empty($options->getOpt('view'))) {
            return;
        }

        $this->cli->info('Opening Behat live view: '.self::VIEW_URL);
        $this->openViewInDefaultBrowser();
    }

    private function isSeleniumStatusReady(string $statusJson): bool {
        $decoded = json_decode($statusJson, true);
        if (!is_array($decoded)) {
            return false;
        }

        $nodes = $decoded['value']['nodes'] ?? null;
        if (!is_array($nodes) || empty($nodes)) {
            return false;
        }

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $availability = strtolower((string)($node['availability'] ?? ''));
            if ($availability === 'up') {
                return true;
            }
        }

        return false;
    }

    private function isSeleniumReadyFromMoodleContainer(string $moodleContainer, string $seleniumContainerName): bool {
        if (is_callable($this->seleniumReadyProbe)) {
            return (bool) call_user_func($this->seleniumReadyProbe, $moodleContainer, $seleniumContainerName);
        }

        $statusUrl = 'http://'.$seleniumContainerName.':4444/status';
        $cmd = sprintf(
            'docker exec -i %s curl -sS -m %d %s',
            escapeshellarg($moodleContainer),
            self::SELENIUM_STATUS_TIMEOUT_SECONDS,
            escapeshellarg($statusUrl)
        );

        try {
            $statusJson = $this->exec($cmd, null, true);
        } catch (ExecFailed $e) {
            return false;
        }

        return $this->isSeleniumStatusReady($statusJson);
    }

    private function waitForSeleniumReady(string $moodleContainer, string $seleniumContainerName): bool {
        for ($i = 0; $i < self::SELENIUM_READY_RETRIES; $i++) {
            if ($this->isSeleniumReadyFromMoodleContainer($moodleContainer, $seleniumContainerName)) {
                return true;
            }

            if ($i < self::SELENIUM_READY_RETRIES - 1) {
                usleep(self::SELENIUM_READY_RETRY_DELAY_US);
            }
        }

        return false;
    }

    private function ensureSeleniumHealthyOrRestart(string $moodleContainer, string $seleniumContainerName): void {
        if ($this->waitForSeleniumReady($moodleContainer, $seleniumContainerName)) {
            return;
        }

        $this->cli->warning('Selenium health check failed. Restarting '.$seleniumContainerName.' container...');
        if (is_callable($this->seleniumRestarter)) {
            try {
                call_user_func($this->seleniumRestarter, $seleniumContainerName);
            } catch (\Throwable $e) {
                throw new Exception('Failed to restart selenium container '.$seleniumContainerName);
            }
        } else {
        try {
            $this->exec('docker restart '.escapeshellarg($seleniumContainerName));
        } catch (ExecFailed $e) {
            throw new Exception('Failed to restart selenium container '.$seleniumContainerName);
        }
        }

        if (!$this->waitForSeleniumReady($moodleContainer, $seleniumContainerName)) {
            throw new Exception('Selenium is still not ready after restart. Please check container logs: docker logs '.$seleniumContainerName);
        }
    }

    public function execute(Options $options): void {
        $this->setStaticVarsFromOptions($options);
        $instance = StaticVars::$instance;
        $instanceName = $instance->containerPrefix;
        $moodleContainer = $this->mainService->getDockerMoodleContainerName($instanceName);

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
                $seleniumBrowser = $this->browser;
                if (php_uname('m') === 'arm64' && $this->browser === 'chrome') {
                    // Chrome images do not publish ARM64 manifests; use Chromium on Apple Silicon.
                    $seleniumBrowser = 'chromium';
                }

                $seleniumImage = "selenium/standalone-$seleniumBrowser:latest";
                $cmd = "docker run --name $containerName --network=$networkName -d -p 4444:4444 -p 7900:7900 --shm-size=\"2g\" $seleniumImage";
            }
            try {
                $this->exec($cmd);
            } catch (ExecFailed $e) {
                throw new Exception('Failed to start docker chrome');
            }
        }

        $this->maybeOpenView($options);

        $this->ensureSeleniumHealthyOrRestart($moodleContainer, $containerName);

        $this->cli->notice('Initializing behat');
        $publicFolder = $this->moodleService->shouldUsePublicFolder($recipe) ? 'public/' : '';
        $cmd = 'docker exec -it '.$moodleContainer.' php /var/www/html/moodle/'.$publicFolder.'admin/tool/behat/cli/init.php --axe';
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
        $options->registerOption('view', 'View test results in real time in a browser', 'b', false, self::COMMAND_NAME);
    }
}
