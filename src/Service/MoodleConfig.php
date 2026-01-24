<?php

namespace App\Service;

use App\Helpers\OS;
use App\Model\Recipe;
use App\Model\Volume;
use App\Model\MoodleConfig as MoodleConfigModel;
use App\Service\Main;
use App\Service\Environment;
use App\Service\RecipeService;
use App\Service\Docker;
use App\StaticVars;

class MoodleConfig extends AbstractService {

    // Dependencies.
    private RecipeService $recipeService;
    private Environment $environmentService;
    private Main $mainService;
    private Docker $dockerService;

    protected function __construct() {
        parent::__construct();
    }

    final public static function instance(): MoodleConfig {
        return self::setup_singleton();
    }

    /**
     * Loads the config file data from the recipe.
     * Either a custom config file path ($recipe->configFile),
     * or a custom config to be added at the end of
     * the existing file ($recipe->config).
     */
    public function processConfigFile(Recipe &$recipe): void {
        if (!empty($recipe->configFile)) {
            if (!file_exists($recipe->configFile)) {
                throw new \Exception('Config file does not exist: ' . $recipe->configFile);
            }
            $registryConfig = $this->environmentService->getRegistryConfig();

            if (empty($registryConfig) && !empty($recipe->mountPlugins)) {
                // If not in publishing mode and recipe has mountPlugins = true.
                // Mount the custom config file as a shared volume so it can be updated by the host.
                $this->mountCustomConfigFile($recipe);
            } else {
                $this->copyCustomConfigFile($recipe);
                $recipe->copyCustomConfigFile = true;
            }
        }

        $this->processTwigConfigFile($recipe);
    }

    private function copyCustomConfigFile(Recipe &$recipe): void {
        $assetsPath = $this->mainService->getAssetsPath();
        // Copy the config file to the assets path.
        copy($recipe->configFile, $assetsPath.'/config-local.php');
        $recipe->customConfigFile = '/var/www/html/moodle/config-local.php';
    }

    /**
     * Mount the config file as a docker volume so it can be live edited.
     */
    private function mountCustomConfigFile(Recipe &$recipe): void {
        // Mount the recipe config file as a Docker volume by adding its path to the dockerData volumes list.
        if (!isset($this->dockerData)) {
            $this->mainService->establishDockerData();
        }

        // Mount host custom config file to /var/www/html/moodle/config-local.php inside container
        $customConfigPath = '/var/www/html/moodle/config-local.php';
        $volume = new Volume(
            path: '/config-local.php',
            hostPath: OS::isWindows()
                ? $this->dockerService->windowsToDockerPath($recipe->configFile)
                : $recipe->configFile,
        );

        $this->mainService->getDockerData()->volumes[] = $volume;
        $recipe->customConfigFile = $customConfigPath;
    }

    private function processTwigConfigFile(Recipe $recipe): void {
        $assetsPath = $this->mainService->getAssetsPath();
        // Create moodle config asset.
        try {
            $moodleConfigContents = $this->mainService->twig->render('@moodle/config.php.twig', (array) $recipe);
        } catch (\Exception $e) {
            throw new \Exception('Failed to parse config.php template: '.$e->getMessage());
        }
        // Remove double blank lines from moodleConfigContents
        $moodleConfigContents = preg_replace("/\n{3,}/", "\n\n", $moodleConfigContents);

        file_put_contents($assetsPath.'/config.php', $moodleConfigContents);

        if (!StaticVars::$ciMode) {
            $browserConfigContents = null;
            if ($recipe->includeBehat || $recipe->developer) {
                try {
                    // Create moodle-browser-config config.
                    $browserConfigContents = $this->mainService->twig->render('@moodle-browser/config.php.twig', (array) $recipe);
                } catch (\Exception $e) {
                    throw new \Exception('Failed to parse moodle-browser config.php template: '.$e->getMessage());
                }
            }
            if ($browserConfigContents) {
                $browserConfigAssetsPath = $assetsPath.'/moodle-browser-config';
                if (!file_exists($browserConfigAssetsPath)) {
                    mkdir($browserConfigAssetsPath, 0755, true);
                }
                file_put_contents($browserConfigAssetsPath.'/config.php', $browserConfigContents);
            }
        }
    }
}