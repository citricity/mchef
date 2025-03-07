<?php

namespace App\Service;

class Project extends AbstractService {
    final public static function instance(\MChefCLI $cli): Project {
        return self::setup_instance($cli);
    }

    public function getProjectDir() {
        $mainService = Main::instance($this->cli);
        $chefPath = $mainService->getChefPath(true);
        return realpath($chefPath . DIRECTORY_SEPARATOR . '..');
    }

    public function purgeProjectFolderOfNonPluginCode() {
        $mainService = Main::instance($this->cli);
        $projectDir = $this->getProjectDir();

        $recipe = $mainService->getRecipe();

        $pluginsInfo = Plugins::instance($this->cli)->getPluginsInfoFromRecipe($recipe);
        // Get array of relative paths for plugins.
        $paths = array_map(function($volume) { return '.'.$volume->path; }, $pluginsInfo->volumes);
        // Add other paths to not delete.
        $paths[] = '.mchef'; // Definitely do not want to delete this! (TODO- this is probably unnecessary due to line below).
        $paths[] = './.*'; // Any other hidden folders at the root of this mchef dir.
        $paths[] = './_behat_dump';
        $paths[] = './*recipe.json';
        $this->cli->promptYesNo('All non project related files will be removed from this dir. Continue?', null,
            function() { die('Aborted!'); });
        File::instance()->deleteAllFilesExcluding($projectDir, [], $paths);
    }
}
