<?php

namespace App\Command;

use App\Service\Main;
use App\Service\Moodle;
use App\Service\Plugins;
use App\StaticVars;
use App\Traits\ExecTrait;
use App\Traits\SingletonTrait;
use splitbrain\phpcli\Exception;
use splitbrain\phpcli\Options;

final class PHPUnit extends AbstractCommand {

    use SingletonTrait;
    use ExecTrait;

    // Service dependencies.
    protected Moodle $moodleService;

    const COMMAND_NAME = 'phpunit';

    public static function instance(): PHPUnit {
        return self::setup_singleton();
    }

    public function execute(Options $options): void {
        $this->setStaticVarsFromOptions($options);
        $instance = StaticVars::$instance;
        $instanceName = $instance->containerPrefix;
        $mainService = Main::instance();

        $moodleContainer = $mainService->getDockerMoodleContainerName($instanceName);
        $recipe = $mainService->getRecipe($instance->recipePath);

        $pluginsService = Plugins::instance();
        if (!$recipe->includePhpUnit && !$recipe->developer) {
            throw new Exception('This recipe does not have includePhpUnit set to true, OR you need to run mchef.php [recipefile] again.');
        }

        $this->cli->notice('Initializing PHPUnit');

        $publicFolder = $this->moodleService->shouldUsePublicFolder($recipe) ? 'public/' : '';
        $cmd = 'docker exec -it '.$moodleContainer.' php /var/www/html/moodle/'.$publicFolder.'admin/tool/phpunit/cli/init.php';
        $this->execStream($cmd, 'Failed to initialize phpunit');
        $runCode = 'bash -c "cd /var/www/html/moodle && vendor/bin/phpunit';

        $groups = $options->getOpt('group');
        $testsuite = $options->getOpt('testsuite');
        $filter = $options->getOpt('filter');

        $runMsg = 'Executing phpunit tests';

        if (!empty($groups)) {
            $runCode .= ' --group='.$groups;
        } else if (empty($testsuite)) {
            $plugins = $pluginsService->getPluginsCsvFromOptions($options);
            $pluginComps = array_map(function($i) {
                return $i->component;
            }, $plugins);
            $pluginsService->validatePluginComponentNames($pluginComps, $pluginsService->loadMchefPluginsInfo());
            if (!empty($plugins)) {
                $runMsg .= " for plugins ".implode(',', array_keys($plugins));
                if (!empty($groups)) {
                    $runMsg .= " and groups $groups";
                }
                $pluginTestPaths = [];
                foreach ($plugins as $plugin) {
                    $pluginTestPaths[] = '/var/www/html/moodle/'.$plugin->path.'/tests/*_test.php';
                }
            } else if (!empty($groups)) {
                $runMsg .= " for groups $groups";
            }
            if (!empty($pluginTestPaths)) {
                $runCode .= ' '.implode(' ', $pluginTestPaths);
            }
        } else {
            $runCode .= ' /var/www/html/moodle/';
            $runCode .= ' --testsuite='.$testsuite;
            $runMsg .= ' for testsuite '.$testsuite;
        }
        if ($filter) {
            $runCode .= ' --filter='.$filter;
            $runMsg .= ' with filter '.$filter;
        }
        $this->cli->notice($runMsg);
        $cmd = 'docker exec -it '.$moodleContainer.' '.$runCode.'"';
        $this->cli->notice($cmd);

        $this->execPassthru($cmd, 'Tests failed');
    }

   protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'Allows phpunit tests to be run against plugins defined in the recipe file.');
        $options->registerOption('plugins',
            'Plugin frankenstyle names to run phpunit tests against. Leave this argument empty for all plugins. For multiple plugins, separate using a comma.',
            'p', 'plugins', self::COMMAND_NAME);
        $options->registerOption('group', 'Limit your tests to ones tagged with specific groups - e.g @group local_someplugin',
            'g', 'group', self::COMMAND_NAME);
        $options->registerOption('testsuite', 'Limit your tests to specific test suites. Note - this will override all other options',
            's', 'testsuite', self::COMMAND_NAME);
        $options->registerOption('filter', 'Filter which tests to run',
            'f', 'filter', self::COMMAND_NAME);
        $options->registerArgument('instance', 'Instance name for Moodle bash shell (optional if instance selected, or run from project directory).', false, self::COMMAND_NAME);
    }
}
