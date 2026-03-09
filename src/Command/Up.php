<?php

namespace App\Command;

use App\Exceptions\CliRuntimeException;
use App\Service\CliService;
use App\Service\Configurator;
use App\Service\ProxyService;
use App\StaticVars;
use App\Traits\ExecTrait;
use App\Traits\SingletonTrait;
use splitbrain\phpcli\Options;

class Up extends AbstractCommand {

    use SingletonTrait;
    use ExecTrait;

    // Service dependencies.
    private ProxyService $proxyService;
    private CliService $cliService;

    // Constants.
    const COMMAND_NAME = 'up';

    final public static function instance(): Up {
        return self::setup_singleton();
    }

    public function execute(Options $options): void {
        $this->setStaticVarsFromOptions($options);
        $instance = StaticVars::$instance;
        $containerPrefix = $instance->containerPrefix;

        if (empty($containerPrefix)) {
            throw new CliRuntimeException('Container prefix is required to start containers.', 0, null, [
                'Usage: mchef up <container-prefix>',
                'Example: mchef up ally'
            ]);
        }

        // Check if this instance is different from the currently active instance
        $currentActiveInstance = $this->configuratorService->getMainConfig()->instance;
        $isDifferentInstance = ($currentActiveInstance !== $containerPrefix);

        // Build expected container names from the prefix
        $moodleContainer = $containerPrefix . '-moodle';
        $dbContainer = $containerPrefix . '-db';

        // Check if containers exist
        $existingContainers = $this->getExistingContainers([$moodleContainer, $dbContainer]);

        if (empty($existingContainers)) {
            $this->throwMissingContainersError($containerPrefix);
        }

        // Check if all expected containers exist
        $missingContainers = [];
        if (!in_array($moodleContainer, $existingContainers)) {
            $missingContainers[] = $moodleContainer;
        }
        if (!in_array($dbContainer, $existingContainers)) {
            $missingContainers[] = $dbContainer;
        }

        if (!empty($missingContainers)) {
            $this->cli->error("Missing containers: " . implode(', ', $missingContainers));
            $this->throwMissingContainersError($containerPrefix);
        }

        // Start the containers
        $this->cli->info("Starting containers for: $containerPrefix");
        $this->startContainers([$moodleContainer, $dbContainer]);

        // Handle proxy mode (warn if port 80 blocked by something other than mchef-proxy)
        $this->proxyService->warnIfPort80BlockedForProxy();
        $this->proxyService->ensureProxyRunning();

        $this->cli->success('Containers started successfully!');
        $this->cli->notice('Your mchef-Moodle is now available at: ' . StaticVars::$recipe->wwwRoot);
        $this->cliService->openSite(StaticVars::$recipe->wwwRoot);


        // If this is a different instance from the currently active one, prompt to set as active
        if ($isDifferentInstance) {
            $msg = $currentActiveInstance ? " currently active: '$currentActiveInstance'" : '';

            $setAsActive = $this->cli->promptYesNo("Do you want to set '$containerPrefix' as the active instance?($msg)", null, null, 'n');
            
            if ($setAsActive) {
                $this->configuratorService->setMainConfigField('instance', $containerPrefix);
                $this->cli->success("Set '$containerPrefix' as the active instance.");
            }
        }
    }

    private function throwMissingContainersError(string $containerPrefix): void {
        // Try to find the project directory from registry for helpful error message
        $instances = $this->configuratorService->getInstanceRegistry();
        $foundInstance = null;

        foreach ($instances as $instance) {
            if ($instance->containerPrefix === $containerPrefix) {
                $foundInstance = $instance;
                break;
            }
        }

        $info = [];
        if ($foundInstance) {
            $projectDir = dirname($foundInstance->recipePath);
            $recipeFile = basename($foundInstance->recipePath);
            $info[] = "You must re-run mchef in the project directory for this recipe:";
            $info[] = "  cd $projectDir";
            $info[] = "  mchef $recipeFile";
        } else {
            $info[] = "You need to reinitialize by running mchef <recipe-file.json> in the project directory.";
        }

        throw new CliRuntimeException("Missing containers for prefix: $containerPrefix", 0, null, $info);
    }    

    private function getExistingContainers(array $expectedContainers): array {
        $existing = [];

        foreach ($expectedContainers as $containerName) {
            // Check if container exists (running or stopped)
            $cmd = "docker ps -a --filter name=^{$containerName}$ --format \"{{.Names}}\"";
            exec($cmd, $output, $returnVar);

            if ($returnVar === 0 && !empty($output) && in_array($containerName, $output)) {
                $existing[] = $containerName;
            }
        }

        return $existing;
    }

    private function startContainers(array $containers): void {
        foreach ($containers as $container) {
            $this->cli->info("Starting container: $container");

            $cmd = "docker start $container";
            exec($cmd, $output, $returnVar);

            if ($returnVar !== 0) {
                $this->cli->error("Failed to start container: $container");
                $this->cli->error(implode("\n", $output));
            } else {
                $this->cli->success("Started: $container");
            }
        }
    }

   protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'Start existing mchef docker containers by container prefix');
        $options->registerArgument('prefix', 'Container prefix to start (e.g., "ally" for ally-moodle, ally-db)', false, self::COMMAND_NAME);
    }
}
