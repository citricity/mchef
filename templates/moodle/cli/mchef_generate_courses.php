<?php
// This file is part of MChef - Moodle Chef
// CLI script to generate courses for testing

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/coursecatlib.php');
require_once($CFG->dirroot . '/course/lib.php');

// Now get cli options
list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'count' => 10,
        'size' => 'M',
        'random-size' => false,
    ],
    [
        'h' => 'help',
        'c' => 'count',
        's' => 'size',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help =
        "Generate courses for testing.

Options:
-h, --help          Print out this help
-c, --count         Number of courses to create (default: 10)
-s, --size          Course size: XS, S, M, L, XL, XXL (default: M)
    --random-size   Use random sizes for courses

Example:
\$sudo -u www-data /usr/bin/php admin/cli/mchef_generate_courses.php --count=30 --size=S
";

    echo $help;
    exit(0);
}

$count = (int) $options['count'];
$baseSize = strtoupper($options['size']);
$randomSize = !empty($options['random-size']);

if ($count <= 0) {
    cli_error('Count must be a positive integer');
}

$validSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
if (!in_array($baseSize, $validSizes)) {
    cli_error('Size must be one of: ' . implode(', ', $validSizes));
}

echo "Creating {$count} courses...\n";

// Get available categories
$categories = coursecat::get_all();
$categoryIds = array_keys($categories);
if (empty($categoryIds)) {
    // Create a default category if none exist
    $category = new stdClass();
    $category->name = "Default Category";
    $category->parent = 0;
    $created = coursecat::create($category);
    $categoryIds = [$created->id];
}

$sizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];

for ($i = 1; $i <= $count; $i++) {
    $categoryId = $categoryIds[array_rand($categoryIds)];
    $size = $randomSize ? $sizes[array_rand($sizes)] : $baseSize;

    $course = new stdClass();
    $course->fullname = "Test Course {$i}";
    $course->shortname = "testcourse{$i}";
    $course->category = $categoryId;
    $course->summary = "Test course {$i} created by MChef";
    $course->summaryformat = FORMAT_HTML;
    $course->format = 'topics';
    $course->numsections = 10;
    $course->startdate = time();
    $course->visible = 1;

    try {
        $created = create_course($course);
        echo "Created course: {$course->fullname} (ID: {$created->id})\n";

        // If we have the test course generator tool, use it to populate the course
        if (file_exists($CFG->dirroot . '/admin/tool/generator/cli/maketestcourse.php')) {
            $shortname = escapeshellarg($course->shortname);
            $sizeEscaped = escapeshellarg($size);
            $cmd = "php " . $CFG->dirroot . "/admin/tool/generator/cli/maketestcourse.php --shortname={$shortname} --size={$sizeEscaped}";
            exec($cmd, $output, $returnVar);
            if ($returnVar === 0) {
                echo "  Populated course with size: {$size}\n";
            }
        }
    } catch (Exception $e) {
        echo "Error creating course {$i}: " . $e->getMessage() . "\n";
    }
}

echo "Course generation completed.\n";
