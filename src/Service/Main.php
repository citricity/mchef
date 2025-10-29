<?php

namespace App\Service;

use App\Helpers\OS;
use App\Model\DockerData;
use App\Model\PluginsInfo;
use App\Model\Recipe;
use App\Model\RegistryInstance;
use App\StaticVars;
use App\Traits\ExecTrait;
use splitbrain\phpcli\Exception;
use App\Service\Database;

class Main extends AbstractService {

    use ExecTrait;

    // Dependencies
    private Docker $dockerService;
    private Plugins $pluginsService;
    private Configurator $configuratorService;
    private File $fileService;
    private RecipeService $recipeService;
    private ProxyService $proxyService;
    private Database $databaseService;

    // Models
    private Recipe $recipe;
    private ?PluginsInfo $pluginInfo = null;
    private DockerData $dockerData;

    // Other properties
    private \Twig\Environment $twig;
    private ?string $chefPath = null;

    protected function __construct() {
        $loader = new \Twig\Loader\FilesystemLoader(__DIR__.'/../../templates');
        $loader->addPath(__DIR__.'/../../templates/moodle', 'moodle');
        $loader->addPath(__DIR__.'/../../templates/moodle/browser', 'moodle-browser');
        $loader->addPath(__DIR__.'/../../templates/docker', 'docker');
        $this->twig = new \Twig\Environment($loader);
        parent::__construct();
    }

    final public static function instance(): Main {
        return self::setup_singleton();
    }

    protected function getRecipePath(): ?string {
        $chefPath = $this->getChefPath(true);
        return realpath("$chefPath/../");
    }

    public function getChefPath($failOnNotFound = false): ?string {
        if ($this->chefPath) {
            return $this->chefPath;
        }

        $instanceName = $this->configuratorService->getMainConfig()->instance;
        if ($instanceName) {
            $instance = $this->configuratorService->getRegisteredInstance($instanceName);
            $chefPath = OS::path(dirname($instance->recipePath).'/.mchef');
        } else {
            $chefPath = $this->fileService->findFileInOrAboveDir('.mchef');
        }
        if ($failOnNotFound && !is_dir($chefPath)) {
            $this->cli->alert('Your current working directory, or the directories above it, do not contain a .mchef directory');
            die;
        }
        $this->chefPath = $chefPath;
        return $this->chefPath;
    }

    public function resolveActiveInstanceName(): ?string {
        $config = $this->configuratorService->getMainConfig();
        if (!empty($config->instance)) {
            return $config->instance;
        }
        $chefPath = $this->getChefPath(false);
        if ($chefPath) {
            // We are in a project dir.
            $recipe = $this->getRecipe();
            if (!empty($recipe->containerPrefix)) {
                return $recipe->containerPrefix;
            }
        }
        return null;
    }

    public function resolveActiveInstance(?string $instanceName): ?RegistryInstance {
        $instanceName = $instanceName ?? $this->resolveActiveInstanceName();
        return $this->configuratorService->getRegisteredInstance($instanceName);
    }

    public function getDockerPath() {
        return $this->getChefPath().'/docker';
    }

    public function getAssetsPath() {
        return $this->getDockerPath().'/assets';
    }

    public function getHostPort(?Recipe $recipe = null): int {
        $recipe = $recipe ?? $this->recipe;
        $config = $this->configuratorService->getMainConfig();

        if (!empty($config->useProxy)) {
            return $this->establishDockerData()->proxyModePort;
        }
        if (!empty($recipe->port)) {
            return $recipe->port;
        }
        return 80; // Default is 80 if not set in recipe and not in proxy mode.
    }

