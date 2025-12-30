<?php

namespace App\Service;

use App\Model\Recipe;
use App\Model\RestoreStructure;
use App\Traits\ExecTrait;
use splitbrain\phpcli\Exception;

class RestoreData extends AbstractService {

    use ExecTrait;

    // Dependencies
    private Moodle $moodleService;
    private File $fileService;

    final public static function instance(): RestoreData {
        return self::setup_singleton();
    }

    /**
     * Process restore structure for users and courses
     */
    public function processRestoreStructure(Recipe $recipe, string $moodleContainer): void {
        if (empty($recipe->restoreStructure)) {
            return;
        }

        $restoreStructure = $recipe->restoreStructure;
        $recipePath = $recipe->getRecipePath();
        $recipeDir = dirname($recipePath);

        $moodlePath = $this->moodleService->getDockerMoodlePath($recipe);

        // Process users
        if (!empty($restoreStructure->users)) {
            $this->processUsers($recipe, $moodleContainer, $moodlePath, $restoreStructure->users, $recipeDir);
        }

        // Process courses
        if (!empty($restoreStructure->courseCategories)) {
            $this->processCourses($recipe, $moodleContainer, $moodlePath, $restoreStructure->courseCategories, $recipeDir);
        }

        $this->cli->success('Restore structure processing completed.');
    }

    /**
     * Process users from CSV file
     */
    private function processUsers(Recipe $recipe, string $moodleContainer, string $moodlePath, string $usersPath, string $recipeDir): void {
        $this->cli->notice('Processing users from: ' . $usersPath);

        // Resolve file path (relative, absolute, or URL)
        $csvPath = $this->resolveFilePath($usersPath, $recipeDir);

        // Copy CSV to container if it's a local file
        if (!$this->isUrl($usersPath)) {
            $containerCsvPath = '/tmp/users.csv';
            $cmd = sprintf(
                'docker cp %s %s:%s',
                escapeshellarg($csvPath),
                escapeshellarg($moodleContainer),
                escapeshellarg($containerCsvPath)
            );
            $this->exec($cmd, "Failed to copy users CSV to container");
        } else {
            // Download file inside container
            $containerCsvPath = '/tmp/users.csv';
            $cmd = sprintf(
                'docker exec %s bash -c "curl -L -o %s %s"',
                escapeshellarg($moodleContainer),
                escapeshellarg($containerCsvPath),
                escapeshellarg($usersPath)
            );
            $this->exec($cmd, "Failed to download users CSV in container");
        }

        // Determine upload script path based on Moodle version
        $uploadScriptPath = $this->getUploadUserScriptPath($recipe, $moodlePath);

        // Execute upload users CLI script
        $cmd = sprintf(
            'docker exec %s php %s --mode=createnew --file=%s --delimiter=comma',
            escapeshellarg($moodleContainer),
            escapeshellarg($uploadScriptPath),
            escapeshellarg($containerCsvPath)
        );

        $this->cli->notice('Uploading users...');
        $this->execPassthru($cmd, "Failed to upload users");
        $this->cli->success('Users uploaded successfully.');
    }

    /**
     * Process courses from category structure
     */
    private function processCourses(Recipe $recipe, string $moodleContainer, string $moodlePath, array|string $courseCategories, string $recipeDir): void {
        $this->cli->notice('Processing course categories and backups...');

        // First, create all categories using the CLI script
        $categoryMap = $this->createCategoriesFromRecipe($recipe, $moodleContainer, $moodlePath);

        // Then, process course backups using the category map
        $this->processCourseBackups($recipe, $moodleContainer, $moodlePath, $courseCategories, $recipeDir, $categoryMap);

        $this->cli->success('Course categories and backups processed successfully.');
    }

