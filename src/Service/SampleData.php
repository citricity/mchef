<?php

namespace App\Service;

use App\Constants\SampleDataSize;
use App\Model\Recipe;
use App\Model\SampleData as SampleDataModel;
use App\Traits\ExecTrait;

class SampleData extends AbstractService {

    use ExecTrait;

    // Dependencies
    private Moodle $moodleService;

    final public static function instance(): SampleData {
        return self::setup_singleton();
    }

    /**
     * Generate sample data for Moodle based on recipe configuration
     */
    public function generateSampleData(Recipe $recipe, string $moodleContainer): void {
        if (empty($recipe->sampleData)) {
            return;
        }

        $sampleData = $recipe->sampleData;
        $this->cli->notice('Generating sample data for Moodle...');

        $moodlePath = $this->moodleService->getDockerMoodlePath($recipe);

        // Detect legacy format and convert
        $this->normalizeLegacyConfiguration($sampleData);

        // Determine generation mode
        $mode = $sampleData->mode ?? 'site';
        $useToolGenerator = $this->shouldUseToolGenerator($sampleData);

        if ($useToolGenerator) {
            // Use Moodle's tool_generator directly
            if ($mode === 'site') {
                $this->generateSite($moodleContainer, $moodlePath, $sampleData);
            } else {
                $this->generateCoursesWithToolGenerator($moodleContainer, $moodlePath, $sampleData);
            }
        } else {
            // Fall back to legacy generation method
            $this->generateLegacy($moodleContainer, $moodlePath, $sampleData);
        }

        $this->cli->success('Sample data generation completed.');
    }

    /**
     * Normalize legacy configuration format to new format
     */
    private function normalizeLegacyConfiguration(SampleDataModel $sampleData): void {
        // If courseSize is set but size is not, convert it
        if (!empty($sampleData->courseSize) && empty($sampleData->size)) {
            $sizeMap = [
                'small' => SampleDataSize::S,
                'medium' => SampleDataSize::M,
                'large' => SampleDataSize::L,
                'random' => SampleDataSize::M, // Default to medium for random
            ];
            $sampleData->size = $sizeMap[$sampleData->courseSize] ?? SampleDataSize::M;
        }

        // Normalize and validate size value
        if (!empty($sampleData->size)) {
            $normalized = SampleDataSize::normalize($sampleData->size);
            if ($normalized === null) {
                $this->cli->warning("Invalid size value '{$sampleData->size}', defaulting to " . SampleDataSize::M);
                $sampleData->size = SampleDataSize::M;
            } else {
                $sampleData->size = $normalized;
            }
        }

        // Set default mode if not specified
        if (empty($sampleData->mode)) {
            // If legacy properties are set, use 'course' mode, otherwise 'site'
            $sampleData->mode = (!empty($sampleData->courses) || !empty($sampleData->courseSize)) ? 'course' : 'site';
        }

        // Only set default size if we're using tool_generator (have mode or size already set)
        // This prevents forcing tool_generator when only legacy properties are present
        if (empty($sampleData->size) && (!empty($sampleData->mode) || !empty($sampleData->fixeddataset) || !empty($sampleData->filesizelimit) || !empty($sampleData->additionalmodules))) {
            $sampleData->size = SampleDataSize::M;
        }
    }

    /**
     * Determine if we should use tool_generator or legacy method
     */
    private function shouldUseToolGenerator(SampleDataModel $sampleData): bool {
        // Always use tool_generator if mode is 'site'
        if ($sampleData->mode === 'site') {
            return true;
        }

        // Use tool_generator if we have size specified (new format or converted from courseSize)
        if (!empty($sampleData->size)) {
            return true;
        }

        // Use tool_generator if new format options are specified
        if (!empty($sampleData->fixeddataset) || !empty($sampleData->filesizelimit) || !empty($sampleData->additionalmodules)) {
            return true;
        }

        // Fall back to legacy if only legacy properties are set (no size, no mode, no new options)
        // This handles old configurations that only have students/teachers/courses
        return false;
    }

    /**
     * Generate site using tool_generator's maketestsite.php
     */
    private function generateSite(string $moodleContainer, string $moodlePath, SampleDataModel $sampleData): void {
        $size = $sampleData->size ?? SampleDataSize::M;
        $this->cli->notice("Generating test site with size: {$size} (using tool_generator)");

        $args = ['--size=' . $size];

        if ($sampleData->fixeddataset) {
            $args[] = '--fixeddataset';
        }

        if ($sampleData->filesizelimit) {
            $args[] = '--filesizelimit=' . $sampleData->filesizelimit;
        }

        $this->executeMoodleCli(
            $moodleContainer,
            $moodlePath,
            'admin/tool/generator/cli/maketestsite.php',
            $args
        );
    }

    /**
     * Generate courses using tool_generator's maketestcourse.php
     */
    private function generateCoursesWithToolGenerator(string $moodleContainer, string $moodlePath, SampleDataModel $sampleData): void {
        $count = $sampleData->courses ?? 10;
        $size = $sampleData->size ?? SampleDataSize::M;

        $this->cli->notice("Generating {$count} courses with size: {$size} (using tool_generator)");

        // Generate categories first if specified
        if (!empty($sampleData->categories) && $sampleData->categories > 0) {
            $this->generateCategories($moodleContainer, $moodlePath, $sampleData->categories);
        }

        for ($i = 1; $i <= $count; $i++) {
            $shortname = 'testcourse_' . $i;
            $args = [
                '--shortname=' . $shortname,
                '--size=' . $size
            ];

            if ($sampleData->fixeddataset) {
                $args[] = '--fixeddataset';
            }

            if ($sampleData->filesizelimit) {
                $args[] = '--filesizelimit=' . $sampleData->filesizelimit;
            }

            if (!empty($sampleData->additionalmodules)) {
                $args[] = '--additionalmodules=' . implode(',', $sampleData->additionalmodules);
            }

            $this->executeMoodleCli(
                $moodleContainer,
                $moodlePath,
                'admin/tool/generator/cli/maketestcourse.php',
                $args
            );
        }
    }

