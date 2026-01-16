<?php

namespace App\Service;

use App\Model\Recipe;

class RestoreData extends AbstractService {

    // Dependencies
    private Moodle $moodleService;
    private Docker $dockerService;

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
        $containerCsvPath = '/tmp/users.csv';
        if (!$this->isUrl($usersPath)) {
            $this->dockerService->copyFileToContainer($csvPath, $moodleContainer, $containerCsvPath);
        } else {
            // Download file inside container
            $this->dockerService->downloadFileInContainer($moodleContainer, $usersPath, $containerCsvPath);
            
            // Normalize the CSV file: remove BOM, convert line endings to Unix format
            $this->dockerService->normalizeCsvFileInContainer($moodleContainer, $containerCsvPath);
        }

        // Determine upload script path based on Moodle version
        $uploadScriptPath = $this->getUploadUserScriptPath($recipe, $moodlePath);

        $this->cli->notice('Uploading users...');
        $this->dockerService->executePhpScriptPassthru($moodleContainer, $uploadScriptPath, ['--mode=createnew', "--file={$containerCsvPath}", '--delimiter=comma']);
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
        
        // Create a temporary recipe file with resolved restoreStructure data
        // This is needed when restoreStructure was downloaded from a URL
        $tempRecipePath = $this->createTempRecipeWithResolvedStructure($recipe, $recipePath);
        $recipeFileToUse = $tempRecipePath ?? $recipePath;
        
