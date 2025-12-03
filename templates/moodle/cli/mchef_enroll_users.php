<?php
// This file is part of MChef - Moodle Chef
// CLI script to enroll students and teachers in courses

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/enrol/manual/lib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');

// Now get cli options
list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help =
        "Enroll students and teachers in courses.

This script enrolls users created by MChef (with usernames starting with 'student' or 'editingteacher')
into courses created by MChef.

Options:
-h, --help          Print out this help

Example:
\$sudo -u www-data /usr/bin/php admin/cli/mchef_enroll_users.php
";

    echo $help;
    exit(0);
}

echo "Enrolling users in courses...\n";

// Get all students (users with username starting with 'student')
$students = $DB->get_records_sql(
    "SELECT id, username
     FROM {user}
     WHERE deleted = 0
     AND suspended = 0
     AND id > 2
     AND username LIKE 'student%'
     ORDER BY id ASC",
    []
);
$studentIds = array_keys($students);
$studentCount = count($studentIds);

// Get all teachers (users with username starting with 'editingteacher')
$teachers = $DB->get_records_sql(
    "SELECT id, username
     FROM {user}
     WHERE deleted = 0
     AND suspended = 0
     AND id > 2
     AND username LIKE 'editingteacher%'
     ORDER BY id ASC",
    []
);
$teacherIds = array_keys($teachers);
$teacherCount = count($teacherIds);

// Get all courses
$courses = $DB->get_records('course', ['visible' => 1], 'id ASC', 'id, shortname, fullname');
$courseIds = array_keys($courses);
$courseCount = count($courseIds);

if (empty($courseIds)) {
    echo "No courses found to enroll users in.\n";
    exit(0);
}

if (empty($studentIds) && empty($teacherIds)) {
    echo "No users found to enroll.\n";
    exit(0);
}

echo "Found {$studentCount} students, {$teacherCount} teachers, and {$courseCount} courses.\n";

// Get enrollment plugin
$enrolplugin = enrol_get_plugin('manual');
if (!$enrolplugin) {
    cli_error('Manual enrollment plugin not found');
}

$enrolledCount = 0;

foreach ($courseIds as $courseId) {
    $course = $courses[$courseId];
    $context = context_course::instance($courseId);

    // Get or create manual enrollment instance
    $instances = enrol_get_instances($courseId, false);
    $enrolInstance = null;
    foreach ($instances as $instance) {
        if ($instance->enrol === 'manual') {
            $enrolInstance = $instance;
            break;
        }
    }

    if (!$enrolInstance) {
        // Create manual enrollment instance
        $enrolId = $enrolplugin->add_default_instance($course);
        if ($enrolId) {
            $enrolInstance = $DB->get_record('enrol', ['id' => $enrolId]);
        }
    }

    if (!$enrolInstance) {
        echo "Warning: Could not create enrollment instance for course {$course->shortname}\n";
        continue;
    }

    // Enroll students (distribute evenly across courses)
    if (!empty($studentIds)) {
        $studentsPerCourse = max(1, (int) ceil($studentCount / $courseCount));
        $courseStudentIds = array_slice($studentIds, 0, $studentsPerCourse);
        $studentIds = array_slice($studentIds, $studentsPerCourse);

        foreach ($courseStudentIds as $userId) {
            if ($enrolplugin->enrol_user($enrolInstance, $userId, 5, time(), 0)) {
                $enrolledCount++;
            }
        }
    }

    // Enroll teachers (at least one per course, distribute evenly)
    if (!empty($teacherIds)) {
        $teachersPerCourse = max(1, (int) ceil($teacherCount / $courseCount));
        $courseTeacherIds = array_slice($teacherIds, 0, $teachersPerCourse);
        $teacherIds = array_slice($teacherIds, $teachersPerCourse);

        foreach ($courseTeacherIds as $userId) {
            if ($enrolplugin->enrol_user($enrolInstance, $userId, 3, time(), 0)) {
                // Assign editing teacher role
                role_assign(3, $userId, $context->id);
                $enrolledCount++;
            }
        }
    }

    echo "Enrolled users in course: {$course->shortname}\n";
}

echo "Enrollment completed. Total enrollments: {$enrolledCount}\n";