    /**
     * Legacy generation method (for backward compatibility)
     */
    private function generateLegacy(string $moodleContainer, string $moodlePath, SampleDataModel $sampleData): void {
        // Generate categories first (needed for courses)
        if (!empty($sampleData->categories) && $sampleData->categories > 0) {
            $this->generateCategories($moodleContainer, $moodlePath, $sampleData->categories);
        }

        // Generate users (students and teachers)
        if (!empty($sampleData->students) && $sampleData->students > 0) {
            $this->generateUsers($moodleContainer, $moodlePath, $sampleData->students, 'student');
        }

        if (!empty($sampleData->teachers) && $sampleData->teachers > 0) {
            $this->generateUsers($moodleContainer, $moodlePath, $sampleData->teachers, 'editingteacher');
        }

        // Generate courses
        if (!empty($sampleData->courses) && $sampleData->courses > 0) {
            $this->generateCourses($moodleContainer, $moodlePath, $sampleData->courses, $sampleData->courseSize);

            // Enroll users in courses if courseSize is specified
            if (!empty($sampleData->courseSize)) {
                $this->enrollUsersInCourses($moodleContainer, $moodlePath, $sampleData);
            }
        }
    }

    /**
     * Execute a Moodle CLI script directly (no copying needed)
     */
    private function executeMoodleCli(string $moodleContainer, string $moodlePath, string $scriptPath, array $args = []): void {
        $argsStr = !empty($args) ? ' ' . implode(' ', array_map('escapeshellarg', $args)) : '';
        $cmd = sprintf(
            'docker exec %s php %s/%s%s',
            escapeshellarg($moodleContainer),
            escapeshellarg($moodlePath),
            escapeshellarg($scriptPath),
            $argsStr
        );

        $this->execPassthru($cmd, "Failed to execute Moodle CLI script: {$scriptPath}");
    }

    /**
     * Generate course categories
     */
    private function generateCategories(string $moodleContainer, string $moodlePath, int $count): void {
        $this->cli->notice("Creating {$count} course categories...");
        $this->executeCliScript($moodleContainer, $moodlePath, 'mchef_generate_categories.php', ['--count=' . $count]);
    }

    /**
     * Generate users (students or teachers)
     */
    private function generateUsers(string $moodleContainer, string $moodlePath, int $count, string $role): void {
        $roleName = $role === 'student' ? 'students' : 'teachers';
        $this->cli->notice("Creating {$count} {$roleName}...");
        $this->executeCliScript($moodleContainer, $moodlePath, 'mchef_generate_users.php', ['--count=' . $count, '--role=' . $role]);
    }

    /**
     * Generate courses with optional size configuration
     */
    private function generateCourses(string $moodleContainer, string $moodlePath, int $count, ?string $courseSize): void {
        $this->cli->notice("Creating {$count} courses...");

        // Map courseSize to Moodle's test course generator sizes
        $sizeMap = [
            'small' => 'S',
            'medium' => 'M',
            'large' => 'L',
            'random' => 'M', // Default to medium for random, or we can randomize
        ];

        $moodleSize = $courseSize && isset($sizeMap[$courseSize]) ? $sizeMap[$courseSize] : 'M';

        $args = ['--count=' . $count, '--size=' . $moodleSize];
        if ($courseSize === 'random') {
            $args[] = '--random-size';
        }

        $this->executeCliScript($moodleContainer, $moodlePath, 'mchef_generate_courses.php', $args);
    }

    /**
     * Copy CLI script to Moodle container and execute it
     */
    private function executeCliScript(string $moodleContainer, string $moodlePath, string $scriptName, array $args = []): void {
        $cliScriptPath = __DIR__ . '/../../templates/moodle/cli/' . $scriptName;

        if (!file_exists($cliScriptPath)) {
            throw new \Exception("CLI script not found: {$cliScriptPath}");
        }

        // Target path in container
        $targetPath = "{$moodlePath}/admin/cli/{$scriptName}";

        // Copy script to container
        $cmd = sprintf(
            'docker cp %s %s:%s',
            escapeshellarg($cliScriptPath),
            escapeshellarg($moodleContainer),
            escapeshellarg($targetPath)
        );

        $this->exec($cmd, "Failed to copy CLI script {$scriptName} to container");

        // Build command with arguments
        $argsStr = !empty($args) ? ' ' . implode(' ', array_map('escapeshellarg', $args)) : '';
        $cmd = sprintf(
            'docker exec %s php %s%s',
            escapeshellarg($moodleContainer),
            escapeshellarg($targetPath),
            $argsStr
        );

        try {
            $this->execPassthru($cmd, "Failed to execute CLI script {$scriptName}");
        } finally {
            // Clean up the script
            $cleanupCmd = sprintf(
                'docker exec %s rm -f %s',
                escapeshellarg($moodleContainer),
                escapeshellarg($targetPath)
            );
            @$this->exec($cleanupCmd);
        }
    }

    /**
     * Enroll students and teachers in courses based on courseSize
     */
    private function enrollUsersInCourses(string $moodleContainer, string $moodlePath, SampleDataModel $sampleData): void {
        if (empty($sampleData->courses) || $sampleData->courses <= 0) {
            return;
        }

        $this->cli->notice('Enrolling users in courses...');
        $this->executeCliScript($moodleContainer, $moodlePath, 'mchef_enroll_users.php', []);
    }
}