    private function startDocker($ymlPath) {
        $ymlPath=OS::path($ymlPath);
        $this->cli->notice('Starting docker containers');

        $this->establishDockerData();
        $dockerData = clone $this->dockerData;

        // Always set hostPort to the correct value (proxy or non-proxy)
        $dockerData->hostPort = $this->getHostPort();

        // Remove plugin volumes (could be cached in plugins) if recipe does not want them.
        if (empty($this->recipe->mountPlugins)) {
            $plugins = $this->pluginsService->getPluginsInfoFromRecipe($this->recipe);

            $pluginPaths = [];
            foreach ($plugins->volumes as $pluginVolume) {
                $path = $pluginVolume->path;
                $pluginPaths[] = $path;
            }

            $volumes = array_filter(
                $dockerData->volumes,
                function($vol) use($pluginPaths) {
                    return !in_array($vol->path, $pluginPaths);
                }
            );

            $dockerData->volumes = $volumes;
        }

        // Re-render the compose file with the correct hostPort
        $dockerComposeFileContents = $this->twig->render('@docker/main.compose.yml.twig', (array) $dockerData);
        file_put_contents($ymlPath, $dockerComposeFileContents);
        $this->cli->notice('Created docker compose file at '.$ymlPath);

        // Compose the command
        $cmd = "docker compose --project-directory \"{$this->getChefPath()}/docker\" -f \"$ymlPath\" up -d --force-recreate --build";
        $this->execPassthru($cmd, "Error starting docker containers - try pruning with 'docker builder prune' OR 'docker system prune' (note 'docker system prune' will destroy all non running container images)");

        // @Todo - Add code here to check docker ps for expected running containers.
        // For example, if one of the Apache virtual hosts has an error in it, it will bomb out.
        // So we need to spin here for about 10 seconds checking that the containers are running.

        $this->cli->success('Docker containers have successfully been started');
    }

    public function startContainers(): void {
        $this->cli->notice('Starting containers');
        $moodleContainer = $this->getDockerMoodleContainerName();
        $this->dockerService->startDockerContainer($moodleContainer);
        $dbContainer = $this->getDockerDatabaseContainerName();
        $this->dockerService->startDockerContainer($dbContainer);

        $this->cli->success('All containers have been started');
    }

    public function stopContainers(?string $instanceName = null): void {
        $instanceName = $instanceName ?? $this->resolveActiveInstanceName();
        if (!$instanceName) {
            $this->cli->error('No active instance to stop');
            return;
        }
        $this->cli->notice('Stopping containers');

        $moodleContainer = $this->getDockerMoodleContainerName($instanceName);
        $dbContainer = $this->getDockerDatabaseContainerName($instanceName);

        $toStop = [
            $moodleContainer,
            $dbContainer
        ];

        $containers = $this->dockerService->getDockerContainers(false);
        $stoppedContainers = 0;
        foreach ($containers as $container) {
            $name = $container->names;
            $this->cli->notice('Stopping container: '.$name);
            if (in_array($name, $toStop)) {
                $this->dockerService->stopDockerContainer($name);
                $stoppedContainers++;
            }
        }

        if ($stoppedContainers > 0) {
            $this->cli->success('All containers have been stopped');
        } else {
            $this->cli->success('No containers were running for this recipe');
        }
    }

