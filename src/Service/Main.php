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
use App\Service\MoodleConfig;

class Main extends AbstractService {

    use ExecTrait;

    // Dependencies
    private Docker $dockerService;
    private Plugins $pluginsService;
    private Configurator $configuratorService;
    private File $fileService;
    private Git $gitService;
    private Moodle $moodleService;
    private RecipeService $recipeService;
    private ProxyService $proxyService;
    private Database $databaseService;
    private Environment $environmentService;
    private MoodleConfig $moodleConfigService;
    private MoodleInstall $moodleInstallService;

    // Models
    private Recipe $recipe;
    private ?PluginsInfo $pluginInfo = null;
    private DockerData $dockerData;

    // Other properties
    public \Twig\Environment $twig;
    private ?string $chefPath = null;

    protected function __construct() {
        $loader = new \Twig\Loader\FilesystemLoader(__DIR__.'/../../templates');
        $loader->addPath(__DIR__.'/../../templates/moodle', 'moodle');
        $loader->addPath(__DIR__.'/../../templates/moodle/browser', 'moodle-browser');
        $loader->addPath(__DIR__.'/../../templates/docker', 'docker');
        $this->twig = new \Twig\Environment($loader);
        parent::__construct();
    }

    public function getTwig(): \Twig\Environment {
        return $this->twig;
    }

