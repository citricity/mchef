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

    public function purgeMoodleFolderOfNonPluginCode(string $instanceName) {

        $instance = $this->configuratorService->getRegisteredInstance($instanceName);
        if (!$instance) {
            throw new Exception ('Invalid instance '.$instanceName);
        }
        $recipe = $this->mainService->getRecipe($instance->recipePath);
        $moodleDir = $this->moodleService->getMoodleDirectoryPath($recipe, $instance->recipePath);

        $pluginsInfo = $this->pluginsService->getPluginsInfoFromRecipe($recipe, true);
        
        // Get array of relative paths for plugins within the moodle directory
        $paths = [];
        if ($pluginsInfo && $pluginsInfo->volumes) {            
            
            // Add plugin paths relative to project directory (they're in moodle subdirectory now)
            $paths = array_map(function($volume) {
                $pathStartsWithSeparator = str_starts_with($volume->path, DIRECTORY_SEPARATOR);
                return ($pathStartsWithSeparator ? '.' : './') . $volume->path;
            }, $pluginsInfo->volumes);
        }
        
        // Add other paths to not delete
        $paths[] = './.vscode';             // Protect VS Code settings
        $paths[] = './.idea';               // Protect PhpStorm settings
        $paths[] = './.env';                // Protect environment files
        $paths[] = './.gitignore';          // Protect gitignore
        $paths[] = './.git';                // Protect git
        $paths[] = './.DS_Store';           // Protect macOS files
      
        $this->cli->promptYesNo('All non-project related files will be removed from this directory. Continue?', null,
            function() { die('Aborted!'); });
        $this->fileService->deleteAllFilesExcluding($moodleDir, [], $paths);
    }
}