    private function configureDockerNetwork(Recipe $recipe): void {
        // TODO LOW priority- default should be mc-network unless defined in recipe or main config.
        $networkName = 'mc-network';
        $database = $this->databaseService::getDatabase();

        if ($this->dockerService->networkExists($networkName)) {
            $this->cli->info('Skipping creating network as it exists: '.$networkName);
        } else {
            $this->cli->info('Configuring network ' . $networkName);
            $cmd = "docker network create $networkName";
            $this->exec($cmd, "Error creating network $networkName");
        }

        $dbContainer = $this->getDockerDatabaseContainerName();
        $moodleContainer = $this->getDockerMoodleContainerName();

        $cmd = "docker network connect $networkName $dbContainer";
        $this->exec($cmd, "Failed to connect $dbContainer to $networkName");

        if ($recipe->includeBehat && $recipe->host && $recipe->host !== 'localhost') {
            // Note - the alias is essential here for behat tests to work.
            // The behat docker container needs to understand the host name when chrome driver tries
            // to operate on the host.
            $cmd = "docker network connect $networkName $moodleContainer --alias $recipe->host --alias $recipe->behatHost";
        } else {
            $cmd = "docker network connect $networkName $moodleContainer";
        }

        $this->exec($cmd, "Failed to connect $moodleContainer to $networkName");

        $this->cli->success('Network configuration successful');

        if ($recipe->installMoodledb) {
            // Try installing The DB
            $this->cli->notice('Try installing MoodleDB');

            $dbnotready = true;
            $dbCheckCmd = $database->buildDBQueryDockerCommand('SELECT 1');
            while ($dbnotready) {
                exec($dbCheckCmd, $output, $returnVar);
                if ($returnVar === 0) {
                    $dbnotready = false;
                }
                $this->cli->notice('Waiting for DB '.$dbContainer.' to be ready');
                sleep(1);
            }
            $this->cli->notice('DB '.$dbContainer.' ready!');

            $dbSchemaInstalledCmd = $database->buildDBQueryDockerCommand('SELECT * FROM mdl_course');

            // Execute the command
            exec($dbSchemaInstalledCmd, $output, $returnVar);
            $dbSchemaInstalled = $returnVar === 0;
            $doDbInstall = !$dbSchemaInstalled;

            if (!$doDbInstall) {
                $this->cli->notice('DB already installed. Skipping installation');
            } else {
                $this->cli->notice('Installing DB');

                // Get language and admin password
                $globalConfig = $this->configuratorService->getMainConfig();
                $lang = $globalConfig->lang ?? 'en';
                $adminPasswordRaw = $recipe->adminPassword ?? $globalConfig->adminPassword ?? '123456';
                // Bash-safe escaping: wrap in single quotes and escape any single quotes inside
                $adminPassword = "'" . str_replace("'", "'\\''", $adminPasswordRaw) . "'";

                $installoptions =
                    '/var/www/html/moodle/admin/cli/install_database.php --lang=' . $lang . ' --adminpass=' . $adminPassword . ' --adminemail=admin@example.com --agree-license --fullname=mchef-MOODLE --shortname=mchefMOODLE';
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
                    }
                }
            }
            $this->cli->notice('Moodle database installed successfully.');
        }
    }

    private function checkPortBinding(Recipe $recipe): bool {
        return $this->dockerService->checkPortAvailable($this->getHostPort($recipe));
    }

    public function hostPath() : string {
        if (!OS::isWindows()) {
            return '/etc/hosts';
        } else {
            return 'C:\\Windows\\System32\\drivers\\etc\\hosts';
        }
    }

    private function updateHostHosts(Recipe $recipe): void {
        $destHostsFile = $this->hostPath();

        if ($recipe->updateHostHosts) {
            try {
                $hosts = file($this->hostPath());
            } catch (\Exception $e) {
                $this->cli->error('Failed to update host hosts file');
            }
        }
        $toAdd = [];
        if (!empty($recipe->host)) {
            $toAdd[] = $recipe->host;
        }
        if (!empty($recipe->behatHost)) {
            $toAdd[] = $recipe->behatHost;
        }
        $toAdd = array_filter($toAdd, function($new) use($hosts) {
            foreach ($hosts as $existing) {
                if (preg_match('/^127\.0\.0\.1\s+' . preg_quote($new, '/') . '$/m', $existing)) {
                    // Already exists - no need to add.
                    return false;
                }
            }
            return true;
        });

        if (empty($toAdd)) {
            $this->cli->info("No hosts to add to host $destHostsFile file");
            return;
        }

        $toAddLines = [];
        foreach ($toAdd as $newHost) {
            $newHost = "\n".'127.0.0.1       '.$newHost;
            $toAddLines[] = $newHost;
        }

        array_unshift($toAddLines, "\n# Hosts added by mchef");
        array_push($toAddLines, "\n# End hosts added by mchef");

        $hosts = array_merge($hosts, $toAddLines);
        $hostsContent = implode("", $hosts);
        $tmpHostsFile = tempnam(sys_get_temp_dir(), "etc_hosts");
        file_put_contents($tmpHostsFile, $hostsContent);

        if (!OS::isWindows()) {
            $this->cli->notice("Updating $destHostsFile - may need root password.");
            $cmd = "sudo cp -f $tmpHostsFile /etc/hosts";
        } else {
            $this->cli->notice("Updating $destHostsFile - may need to be running as administrator.");
            $cmd = "copy /Y \"$tmpHostsFile\" \"$destHostsFile\"";
        }
        exec($cmd, $output, $returnVar);

        if ($returnVar != 0) {
            throw new Exception("Error updating $destHostsFile file");
        }

        $hostsContent = file_get_contents($destHostsFile);
        foreach ($toAdd as $toCheck) {
            if (stripos($hostsContent, $toCheck) === false) {
                throw new Exception("Failed to update $destHostsFile");
            }
        }

        $this->cli->success("Successfully updated $destHostsFile");
    }

    private function parseRecipe(string $recipeFilePath): Recipe {
        $recipe = $this->recipeService->parse($recipeFilePath);
        $this->cli->success('Recipe successfully parsed.');
        return $recipe;
    }

    private function populateAssets(Recipe $recipe) {
        $assetsPath = $this->getAssetsPath();
        if (!file_exists($assetsPath)) {
            $this->cli->info('Creating docker assets path '.$assetsPath);
            mkdir($assetsPath, 0755, true);
        }

        // Create moodle config asset.
        try {
            $moodleConfigContents = $this->twig->render('@moodle/config.php.twig', (array) $recipe);
        } catch (\Exception $e) {
            throw new Exception('Failed to parse config.php template: '.$e->getMessage());
        }
        file_put_contents($assetsPath.'/config.php', $moodleConfigContents);

        if ($recipe->includeBehat || $recipe->developer) {
            try {
                // Create moodle-browser-config config.
                $browserConfigContents = $this->twig->render('@moodle-browser/config.php.twig', (array) $recipe);
            } catch (\Exception $e) {
                throw new Exception('Failed to parse moodle-browser config.php template: '.$e->getMessage());
            }
        }
        $browserConfigAssetsPath = $assetsPath.'/moodle-browser-config';
        if (!file_exists($browserConfigAssetsPath)) {
            mkdir($browserConfigAssetsPath, 0755, true);
        }
        file_put_contents($browserConfigAssetsPath.'/config.php', $browserConfigContents);

        if ($recipe->includeXdebug || $recipe->developer) {
            try {
                $xdebugContents = $this->twig->render('@docker/install-xdebug.sh.twig', ['mode' => $recipe->xdebugMode ?? 'debug']);
            } catch (\Exception $e) {
                throw new Exception('Failed to parse install-xdebug.sh template: '.$e->getMessage());
            }
        }
        $scriptsAssetsPath = $assetsPath.'/scripts';
        if (!file_exists($scriptsAssetsPath)) {
            mkdir($scriptsAssetsPath, 0755, true);
        }
        file_put_contents($scriptsAssetsPath.'/install-xdebug.sh', $xdebugContents);

    }

    public function getRegisteredUuid(string $chefPath): ?string {
        $path = OS::path($chefPath.'/registry_uuid.txt');
        if (file_exists($path)) {
            return trim(file_get_contents($path));
        }
        return null;
    }

    private function establishDockerData() {
        if (!empty($this->dockerData)) {
            return $this->dockerData;
        }
        $this->pluginInfo = $this->pluginsService->getPluginsInfoFromRecipe($this->recipe);
        $volumes = $this->pluginInfo ? $this->pluginInfo->volumes : [];
        if ($volumes) {
            $this->cli->info('Volumes will be created for plugins: '.implode("\n", array_map(function($vol) {return $vol->path;}, $volumes)));
        }

        $dockerData = new DockerData($volumes, null, ...(array) $this->recipe);
        $dockerData->volumes = $volumes;
        $this->dockerData = $dockerData;
        return $this->dockerData;
    }

    public function up(string $recipeFilePath): void {
        $recipeFilePath = OS::path($recipeFilePath);
        $this->cli->notice('Cooking up recipe '.$recipeFilePath);
        // Check if we're running from within the actual moodle-chef development directory
        $currentDir = realpath(getcwd());
        $mchefDevDir = realpath(__DIR__ . '/../../');
        if ($currentDir && $mchefDevDir && (strpos($currentDir . '/', $mchefDevDir . '/') === 0 || $currentDir === $mchefDevDir)) {
            throw new Exception('You should not run mchef from within the moodle-chef folder.'.
                "\nYou should instead, create a link to mchef in your bin folder and then run it from a project folder.".
                "\n\nphp mchef.php -i will do this for you. You'll need to open a fresh terminal once it has completed.".
                "\nAt that point you should be able to call mchef.php without prefixing with the php command."
            );
        }
        $recipe = $this->getRecipe($recipeFilePath);

        $directory = OS::path(getcwd() . '/.mchef'); // Get the current working directory and append '.mchef'
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true); // Create the directory with appropriate permissions
        }
        // Define the path for the recipe.json file
        $recipeJsonFilePath = OS::path($directory . '/recipe.json');

        // Check if the recipe.json file exists
        if (!file_exists($recipeJsonFilePath)) {
           // If the file doesn't exist, copy the contents of $recipeFilePath to the new recipe.json file
           copy($recipeFilePath, $recipeJsonFilePath);
        }
        $this->stopContainers($recipe->containerPrefix);

        if ($recipe->includeBehat) {
            $behatDumpPath = getcwd().'/_behat_dump';
            if (!file_exists($behatDumpPath)) {
                mkdir($behatDumpPath, 0755);
                file_put_contents($behatDumpPath.'/.htaccess', "Options +Indexes\nAllow from All");
            }
        }

        $this->pluginInfo = $this->pluginsService->getPluginsInfoFromRecipe($recipe);
        $volumes = $this->pluginInfo ? $this->pluginInfo->volumes : [];
        if ($volumes) {
            $this->cli->info('Volumes will be created for plugins: '.implode("\n", array_map(function($vol) {return $vol->path;}, $volumes)));
        }

        $dockerData = new DockerData($volumes, null, ...(array) $recipe);
        $dockerData->volumes = $volumes;

        // Add plugin data for dockerfile shallow cloning
        if ($recipe->plugins) {
            $pluginsForDocker = [];
            foreach ($recipe->plugins as $plugin) {
                $recipePlugin = $this->pluginsService->extractRepoInfoFromPlugin($plugin);

                // Only include GitHub repositories for cloning
                if (strpos($recipePlugin->repo, 'https://github.com') === 0 || strpos($recipePlugin->repo, 'git@github.com') === 0) {
                    // Find plugin info to get the Moodle path
                    if ($this->pluginInfo && isset($this->pluginInfo->plugins)) {
                        foreach ($this->pluginInfo->plugins as $pluginInfo) {
                            if (is_string($pluginInfo->recipeSrc)) {
                                if ($pluginInfo->recipeSrc !== $recipePlugin->repo) {
                                    continue;
                                }
                                $repo = $pluginInfo->recipeSrc;
                                $branch = 'main'; // Default branch if not specified
                            } elseif (is_object($pluginInfo->recipeSrc)) {
                                if ($pluginInfo->recipeSrc->repo !== $recipePlugin->repo) {
                                    continue;
                                }
                                $repo = $pluginInfo->recipeSrc->repo;
                                $branch = $pluginInfo->recipeSrc->branch;
                            }

                            $path = $pluginInfo->path;

                            $pluginsForDocker[] = [
                                'repo' => $repo,
                                'branch' => $branch,
                                'path' => $path
                            ];
                            break;
                        }
                    }
                }
            }
            $dockerData->pluginsForDocker = $pluginsForDocker;
        }

        $this->dockerData = $dockerData;

        if ($recipe->updateHostHosts) {
            $this->updateHostHosts($recipe);
        }

        // Register instance first to allocate proxy port if needed
        $chefPath = $this->getChefPath();
        $dockerPath = $this->getDockerPath();
        if (!file_exists($dockerPath)) {
            mkdir($dockerPath, 0755, true);
        }
        copy($recipeFilePath, $chefPath.'/recipe.json');
        $regUuid = $this->getRegisteredUuid($chefPath);
        $this->cli->notice('Registering instance in main config');

        $this->configuratorService->registerInstance(realPath($recipeFilePath), $regUuid, $recipe->containerPrefix);
        // Now get the updated proxy information after registration
        $globalConfig = $this->configuratorService->getMainConfig();
        $useProxy = $globalConfig->useProxy ?? false;

        // Get proxy port for this instance if in proxy mode
        if ($useProxy) {
            $instances = $this->configuratorService->getInstanceRegistry();
            foreach ($instances as $instance) {
                if ($instance->containerPrefix === $recipe->containerPrefix && $instance->proxyModePort !== null) {
                    $dockerData->proxyModePort = $instance->proxyModePort;
                    $this->cli->info("Allocated proxy port {$instance->proxyModePort} for {$recipe->containerPrefix}");
                    break;
                }
            }
            // In proxy mode, always use the allocated proxy port for the host mapping
            $dockerData->hostPort = $dockerData->proxyModePort;
        } else {
            // In non-proxy mode, use the recipe port
            $dockerData->hostPort = $recipe->port;
        }

        $this->checkPortBinding($recipe) || die();

        try {
            $dockerFileContents = $this->twig->render('@docker/main.dockerfile.twig', (array) $dockerData);
        } catch (\Exception $e) {
            throw new Exception('Failed to parse main.dockerfile template: '.$e->getMessage());
        }

        $dockerData->dockerFile = $dockerPath.'/Dockerfile';
        file_put_contents($dockerData->dockerFile, $dockerFileContents);

        $dockerComposeFileContents = $this->twig->render('@docker/main.compose.yml.twig', (array) $dockerData);
        $ymlPath = $dockerPath.'/main.compose.yml';
        file_put_contents($ymlPath, $dockerComposeFileContents);

        $this->populateAssets($recipe);

        // If containers are already running then we need to stop them to re-implement recipe.
        $this->stopContainers();
        $this->startDocker($ymlPath);

        $this->configureDockerNetwork($recipe);

        // Handle proxy mode
        $this->proxyService->ensureProxyRunning();
        $this->proxyService->updateProxyConfiguration();

        // Print out wwwroot
        $this->cli->notice('Your mchef-Moodle is now available at: ' . $recipe->wwwRoot );
    }

    public function getRecipe(?string $recipeFilePath = null): Recipe {
        if (!empty($this->recipe)) {
            return $this->recipe;
        }
        $mchefPath = $this->getChefPath();
        $recipeFilePath = $recipeFilePath ?? $mchefPath.'/recipe.json';
        if (!file_exists($recipeFilePath)) {
            throw new \Exception('Have you run mchef.php [recipefile]? Recipe not present at '.$recipeFilePath);
        }
        $this->recipe = $this->parseRecipe($recipeFilePath);
        StaticVars::$recipe = $this->recipe;
        return $this->recipe;
    }

    public function getActiveInstanceRecipe(): Recipe {
        $instanceName = $this->resolveActiveInstanceName();
        $instance = $this->configuratorService->getRegisteredInstance($instanceName);
        if (!$instance) {
            throw new \Exception('Failed to get instance '.$instanceName);
        }
        return $this->getRecipe($instance->recipePath);
    }

    private function getDockerContainerName(string $suffix, ?string $instanceName = null, ?Recipe $recipe = null, ?string $recipeFilePath = null) {
        if (!$instanceName) {
            $recipe = $recipe ?? $this->recipe;
            if (empty($recipe)) {
                $this->getRecipe($recipeFilePath);
                $recipe = $this->recipe;
            }
            if (empty($recipe->containerPrefix)) {
                throw new \Exception('Failed to establish instance name');
            }
        }
        $instanceName = $instanceName ?? $recipe->containerPrefix;
        return $instanceName.'-'.$suffix;
    }

    public function getDockerMoodleContainerName(?string $instanceName = null, ?Recipe $recipe = null) {
        return $this->getDockerContainerName('moodle', $instanceName, $recipe);
    }

    public function getDockerDatabaseContainerName(?string $instanceName = null, ?Recipe $recipe = null) {
        return $this->getDockerContainerName('db', $instanceName, $recipe);
    }

    /**
     * Prepare Docker data for CI builds (production settings, no volumes).
     *
     * @param Recipe $recipe The recipe to prepare
     * @return DockerData Prepared docker data for CI
     */
    private function prepareDockerDataForCI(Recipe $recipe): DockerData {
        // Create docker data with no volumes (CI build)
        $dockerData = new DockerData([], null, ...(array) $recipe);
        $dockerData->volumes = [];

        // Add plugin data for dockerfile shallow cloning (if not disabled)
        if ($recipe->plugins && !$recipe->cloneRepoPlugins) {
            $pluginsForDocker = [];
            foreach ($recipe->plugins as $plugin) {
                $recipePlugin = $this->pluginsService->extractRepoInfoFromPlugin($plugin);

                // Only include GitHub repositories for cloning
                if (strpos($recipePlugin->repo, 'https://github.com') === 0 || strpos($recipePlugin->repo, 'git@github.com') === 0) {
                    // For CI builds, we don't need volume mounts, just the plugin info for shallow cloning
                    $pluginsForDocker[] = [
                        'repo' => $recipePlugin->repo,
                        'branch' => $recipePlugin->branch,
                        'path' => '/var/www/html' // Default path for CI builds
                    ];
                }
            }
            $dockerData->pluginsForDocker = $pluginsForDocker;
        }

        return $dockerData;
    }

    /**
     * Build Docker image for CI/production purposes with custom image name.
     *
     * @param Recipe $recipe The recipe to build
     * @param string $imageName Custom image name to tag the built image
     * @throws Exception If build fails
     */
    public function buildDockerImage(Recipe $recipe, string $imageName): void {
        $this->cli->info("Building Docker image: {$imageName}");

        // Set static vars for template rendering
        StaticVars::$recipe = $recipe;

        // Generate temporary project directory for build
        $buildDir = $this->getChefPath() . '/ci-build-' . uniqid();
        $dockerDir = $buildDir . '/docker';

        try {
            // Create build directory
            if (!mkdir($dockerDir, 0755, true)) {
                throw new Exception("Failed to create build directory: {$dockerDir}");
            }

            // Prepare docker data for CI build (no volumes, production settings)
            $dockerData = $this->prepareDockerDataForCI($recipe);

            // Render docker-compose file for CI build
            $ymlPath = $dockerDir . '/docker-compose.yml';
            $dockerComposeFileContents = $this->twig->render('@docker/main.compose.yml.twig', (array) $dockerData);
            file_put_contents($ymlPath, $dockerComposeFileContents);

            // Build the image using docker compose
            $this->dockerService->buildImageWithCompose($ymlPath, $imageName, $dockerDir);

        } finally {
            // Clean up build directory
            if (is_dir($buildDir)) {
                $this->fileService->deleteDir($buildDir);
            }
        }
    }
}
