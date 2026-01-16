<?php

namespace App\Service;

use App\Model\Recipe;
use App\Traits\ExecTrait;

class MoodleInstall extends AbstractService {

    use ExecTrait;

    // Dependencies
    private Database $databaseService;
    private Configurator $configuratorService;
    private Moodle $moodleService;
    private SampleData $sampleDataService;
    private RestoreData $restoreDataService;

    final public static function instance(): MoodleInstall {
        return self::setup_singleton();
    }

    /**
     * Install Moodle database and sample data
     * 
     * @param Recipe $recipe The recipe containing installation configuration
     * @param string $moodleContainer The name of the Moodle container
     * @param string $dbContainer The name of the database container
     * @return void
     */
    public function installMoodle(Recipe $recipe, string $moodleContainer, string $dbContainer): void {
        if (empty($recipe->installMoodledb)) {
            return;
        }

        $this->cli->notice('Installing Moodle database...');
        $this->waitForDatabase($dbContainer);
        $this->installDatabase($recipe, $moodleContainer, $dbContainer);
        $this->generateSampleData($recipe, $moodleContainer);
        $this->processRestoreStructure($recipe, $moodleContainer);
        $this->cli->success('Moodle installation completed.');
    }

    /**
     * Wait for database container to be ready
     * 
     * @param string $dbContainer The name of the database container
     * @return void
     */
    private function waitForDatabase(string $dbContainer): void {
        $database = $this->databaseService::getDatabase();
        $dbCheckCmd = $database->buildDBQueryDockerCommand('SELECT 1');

        $dbnotready = true;
        while ($dbnotready) {
            exec($dbCheckCmd, $output, $returnVar);
            if ($returnVar === 0) {
                $dbnotready = false;
            } else {
                $this->cli->notice('Waiting for DB ' . $dbContainer . ' to be ready');
                $this->cli->notice('Command: ' . $dbCheckCmd);
                sleep(1);
            }
        }
        $this->cli->notice('DB ' . $dbContainer . ' ready!');
    }

    /**
     * Install Moodle database schema
     * 
     * @param Recipe $recipe The recipe containing installation configuration
     * @param string $moodleContainer The name of the Moodle container
     * @param string $dbContainer The name of the database container
     * @return void
     */
    private function installDatabase(Recipe $recipe, string $moodleContainer, string $dbContainer): void {
        $database = $this->databaseService::getDatabase();
        $dbSchemaInstalledCmd = $database->buildDBQueryDockerCommand('SELECT * FROM mdl_course', true);

        // Execute the command
        exec($dbSchemaInstalledCmd, $output, $returnVar);
        $dbSchemaInstalled = $returnVar === 0;
        $doDbInstall = !$dbSchemaInstalled;

        if (!$doDbInstall) {
            $this->cli->notice('DB already installed. Skipping installation');
            return;
        }

        $this->cli->notice('Installing DB');

        // Get language and admin password
        $globalConfig = $this->configuratorService->getMainConfig();
        $lang = $globalConfig->lang ?? 'en';
        $adminPasswordRaw = $recipe->adminPassword ?? $globalConfig->adminPassword ?? '123456';
        // Bash-safe escaping: wrap in single quotes and escape any single quotes inside
        $adminPassword = "'" . str_replace("'", "'\\''", $adminPasswordRaw) . "'";

        $moodlePath = $this->moodleService->getDockerMoodlePath($recipe);
        $installoptions = $moodlePath . '/admin/cli/install_database.php --lang=' . $lang . ' --adminpass=' . $adminPassword . ' --adminemail=admin@example.com --agree-license --fullname=mchef-MOODLE --shortname=mchefMOODLE';
        $cmdinstall = 'docker exec ' . $moodleContainer . ' php ' . $installoptions;

        // Try to install
        try {
            $this->execPassthru($cmdinstall);
        } catch (\Exception $e) {
            // Installation failed, ask if DB should be dropped?
            $this->cli->error($e->getMessage());
            $overwrite = readline("Do you want to delete the db and install fresh? (yes/no): ");

            if (strtolower(trim($overwrite)) === 'yes') {
                $this->cli->notice('Overwriting the existing Moodle database...');
                // Drop all DB Tables in public
                $database->dropAllTables();
                // Do the installation again, should work now
                $this->execPassthru($cmdinstall);
            } else {
                $this->cli->notice('Skipping Moodle database installation.');
                return;
            }
        }

        $this->cli->notice('Moodle database installed successfully.');
    }

    /**
     * Generate sample data if configured in recipe
     * 
     * @param Recipe $recipe The recipe containing sample data configuration
     * @param string $moodleContainer The name of the Moodle container
     * @return void
     */
    private function generateSampleData(Recipe $recipe, string $moodleContainer): void {
        if (!empty($recipe->sampleData)) {
            $this->sampleDataService->generateSampleData($recipe, $moodleContainer);
        }
    }

    /**
     * Process restore structure if configured in recipe
     * 
     * @param Recipe $recipe The recipe containing restore structure configuration
     * @param string $moodleContainer The name of the Moodle container
     * @return void
     */
    private function processRestoreStructure(Recipe $recipe, string $moodleContainer): void {
        if (!empty($recipe->restoreStructure)) {
            $this->restoreDataService->processRestoreStructure($recipe, $moodleContainer);
        }
    }
}

