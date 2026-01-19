<?php

namespace App\Command;

use App\Helpers\OS;
use App\Model\Recipe;
use App\Model\RegistryInstance;
use App\Service\File;
use App\Service\Moodle;
use App\Service\Plugins;
use App\Service\Project;
use App\StaticVars;
use App\Traits\ExecTrait;
use App\Traits\SingletonTrait;
use splitbrain\phpcli\Options;

final class CopySrc extends AbstractCommand {

    use SingletonTrait;
    use ExecTrait;

    // Service dependencies.
    private Plugins $pluginsService;
    private Project $projectService;
    private File $fileService;
    private Moodle $moodleService;

    // Constants.
    const COMMAND_NAME = 'copysrc';

    protected Recipe $recipe;

    public static function instance(): CopySrc {
        return self::setup_singleton();
    }

    private function copySrc(RegistryInstance $instance): void {        
        $this->recipe = $this->mainService->getRecipe($instance->recipePath);
        $moodleContainer = $this->mainService->getDockerMoodleContainerName();

        // Use Moodle service to ensure moodle directory exists and get its path
        $moodleTargetPath = $this->moodleService->provideMoodleDirectory($this->recipe, $instance->recipePath);

        // Create temp directory on guest moodle container.
        $cmd = 'mktemp -d -t XXXXXXXXXX';
        $tmpDir = $this->exec('docker exec '.$moodleContainer.' '.$cmd);

        // Copy all moodle files to temp folder on guest.
        $this->cli->notice('Preparing moodle src on guest');
        $cmd = 'docker exec '.$moodleContainer.' cp -R /var/www/html/moodle '.$tmpDir;
        $this->execPassthru($cmd);

        // Remove plugin folders from tmpDir on guest.
        // This is essential to avoid copying paths that are volumes back to host which results in docker locking up.
        // We also don't want to wipe over local plugin work!
        $pluginsInfo = $this->pluginsService->getPluginsInfoFromRecipe($this->recipe, StaticVars::$noCache);
        $paths = array_map(function($volume) { return $volume->path; }, $pluginsInfo->volumes);
        foreach ($paths as $path) {
            $cmd = 'docker exec '.$moodleContainer.' rm -rf '.$tmpDir.'/moodle/'.$path;
            $this->exec($cmd);
        }

        // Also purge the folder of git folders if present.
        $cmd = 'docker exec -w '.$tmpDir.'/moodle '.$moodleContainer.' find . -path "./.git" -exec rm -rf {} +';
        $this->exec($cmd);

        $this->cli->notice('Copying moodle source to project directory');
        $exec = 'docker cp '.$moodleContainer.':'.$tmpDir.'/moodle/. '.$moodleTargetPath;
        $this->execPassthru($exec);

        // Remove temp directory on guest moodle container.
        $cmd = 'rm -rf '.$tmpDir;
        $this->exec('docker exec '.$moodleContainer.' '.$cmd);

        if (file_exists($moodleTargetPath.'/lib/weblib.php')) {
            $this->cli->success('Finished copying moodle source to project directory');
        }
    }

    public function execute(Options $options): void {
        if (OS::isWindows()) {
            $this->cli->error('This command is not supported on Windows at the moment. Requires testing.');
            return;
        }
        $this->setStaticVarsFromOptions($options);
        $instance = StaticVars::$instance;
        $instanceName = $instance->containerPrefix;
        $projectDir = dirname($instance->recipePath);
        
        $recipe = $this->mainService->getRecipe($instance->recipePath);
        $moodleDirectoryName = $recipe->moodleDirectory ?? 'moodle';

        $result = $this->cli->promptYesNo(
            "Selected instance is $instanceName \nProject directory is $projectDir\nMoodle directory: $moodleDirectoryName/\n\nCopying the moodle src into your moodle directory will wipe everything except your plugin files. Continue?",
            null,
            function() {
                return false;
            });
        if (!$result) {
            return;
        }

        $this->projectService->purgeMoodleFolderOfNonPluginCode($instanceName);
        $moodleDir = $this->moodleService->getMoodleDirectoryPath($recipe, $instance->recipePath);

        $this->fileService->folderRestrictionCheck($moodleDir, 'Copy files to this folder');

        $this->copySrc($instance);
    }

   protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'Copy Moodle source from docker container to project folder');
    }
}
