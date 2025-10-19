<?php

namespace App\Service;

use splitbrain\phpcli\Exception;

class Project extends AbstractService {

    // Service dependencies.
    private Main $mainService;
    private Configurator $configuratorService;
    private Plugins $pluginsService;
    private File $fileService;
    private Moodle $moodleService;

    final public static function instance(): Project {
        return self::setup_singleton();
    }

    public function purgeProjectFolderOfNonPluginCode(string $instanceName) {

        $instance = $this->configuratorService->getRegisteredInstance($instanceName);
        if (!$instance) {
            throw new Exception ('Invalid instance '.$instanceName);
        }
        $recipe = $this->mainService->getRecipe($instance->recipePath);
        $projectDir = dirname($instance->recipePath);

        $pluginsInfo = $this->pluginsService->getPluginsInfoFromRecipe($recipe);
        
        // Get array of relative paths for plugins within the moodle directory
        $paths = [];
        if ($pluginsInfo && $pluginsInfo->volumes) {
            $moodleDir = $this->moodleService->getMoodleDirectoryPath($recipe, $instance->recipePath);
            $moodleDirName = basename($moodleDir);
            
            // Add plugin paths relative to project directory (they're in moodle subdirectory now)
            $paths = array_map(function($volume) use ($moodleDirName) { 
                return './' . $moodleDirName . $volume->path; 
            }, $pluginsInfo->volumes);
        }
        
        // Add other paths to not delete - FIXED THE DANGEROUS PATTERN
        $paths[] = './.mchef';              // Protect MChef directory
        $paths[] = './.git';                // Protect Git repository
        $paths[] = './.vscode';             // Protect VS Code settings
        $paths[] = './.idea';               // Protect PhpStorm settings
        $paths[] = './.env';                // Protect environment files
        $paths[] = './.gitignore';          // Protect gitignore
        $paths[] = './.DS_Store';           // Protect macOS files
        $paths[] = './_behat_dump';         // Protect Behat dumps
        $paths[] = './*recipe.json';        // Protect recipe files
        
        // Protect the entire moodle directory (it has its own management)
        $moodleDirectoryName = $recipe->moodleDirectory ?? 'moodle';
        $paths[] = './' . $moodleDirectoryName;
        
        $this->cli->promptYesNo('All non-project related files will be removed from this directory. Continue?', null,
            function() { die('Aborted!'); });
        $this->fileService->deleteAllFilesExcluding($projectDir, [], $paths);
    }
}