    /**
     * Create all categories from recipe file using CLI script
     * 
     * @param Recipe $recipe
     * @param string $moodleContainer
     * @param string $moodlePath
     * @return array Map of category names to IDs
     */
    private function createCategoriesFromRecipe(Recipe $recipe, string $moodleContainer, string $moodlePath): array {
        $this->cli->notice('Creating course categories from recipe...');

        // Copy the CLI script from scripts directory
        $sourceScript = __DIR__ . '/../../scripts/moodle/admin/cli/create_category_mchef.php';
        $scriptPath = $moodlePath . '/admin/cli/create_category_mchef.php';
        
        // Get recipe file path - need to copy it to container or use existing path
        $recipePath = $recipe->getRecipePath();
        
        // Copy recipe file to container
        $containerRecipePath = '/tmp/recipe.json';
        $cmd = sprintf(
            'docker cp %s %s:%s',
            escapeshellarg($recipePath),
            escapeshellarg($moodleContainer),
            escapeshellarg($containerRecipePath)
        );
        $this->exec($cmd, "Failed to copy recipe file to container");

        // Copy the CLI script to container
        $cmd = sprintf(
            'docker cp %s %s:%s',
            escapeshellarg($sourceScript),
            escapeshellarg($moodleContainer),
            escapeshellarg($scriptPath)
        );
        $this->exec($cmd, "Failed to copy CLI script to container");

        // Execute the CLI script with recipe file path via environment variable
        $cmd = sprintf(
            'docker exec -e MCHEF_RECIPE_PATH=%s %s php %s',
            escapeshellarg($containerRecipePath),
            escapeshellarg($moodleContainer),
            escapeshellarg($scriptPath)
        );

        $this->cli->info("Executing category creation script...");
        
        // Capture both stdout and stderr
        $cmdWithStderr = $cmd . ' 2>&1';
        
        $output = [];
        $returnVar = 0;
        exec($cmdWithStderr, $output, $returnVar);
        $outputStr = implode("\n", $output);
        
        if ($returnVar !== 0) {
            throw new Exception("Failed to create categories - script returned exit code {$returnVar}. Output: " . substr($outputStr, 0, 2000));
        }
        
        // Check if output starts with "ERROR:"
        if (strpos(trim($outputStr), 'ERROR:') === 0) {
            throw new Exception(trim($outputStr));
        }
        
        // Check for PHP errors or warnings in output
        if (preg_match('/PHP (Fatal error|Parse error|Warning|Notice):/i', $outputStr)) {
            throw new Exception("PHP error in script: " . $outputStr);
        }
        
        // Parse JSON output from stdout
        // The script outputs JSON to stdout, but debug messages go to stderr
        // However, when we capture 2>&1, they're mixed. We need to extract the JSON.
        
        // Try to find JSON object in the output
        // Look for the last occurrence of a complete JSON object
        $jsonOutput = '';
        $lines = explode("\n", $outputStr);
        $jsonStartIndex = -1;
        $braceCount = 0;
        
        // Find where JSON starts (first line with {)
        for ($i = 0; $i < count($lines); $i++) {
            $trimmed = trim($lines[$i]);
            if (strpos($trimmed, '{') !== false) {
                $jsonStartIndex = $i;
                break;
            }
        }
        
        if ($jsonStartIndex >= 0) {
            // Collect lines from JSON start to end
            $jsonLines = [];
            for ($i = $jsonStartIndex; $i < count($lines); $i++) {
                $trimmed = trim($lines[$i]);
                $jsonLines[] = $trimmed;
                $braceCount += substr_count($trimmed, '{') - substr_count($trimmed, '}');
                if ($braceCount === 0 && !empty($trimmed)) {
                    // Complete JSON object found
                    break;
                }
            }
            $jsonOutput = implode("\n", $jsonLines);
        }
        
        // If we still don't have JSON, try parsing the whole output
        if (empty($jsonOutput)) {
            $jsonOutput = trim($outputStr);
        }
        
        $categoryMap = json_decode($jsonOutput, true);
        if ($categoryMap === null || !is_array($categoryMap)) {
            $jsonError = json_last_error_msg();
            throw new Exception("Failed to parse category map from script output. JSON error: {$jsonError}. Output was: " . substr($outputStr, 0, 1000));
        }
        
        $this->cli->success('Created ' . count($categoryMap) . ' categories');
        return $categoryMap;
    }

    /**
     * Process course backups using the category map
     * 
     * @param Recipe $recipe
     * @param string $moodleContainer
     * @param string $moodlePath
     * @param array|string $courseCategories
     * @param string $recipeDir
     * @param array $categoryMap Map of category names to IDs
     */
    private function processCourseBackups(
        Recipe $recipe,
        string $moodleContainer,
        string $moodlePath,
        array|string $courseCategories,
        string $recipeDir,
        array $categoryMap
    ): void {
        $this->processCategoryBackupsRecursive($recipe, $moodleContainer, $moodlePath, $courseCategories, $recipeDir, $categoryMap);
    }