    public function getDockerData(): DockerData {
        return $this->dockerData;
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
        if (isset(StaticVars::$ciDockerPath)) {
            return StaticVars::$ciDockerPath;
        }
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
            $plugins = $this->pluginsService->getPluginsInfoFromRecipe($this->recipe, StaticVars::$noCache);

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
        $dockerBuildKit = $dockerData->reposUseSsh ? 'DOCKER_BUILDKIT=1 COMPOSE_DOCKER_CLI_BUILD=1 ' : '';
        $cmd = "{$dockerBuildKit}docker compose --project-directory \"{$this->getChefPath()}/docker\" -f \"$ymlPath\" up -d --force-recreate --build";
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

        // Install Moodle database and sample data
        $this->moodleInstallService->installMoodle($recipe, $moodleContainer, $dbContainer);
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

    private function populateAssets(Recipe &$recipe) {
        $assetsPath = $this->getAssetsPath();
        if (!file_exists($assetsPath)) {
            $this->cli->info('Creating docker assets path '.$assetsPath);
            mkdir($assetsPath, 0755, true);
        }

        $this->moodleConfigService->processConfigFile($recipe);
        $scriptsAssetsPath = $assetsPath.'/scripts';
        if (!file_exists($scriptsAssetsPath)) {
            mkdir($scriptsAssetsPath, 0755, true);
        }

        if (!StaticVars::$ciMode && ($recipe->includeXdebug || $recipe->developer)) {
            try {
                $xdebugContents = $this->twig->render('@docker/install-xdebug.sh.twig', ['mode' => $recipe->xdebugMode ?? 'debug']);
            } catch (\Exception $e) {
                throw new Exception('Failed to parse install-xdebug.sh template: '.$e->getMessage());
            }        
            file_put_contents($scriptsAssetsPath.'/install-xdebug.sh', $xdebugContents);
        }
    }

    public function getRegisteredUuid(string $chefPath): ?string {
        $path = OS::path($chefPath.'/registry_uuid.txt');
        if (file_exists($path)) {
            return trim(file_get_contents($path));
        }
        return null;
    }

    public function establishDockerData() {
        if (!empty($this->dockerData)) {
            return $this->dockerData;
        }
        $this->pluginInfo = $this->pluginsService->getPluginsInfoFromRecipe($this->recipe);
        $volumes = $this->pluginInfo ? $this->pluginInfo->volumes : [];
        if ($volumes) {
            $this->cli->info('Volumes will be created for plugins: '.implode("\n", array_map(function($vol) {return $vol->path;}, $volumes)));
        }

        $moodlePath = $this->moodleService->getDockerMoodlePath($this->recipe);
        $usePublic = $this->moodleService->shouldUsePublicFolder($this->recipe);
        $dockerData = new DockerData($volumes, $moodlePath, $usePublic, null, ...(array) $this->recipe);
        $dockerData->volumes = $volumes;
        $dockerData->reposUseSsh = $this->pluginsReposUseSsh($this->recipe);
        $this->dockerData = $dockerData;
        return $this->dockerData;
    }

    private function getPluginsForDocker(Recipe $recipe): array {
        $pluginsForDocker = [];
        if (empty($recipe->plugins)) {
            return $pluginsForDocker;
        }
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
        return $pluginsForDocker;
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

        // Populate assets first, as in doing so, the recipe may change.
        $this->populateAssets($recipe);

        $dockerData = $this->establishDockerData();

        // Add plugin data for dockerfile shallow cloning
        if ($recipe->plugins) {
            $pluginsForDocker = $this->getPluginsForDocker($recipe);
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

    private function pluginsReposUseSsh(Recipe $recipe): bool {
        $hasSsh = false;
        if ($recipe->plugins) {
            foreach ($recipe->plugins as $plugin) {
                $recipePlugin = $this->pluginsService->extractRepoInfoFromPlugin($plugin);
                if ($this->gitService->isRemoteSsh($recipePlugin->repo)) {
                    $hasSsh = true;
                    break;
                }
            }
        }
        return $hasSsh;
    }

    /**
     * Prepare Docker data for CI builds (production settings, no volumes).
     *
     * @param Recipe $recipe The recipe to prepare
     * @return DockerData Prepared docker data for CI
     */
    private function prepareDockerDataForCI(Recipe $recipe): DockerData {
        // Create docker data with no volumes (CI build)
        $moodlePath = $this->moodleService->getDockerMoodlePath($this->recipe);
        $usePublic = $this->moodleService->shouldUsePublicFolder($this->recipe);
        $dockerData = new DockerData([], $moodlePath, $usePublic, null, ...(array) $recipe);
        $dockerData->volumes = [];

        // Add plugin data for dockerfile shallow cloning (if not disabled)
        // TODO - note that CI is going to require some way to clone these repos via ssh in some cases.
        // We will need to add a SSH_KEY github env variable.
        if ($recipe->plugins && !$recipe->cloneRepoPlugins) {
            $pluginsForDocker = $this->getPluginsForDocker($recipe);
            $dockerData->pluginsForDocker = $pluginsForDocker;
            $dockerData->reposUseSsh = $this->pluginsReposUseSsh($recipe);
        }

        $containerName = 'mc-'.($this->recipe->containerPrefix ?? 'mchef').'-moodle';
        $dockerData->containerName = $containerName;

        return $dockerData;
    }

    /**
     * Build Docker image for CI/production purposes with custom image name.
     *
     * @param Recipe $recipe The recipe to build
     * @param string $imageName Custom image name to tag the built image
     * @throws Exception If build fails
     */
    public function buildDockerCiImage(Recipe $recipe, string $imageName): void {
        $this->cli->info("Building Docker image: {$imageName}");

        StaticVars::$ciMode = true;

        // Set static vars for template rendering
        StaticVars::$recipe = $recipe;

        // Generate temporary project directory for build
        $buildDir = $this->getChefPath() . '/ci-build-' . uniqid();
        $dockerDir = $buildDir . '/docker';
        StaticVars::$ciDockerPath = $dockerDir;

        try {
            // Create build directory
            if (!mkdir($dockerDir, 0755, true)) {
                throw new Exception("Failed to create build directory: {$dockerDir}");
            }

            // Populate assets (e.g., xdebug install script)
            $this->populateAssets($recipe);

            // Prepare docker data for CI build (no volumes, production settings)
            $dockerData = $this->prepareDockerDataForCI($recipe);

            try {
                $dockerFileContents = $this->twig->render('@docker/main.dockerfile.twig', (array) $dockerData);
            } catch (\Exception $e) {
                throw new Exception('Failed to parse main.dockerfile template: '.$e->getMessage());
            }

            $dockerData->dockerFile = $dockerDir.'/Dockerfile';
            file_put_contents($dockerData->dockerFile, $dockerFileContents);

            // Render docker-compose file for CI build
            $ymlPath = $dockerDir . '/docker-compose.yml';
            $dockerComposeFileContents = $this->twig->render('@docker/main.compose.yml.twig', (array) $dockerData);
            file_put_contents($ymlPath, $dockerComposeFileContents);

            // Build the image using docker compose
            $usesSsh = $this->pluginsReposUseSsh($recipe);
            $this->dockerService->buildImageWithCompose($ymlPath, $dockerData, $imageName, $dockerDir, $usesSsh);

        } finally {
            // Clean up build directory
            if (is_dir($buildDir)) {
                $this->fileService->deleteDir($buildDir);
            }
        }
    }
}
