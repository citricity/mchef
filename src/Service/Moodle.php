<?php

namespace App\Service;

use App\Model\Recipe;

class Moodle extends AbstractService {

    private Git $gitService;

    final public static function instance(): Moodle {
        return self::setup_singleton();
    }
    
    /**
     * Check if the current Moodle version uses the public folder structure.
     * Caches the result to avoid repeated remote checks.
     *
     * @param Recipe $recipe The recipe containing moodleTag
     * @return bool True if public folder should be used
     */
    public function shouldUsePublicFolder(Recipe $recipe): bool {
        static $publicFolderCache = [];
        
        $moodleTag = $recipe->moodleTag;
        
        // Check cache first
        if (isset($publicFolderCache[$moodleTag])) {
            return $publicFolderCache[$moodleTag];
        }
        
        // Check if public folder exists for this Moodle version
        $hasPublicFolder = $this->gitService->moodleHasPublicFolder($moodleTag);
        
        // Cache the result
        $publicFolderCache[$moodleTag] = $hasPublicFolder;
        
        if ($hasPublicFolder) {
            $this->cli->info("Moodle {$moodleTag} uses public folder structure - plugins will be installed in public/ paths");
        }
        
        return $hasPublicFolder;
    }

    public function getDockerMoodlePath(Recipe $recipe): string {
        $moodleDir = $recipe->moodleDirectory ?? 'moodle';
        return '/var/www/html/' . $moodleDir;
    }

    public function getDockerMoodlePublicPath(Recipe $recipe): string {
        $moodleDir = $this->getDockerMoodlePath($recipe);
        $hasPublicFolder = $this->shouldUsePublicFolder($recipe);
        if ($hasPublicFolder) {
            $moodleDir .= '/public';
        }

        return $moodleDir;
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

    public function getMoodlePluginsPath(Recipe $recipe, string $recipePath): string {
        $moodleDir = $this->getMoodleDirectoryPath($recipe, $recipePath);
        return $moodleDir . DIRECTORY_SEPARATOR . 'mod';
    }
}
