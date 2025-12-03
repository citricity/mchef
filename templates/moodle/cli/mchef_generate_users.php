<?php
// This file is part of MChef - Moodle Chef
// CLI script to generate users (students or teachers) for testing

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');

// Now get cli options
list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'count' => 10,
        'role' => 'student',
    ],
    [
        'h' => 'help',
        'c' => 'count',
        'r' => 'role',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help =
        "Generate users (students or teachers) for testing.

Options:
-h, --help          Print out this help
-c, --count         Number of users to create (default: 10)
-r, --role          User role: 'student' or 'editingteacher' (default: student)

Example:
\$sudo -u www-data /usr/bin/php admin/cli/mchef_generate_users.php --count=200 --role=student
";

    echo $help;
    exit(0);
}

$count = (int) $options['count'];
$role = $options['role'];

if ($count <= 0) {
    cli_error('Count must be a positive integer');
}

if (!in_array($role, ['student', 'editingteacher'])) {
    cli_error('Role must be either "student" or "editingteacher"');
}

echo "Creating {$count} users with role: {$role}...\n";

$roleid = null;
if ($role === 'student') {
    $roleid = get_config('core', 'defaultstudentroleid');
} elseif ($role === 'editingteacher') {
    $roleid = get_config('core', 'defaultcourseteacherroleid');
}

if (!$roleid) {
    // Fallback: get role by shortname
    $roles = get_all_roles();
    foreach ($roles as $r) {
        if ($r->shortname === $role) {
            $roleid = $r->id;
            break;
        }
    }
}

if (!$roleid) {
    cli_error("Could not find role: {$role}");
}

for ($i = 1; $i <= $count; $i++) {
    $user = new stdClass();
    $user->username = $role . $i;
    $user->firstname = ucfirst($role) . ' ' . $i;
    $user->lastname = 'Test';
    $user->email = $role . $i . '@example.com';
    $user->password = 'Test123!@#';
    $user->auth = 'manual';
    $user->confirmed = 1;
    $user->mnethostid = $CFG->mnet_localhost_id;

    try {
        $userid = user_create_user($user, false, false);
        echo "Created user: {$user->username} (ID: {$userid})\n";
    } catch (Exception $e) {
        echo "Error creating user {$i}: " . $e->getMessage() . "\n";
    }
}

echo "User generation completed.\n";
