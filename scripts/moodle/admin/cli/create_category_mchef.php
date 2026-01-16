<?php
// This file is part of mchef - Modular Chef
//
// This CLI script creates course categories based on the recipe file structure.
// It parses the restoreStructure.courseCategories node and creates a nested
// category structure matching the JSON hierarchy.

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'help' => false,
], [
    'h' => 'help',
]);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help = <<<EOL
Create course categories from recipe file.

This script parses the recipe file and creates course categories based on the
restoreStructure.courseCategories node. Categories are created in a nested
structure matching the JSON hierarchy.

The recipe file path is provided via the MCHEF_RECIPE_PATH environment variable.

Options:
    -h, --help             Print out this help

Example:
    \$MCHEF_RECIPE_PATH=/tmp/recipe.json sudo -u www-data /usr/bin/php admin/cli/create_category_mchef.php

EOL;

    echo $help;
    exit(0);
}

// Get recipe file path from environment variable
$recipePath = getenv('MCHEF_RECIPE_PATH');
if (empty($recipePath)) {
    cli_error("MCHEF_RECIPE_PATH environment variable is not set. Please set it to the path of the recipe JSON file.");
}

// Validate recipe file exists
if (!file_exists($recipePath)) {
    cli_error("Recipe file not found: {$recipePath}");
}

// Read and parse recipe file
$recipeContent = file_get_contents($recipePath);
if ($recipeContent === false) {
    cli_error("Failed to read recipe file: {$recipePath}");
}

$recipe = json_decode($recipeContent, true);
if ($recipe === null) {
    $jsonError = json_last_error_msg();
    cli_error("Failed to parse recipe JSON: {$jsonError}");
}

// Check if restoreStructure exists
if (!isset($recipe['restoreStructure'])) {
    cli_error("Recipe file does not contain 'restoreStructure' node");
}

// Check if courseCategories exists
if (!isset($recipe['restoreStructure']['courseCategories'])) {
    cli_error("Recipe file does not contain 'restoreStructure.courseCategories' node");
}

$courseCategories = $recipe['restoreStructure']['courseCategories'];

// If courseCategories is a string (URL), we can't process it here
if (is_string($courseCategories)) {
    cli_error("courseCategories is a URL string. This script only processes inline category structures.");
}

// If courseCategories is not an array, error
if (!is_array($courseCategories)) {
    cli_error("courseCategories must be an array/object structure");
}

// Ensure we have admin user
if (!$admin = get_admin()) {
    cli_error('No admin user found');
}

// Map to store category names to IDs
$categoryMap = [];

/**
 * Recursively create categories from the structure
 * 
 * @param array $categories The category structure from recipe
 * @param int|null $parentId The parent category ID (null for root)
 * @return void
 */
function createCategoriesRecursive($categories, $parentId = null) {
    global $categoryMap, $DB;
    
    foreach ($categories as $categoryName => $categoryData) {
        // Determine the actual parent ID to use
        $actualParentId = $parentId;
        if ($actualParentId === null) {
            // For root level, use the top category (id=0)
            $topCategory = core_course_category::top();
            $actualParentId = $topCategory->id;
        }
        
        // Check if category with this name already exists under the same parent
        $existingCategory = $DB->get_record('course_categories', [
            'name' => $categoryName,
            'parent' => $actualParentId
        ], '*', IGNORE_MISSING);
        
        if ($existingCategory !== false) {
            // Category already exists, use it
            $currentCategoryId = $existingCategory->id;
            mtrace("Category '{$categoryName}' already exists with ID {$currentCategoryId}, reusing");
        } else {
            // Create new category
            $categoryDataToCreate = [
                'name' => $categoryName,
                'parent' => $actualParentId,
            ];
            
            try {
                $newCategory = core_course_category::create($categoryDataToCreate);
                $currentCategoryId = $newCategory->id;
                mtrace("Created category '{$categoryName}' with ID {$currentCategoryId}" . 
                       ($actualParentId ? " under parent {$actualParentId}" : " at root level"));
            } catch (Exception $e) {
                cli_error("Failed to create category '{$categoryName}': " . $e->getMessage());
            }
        }
        
        // Store in map using category name as key
        // Note: If the same category name appears at different levels, the last one will overwrite
        // This is a limitation, but should be rare in practice
        $categoryMap[$categoryName] = $currentCategoryId;
        
        // Process nested categories if categoryData is an associative array (has string keys)
        if (is_array($categoryData)) {
            $hasStringKeys = !empty(array_filter(array_keys($categoryData), 'is_string'));
            
            if ($hasStringKeys) {
                // This is a nested category structure
                createCategoriesRecursive($categoryData, $currentCategoryId);
            }
            // If it's a numeric array, it's just a list of course backups, skip
        }
    }
}

// Start creating categories
mtrace("Starting category creation from recipe file...");
createCategoriesRecursive($courseCategories);

// Output JSON map to stdout (for RestoreData.php to parse)
// We output both the full path keys and simple name keys
echo json_encode($categoryMap, JSON_PRETTY_PRINT) . "\n";

mtrace("Category creation completed. Created " . count($categoryMap) . " category mappings.");
exit(0);

