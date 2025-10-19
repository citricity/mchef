<?php

namespace App\Service;

use App\Model\Recipe;

class Moodle extends AbstractService {

    final public static function instance(): Moodle {
        return self::setup_singleton();
    }

    /**
     * Provide a moodle directory within the project, creating it if it doesn't exist.
     * This creates a centralized location for all Moodle source code and plugins.
     *
     * @param Recipe $recipe The recipe configuration
     * @param string $recipePath Path to the recipe file
     * @return string The absolute path to the moodle directory
     */
    public function provideMoodleDirectory(Recipe $recipe, string $recipePath): string {
        $projectDir = dirname($recipePath);
        $moodleDirectoryName = $recipe->moodleDirectory ?? 'moodle';
        $moodleDir = $projectDir . DIRECTORY_SEPARATOR . $moodleDirectoryName;

        // Create the moodle directory if it doesn't exist
        if (!is_dir($moodleDir)) {
            if (!@mkdir($moodleDir, 0755, true)) {
                throw new \RuntimeException("Failed to create moodle directory: {$moodleDir}");
            }
            $this->cli->info("Created moodle directory: {$moodleDirectoryName}/");
        }

        return $moodleDir;
    }

    /**
     * Get the moodle directory path without creating it.
     *
     * @param Recipe $recipe The recipe configuration
     * @param string $recipePath Path to the recipe file
     * @return string The absolute path to the moodle directory
     */
    public function getMoodleDirectoryPath(Recipe $recipe, string $recipePath): string {
        $projectDir = dirname($recipePath);
        $moodleDirectoryName = $recipe->moodleDirectory ?? 'moodle';
        return $projectDir . DIRECTORY_SEPARATOR . $moodleDirectoryName;
    }
}