    /**
     * Recursively process category structure to restore course backups
     * 
     * @param Recipe $recipe
     * @param string $moodleContainer
     * @param string $moodlePath
     * @param array $categories
     * @param string $recipeDir
     * @param array $categoryMap Map of category names to IDs
     */
    private function processCategoryBackupsRecursive(
        Recipe $recipe,
        string $moodleContainer,
        string $moodlePath,
        array $categories,
        string $recipeDir,
        array $categoryMap
    ): void {
        foreach ($categories as $categoryName => $categoryData) {
            // Get category ID from map
            if (!isset($categoryMap[$categoryName])) {
                $this->cli->warning("Category '{$categoryName}' not found in category map, skipping backups");
                continue;
            }
            
            $categoryId = $categoryMap[$categoryName];
            
            if (is_array($categoryData)) {
                // Check if it's an array of course backups (strings) or nested categories
                $hasStringKeys = !empty(array_filter(array_keys($categoryData), 'is_string'));
                
                if ($hasStringKeys) {
                    // Recursive: nested categories (has string keys)
                    $this->processCategoryBackupsRecursive($recipe, $moodleContainer, $moodlePath, $categoryData, $recipeDir, $categoryMap);
                } else {
                    // Process course backups (numeric keys, string values)
                    foreach ($categoryData as $backupPath) {
                        if (is_string($backupPath)) {
                            $this->restoreCourse($recipe, $moodleContainer, $moodlePath, $backupPath, $recipeDir, $categoryId);
                        }
                    }
                }
            }
        }
    }

    /**
     * Restore a course from MBZ backup file
     */
    private function restoreCourse(Recipe $recipe, string $moodleContainer, string $moodlePath, string $backupPath, string $recipeDir, int $categoryId): void {
        $this->cli->notice("Restoring course from: {$backupPath}");

        // Resolve file path (relative, absolute, or URL)
        $mbzPath = $this->resolveFilePath($backupPath, $recipeDir);

        // Copy or download MBZ to container
        if (!$this->isUrl($backupPath)) {
            $containerMbzPath = '/tmp/' . basename($mbzPath);
            $cmd = sprintf(
                'docker cp %s %s:%s',
                escapeshellarg($mbzPath),
                escapeshellarg($moodleContainer),
                escapeshellarg($containerMbzPath)
            );
            $this->exec($cmd, "Failed to copy MBZ to container");
        } else {
            // Download file inside container
            $containerMbzPath = '/tmp/' . basename(parse_url($backupPath, PHP_URL_PATH) ?: 'backup.mbz');
            $cmd = sprintf(
                'docker exec %s bash -c "curl -L -o %s %s"',
                escapeshellarg($moodleContainer),
                escapeshellarg($containerMbzPath),
                escapeshellarg($backupPath)
            );
            $this->exec($cmd, "Failed to download MBZ in container");
        }

        // Restore course using Moodle CLI
        $restoreScriptPath = $moodlePath . '/admin/cli/restore_backup.php';
        $cmd = sprintf(
            'docker exec %s php %s --file=%s --categoryid=%d',
            escapeshellarg($moodleContainer),
            escapeshellarg($restoreScriptPath),
            escapeshellarg($containerMbzPath),
            $categoryId
        );

        $this->cli->notice("Restoring course to category ID: {$categoryId}");
        $this->execPassthru($cmd, "Failed to restore course from {$backupPath}");
        $this->cli->success("Course restored successfully from {$backupPath}");
    }


    /**
     * Get the upload user script path based on Moodle version
     */
    private function getUploadUserScriptPath(Recipe $recipe, string $moodlePath): string {
        // Check if Moodle version is 5.1 or later (uses public folder)
        $hasPublicFolder = $this->moodleService->shouldUsePublicFolder($recipe);
        
        if ($hasPublicFolder) {
            return $moodlePath . '/public/admin/tool/uploaduser/cli/uploaduser.php';
        } else {
            return $moodlePath . '/admin/tool/uploaduser/cli/uploaduser.php';
        }
    }

    /**
     * Resolve file path (relative to recipe, absolute, or URL)
     */
    private function resolveFilePath(string $path, string $recipeDir): string {
        if ($this->isUrl($path)) {
            return $path; // Return URL as-is
        }

        // Check if it's an absolute path
        if (strpos($path, '/') === 0 || (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Z]:\\\\/', $path))) {
            if (!file_exists($path)) {
                throw new Exception("File not found: {$path}");
            }
            return $path;
        }

        // Relative path - resolve relative to recipe directory
        $resolvedPath = $recipeDir . DIRECTORY_SEPARATOR . $path;
        if (!file_exists($resolvedPath)) {
            throw new Exception("File not found: {$resolvedPath} (resolved from: {$path})");
        }
        return $resolvedPath;
    }

    /**
     * Download a file from URL
     */
    private function downloadFile(string $url): string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For development - consider making configurable

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($content === false || $httpCode !== 200) {
            throw new Exception("Failed to download file from {$url}: HTTP {$httpCode} - {$error}");
        }

        return $content;
    }

    /**
     * Check if a string is a valid URL
     */
    private function isUrl(string $str): bool {
        return filter_var($str, FILTER_VALIDATE_URL) !== false;
    }
}