        try {
            // Copy recipe file to container
            $containerRecipePath = '/tmp/recipe.json';
            $this->dockerService->copyFileToContainer($recipeFileToUse, $moodleContainer, $containerRecipePath);

            // Copy the CLI script to container
            $this->dockerService->copyFileToContainer($sourceScript, $moodleContainer, $scriptPath);

            $this->cli->info("Executing category creation script...");
            
            // Execute the CLI script with recipe file path via environment variable
            list($outputStr, $returnVar) = $this->dockerService->executeInContainerWithEnv($moodleContainer, 'MCHEF_RECIPE_PATH', $containerRecipePath, $scriptPath);
            
            $this->validateScriptOutput($outputStr, $returnVar);
            $categoryMap = $this->parseCategoryMapFromOutput($outputStr);
            
            $this->cli->success('Created ' . count($categoryMap) . ' categories');
            return $categoryMap;
        } finally {
            // Clean up temporary file if we created one
            if ($tempRecipePath !== null && file_exists($tempRecipePath)) {
                unlink($tempRecipePath);
            }
        }
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
            $this->dockerService->copyFileToContainer($mbzPath, $moodleContainer, $containerMbzPath);
        } else {
            // Download file inside container
            $containerMbzPath = '/tmp/' . basename(parse_url($backupPath, PHP_URL_PATH) ?: 'backup.mbz');
            $this->dockerService->downloadFileInContainer($moodleContainer, $backupPath, $containerMbzPath);
        }

        // Restore course using Moodle CLI
        $restoreScriptPath = $moodlePath . '/admin/cli/restore_backup.php';
        
        $this->cli->notice("Restoring course to category ID: {$categoryId}");
        $this->dockerService->executePhpScriptPassthru($moodleContainer, $restoreScriptPath, ["--file={$containerMbzPath}", "--categoryid={$categoryId}"]);
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
                throw new \Exception("File not found: {$path}");
            }
            return $path;
        }

        // Relative path - resolve relative to recipe directory
        $resolvedPath = $recipeDir . DIRECTORY_SEPARATOR . $path;
        if (!file_exists($resolvedPath)) {
            throw new \Exception("File not found: {$resolvedPath} (resolved from: {$path})");
        }
        return $resolvedPath;
    }

    /**
     * Create a temporary recipe file with resolved restoreStructure data
     * This is needed when restoreStructure was downloaded from a URL
     * 
     * @param Recipe $recipe The recipe object with resolved restoreStructure
     * @param string $originalRecipePath Path to the original recipe file
     * @return string|null Path to temporary file, or null if not needed
     */
    private function createTempRecipeWithResolvedStructure(Recipe $recipe, string $originalRecipePath): ?string {
        // Only create temp file if restoreStructure exists and is a RestoreStructure object
        // (not a string URL, which means it was already resolved)
        if ($recipe->restoreStructure === null || is_string($recipe->restoreStructure)) {
            return null; // No need for temp file
        }

        // Read original recipe file
        $recipeContent = file_get_contents($originalRecipePath);
        if ($recipeContent === false) {
            throw new \Exception("Failed to read recipe file: {$originalRecipePath}");
        }

        $recipeData = $this->decodeJsonSafely($recipeContent, "recipe JSON from {$originalRecipePath}");

        // Convert RestoreStructure object to array for JSON encoding
        $restoreStructureArray = [
            'users' => $recipe->restoreStructure->users,
            'courseCategories' => $recipe->restoreStructure->courseCategories,
        ];

        // Replace restoreStructure in recipe data
        $recipeData['restoreStructure'] = $restoreStructureArray;

        // Create temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'mchef_recipe_');
        if ($tempFile === false) {
            throw new \Exception("Failed to create temporary recipe file");
        }

        // Write updated recipe data to temp file
        $jsonContent = $this->encodeJsonSafely($recipeData, "temporary recipe file");
        if (file_put_contents($tempFile, $jsonContent) === false) {
            unlink($tempFile);
            throw new \Exception("Failed to write temporary recipe file");
        }

        return $tempFile;
    }


    /**
     * Safely decode JSON string to array
     * 
     * @param string $json JSON string to decode
     * @param string $errorContext Context for error messages
     * @return array Decoded array
     * @throws Exception If JSON decoding fails
     */
    private function decodeJsonSafely(string $json, string $errorContext = 'JSON'): array {
        $decoded = json_decode($json, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $jsonError = json_last_error_msg();
            throw new \Exception("Failed to decode {$errorContext}: {$jsonError}");
        }
        if (!is_array($decoded)) {
            throw new \Exception("Failed to decode {$errorContext}: expected array, got " . gettype($decoded));
        }
        return $decoded;
    }

    /**
     * Safely encode array to JSON string
     * 
     * @param array $data Data to encode
     * @param string $errorContext Context for error messages
     * @return string JSON string
     * @throws Exception If JSON encoding fails
     */
    private function encodeJsonSafely(array $data, string $errorContext = 'JSON'): string {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $jsonError = json_last_error_msg();
            throw new \Exception("Failed to encode {$errorContext}: {$jsonError}");
        }
        return $json;
    }

    /**
     * Validate script output and check for errors
     * 
     * @param string $output Script output
     * @param int $returnVar Return code from script
     * @throws Exception If errors are found in output
     */
    private function validateScriptOutput(string $output, int $returnVar): void {
        if ($returnVar !== 0) {
            throw new \Exception("Failed to create categories - script returned exit code {$returnVar}. Output: " . substr($output, 0, 2000));
        }
        
        // Check if output starts with "ERROR:"
        if (strpos(trim($output), 'ERROR:') === 0) {
            throw new \Exception(trim($output));
        }
        
        // Check for PHP errors or warnings in output
        if (preg_match('/PHP (Fatal error|Parse error|Warning|Notice):/i', $output)) {
            throw new \Exception("PHP error in script: " . $output);
        }
    }

    /**
     * Extract JSON string from mixed output (handles cases where JSON is mixed with other output)
     * 
     * @param string $output Mixed output containing JSON
     * @return string Extracted JSON string
     */
    private function extractJsonFromOutput(string $output): string {
        $lines = explode("\n", $output);
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
            return implode("\n", $jsonLines);
        }
        
        // If we still don't have JSON, try parsing the whole output
        return trim($output);
    }

    /**
     * Parse category map from script output
     * 
     * @param string $output Script output containing JSON
     * @return array Map of category names to IDs
     * @throws Exception If parsing fails
     */
    private function parseCategoryMapFromOutput(string $output): array {
        $jsonOutput = $this->extractJsonFromOutput($output);
        return $this->decodeJsonSafely($jsonOutput, "category map from script output");
    }

    /**
     * Check if a string is a valid URL
     */
    private function isUrl(string $str): bool {
        return filter_var($str, FILTER_VALIDATE_URL) !== false;
    }
}
