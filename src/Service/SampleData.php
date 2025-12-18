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
     * Uses Moodle's built-in tool_generator for generating test data
     */
    public function generateSampleData(Recipe $recipe, string $moodleContainer): void {
        if (empty($recipe->sampleData)) {
            return;
        }

        $sampleData = $recipe->sampleData;
        $this->cli->notice('Generating sample data for Moodle using tool_generator...');

        $moodlePath = $this->moodleService->getDockerMoodlePath($recipe);

        // Normalize and validate configuration
        $this->normalizeConfiguration($sampleData);

        // Determine generation mode (defaults to 'site')
        $mode = $sampleData->mode ?? 'site';

        // Use Moodle's tool_generator
        if ($mode === 'site') {
            $this->generateSite($moodleContainer, $moodlePath, $sampleData);
        } else {
            $this->generateCourses($moodleContainer, $moodlePath, $sampleData);
        }

        $this->cli->success('Sample data generation completed.');
    }

    /**
     * Normalize and validate configuration
     */
    private function normalizeConfiguration(SampleDataModel $sampleData): void {
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
            $sampleData->mode = !empty($sampleData->courses) ? 'course' : 'site';
        }

        // Set default size if not specified
        if (empty($sampleData->size)) {
            $sampleData->size = SampleDataSize::M;
        }
    }

    /**
     * Generate site using tool_generator's maketestsite.php
     * 
     * This uses Moodle's built-in tool_generator to create a full test site
     * with courses, users, activities, and content. See Moodle documentation
     * for more details: https://moodledev.io/general/development/tools/generator
     */
    private function generateSite(string $moodleContainer, string $moodlePath, SampleDataModel $sampleData): void {
        $size = $sampleData->size ?? SampleDataSize::M;
        $this->cli->notice("Generating test site with size: {$size}");

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
     * 
     * This uses Moodle's built-in tool_generator to create individual test courses
     * with activities and content. See Moodle documentation for more details:
     * https://moodledev.io/general/development/tools/generator
     */
    private function generateCourses(string $moodleContainer, string $moodlePath, SampleDataModel $sampleData): void {
        $count = $sampleData->courses ?? 10;
        $size = $sampleData->size ?? SampleDataSize::M;

        $this->cli->notice("Generating {$count} courses with size: {$size}");

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
     * Execute a Moodle CLI script directly
     * Uses Moodle's built-in tool_generator scripts
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

        $this->cli->notice("Executing Moodle CLI script: {$scriptPath}");

        $this->execPassthru($cmd, "Failed to execute Moodle CLI script: {$scriptPath}");
    }
}
