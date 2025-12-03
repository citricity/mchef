<?php
// This file is part of MChef - Moodle Chef
// CLI script to generate course categories for testing

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/coursecatlib.php');

// Now get cli options
list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'count' => 10,
    ],
    [
        'h' => 'help',
        'c' => 'count',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help =
        "Generate course categories for testing.

Options:
-h, --help          Print out this help
-c, --count         Number of categories to create (default: 10)

Example:
\$sudo -u www-data /usr/bin/php admin/cli/mchef_generate_categories.php --count=20
";

    echo $help;
    exit(0);
}

$count = (int) $options['count'];

if ($count <= 0) {
    cli_error('Count must be a positive integer');
}

echo "Creating {$count} course categories...\n";

for ($i = 1; $i <= $count; $i++) {
    $category = new stdClass();
    $category->name = "Test Category {$i}";
    $category->idnumber = "testcat{$i}";
    $category->description = "Test category {$i} created by MChef";
    $category->descriptionformat = FORMAT_HTML;
    $category->parent = 0; // Top level category

    try {
        // Use coursecat::create() for compatibility with Moodle versions
        $created = coursecat::create($category);
        echo "Created category: {$created->name} (ID: {$created->id})\n";
    } catch (Exception $e) {
        echo "Error creating category {$i}: " . $e->getMessage() . "\n";
    }
}

echo "Category generation completed.\n";
