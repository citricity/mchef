<?php

namespace App\Command;

use App\Exceptions\CliRuntimeException;
use App\Model\Recipe;
use App\Service\Docker;
use App\Service\Environment;
use App\StaticVars;
use App\Traits\SingletonTrait;
use splitbrain\phpcli\Options;
use Exception;

class CI extends AbstractCommand {

    use SingletonTrait;

    // Service dependencies.
    protected Docker $dockerService;
    protected Environment $environmentService;

    // Constants.
    const COMMAND_NAME = 'ci';

    public function __construct() {
        $this->dockerService = Docker::instance();
        $this->environmentService = Environment::instance();
    }

    final public static function instance(): CI {
        return self::setup_singleton();
    }

    public function execute(Options $options): void {
        $this->cli = StaticVars::$cli;
        $args = $options->getArgs();
        
        // Validate recipe argument
        if (empty($args) || empty($args[0])) {
            throw new CliRuntimeException('Recipe file path is required', 0, null, [
                'Usage: mchef ci <recipe-file> --publish=<tag>',
                'Example: mchef ci recipe.json --publish=v1.5.0'
            ]);
        }

        $recipePath = $this->cli->getRecipePathFromArgs();
        if (!$recipePath) {
            throw new CliRuntimeException('Recipe file does not exist: ' . $recipePath);
        }

        $publishTag = $options->getOpt('publish');
        if (empty($publishTag)) {
            throw new CliRuntimeException('Publish tag is required', 0, null, [
                'Usage: mchef ci --publish=<tag> <recipe-file>',
                'Example: mchef ci --publish=v1.5.0 recipe.json'
            ]);
        }

        try {
            // Load and prepare recipe for CI
            $recipe = $this->loadAndPrepareRecipe($recipePath);
            
            // Build Docker image
            $imageName = $this->buildImage($recipe, $publishTag);
            
            // Publish if environment variables are set
            $this->publishImage($imageName, $publishTag);            
        } catch (Exception $e) {
            throw new CliRuntimeException('CI build failed: ' . $e->getMessage());
        }
    }

    protected function register(Options $options): void {
        $options->registerCommand(self::COMMAND_NAME, 'Build and optionally publish production Docker image from recipe');
        $options->registerOption('publish', 'Tag to apply to the built image and publish to registry', 'p', true, self::COMMAND_NAME);
    }

    /**
     * Load recipe and override fields for CI/production build
     */
    private function loadAndPrepareRecipe(string $recipePath): Recipe {
        $this->cli->info("Loading recipe: {$recipePath}");
        
        // Load recipe using Main service
        $recipe = $this->mainService->getRecipe($recipePath);
        
        // Override fields for CI/production build
        $recipe->cloneRepoPlugins = false;
        $recipe->mountPlugins = false;
        $recipe->developer = false;
        $recipe->includePhpUnit = false;
        $recipe->includeBehat = false;
        $recipe->includeXdebug = false;
        
        $this->cli->info('✓ Recipe configured for production build');
        $this->cli->info('  - mountPlugins: false');
        $this->cli->info('  - developer: false');
        $this->cli->info('  - includePhpUnit: false');
        $this->cli->info('  - includeBehat: false');
        $this->cli->info('  - includeXdebug: false');
        
        return $recipe;
    }

    /**
     * Build Docker image using the prepared recipe
     */
    private function buildImage(Recipe $recipe, string $publishTag): string {
        $this->cli->info("Building production image...");
        
        // Generate image name from publishTagPrefix or recipe name
        $imageBaseName = $this->getImageBaseName($recipe);
        $fullImageName = "{$imageBaseName}:{$publishTag}";
        
        $this->cli->info("Target image: {$fullImageName}");
        
        // Build image using Main service build process
        // Note: We'll need to extend the Main service to support custom image names
        $this->mainService->buildDockerCiImage($recipe, $fullImageName);
        
        $this->cli->success("✓ Image built: {$fullImageName}");
        
        return $fullImageName;
    }

    /**
     * Publish image to registry if environment variables are configured
     */
    private function publishImage(string $imageName, string $tag): void {
        $registryConfig = $this->environmentService->getRegistryConfig();
        
        if (empty($registryConfig)) {
            $this->cli->warning('Registry environment variables not configured - skipping publish');
            $this->cli->info('To enable publishing, set the following global config / environment variables:');
            $this->cli->info('  registryUrl / MCHEF_REGISTRY_URL');
            $this->cli->info('  registryUsername / MCHEF_REGISTRY_USERNAME');
            $this->cli->info('  registryPassword / MCHEF_REGISTRY_PASSWORD (or registryToken / MCHEF_REGISTRY_TOKEN for token-based auth)');
            return;
        }
        
        $this->cli->info("Publishing to registry: {$registryConfig['url']}");
        
        try {
            // Login to registry
            $this->dockerService->loginToRegistry($registryConfig);
            
            // Tag image for registry
            $registryImageName = $this->getRegistryImageName($imageName, $registryConfig);
            $this->dockerService->tagImage($imageName, $registryImageName);
            
            // Push to registry
            $this->dockerService->pushImage($registryImageName);
            
            $this->cli->success("✓ Image published: {$registryImageName}");
            
        } catch (Exception $e) {
            $this->cli->error("Failed to publish image: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get image base name from publishTagPrefix or sanitized recipe name
     */
    private function getImageBaseName(Recipe $recipe): string {
        if (!empty($recipe->publishTagPrefix)) {
            return $this->sanitizeImageName($recipe->publishTagPrefix);
        }
        
        if (!empty($recipe->name)) {
            return $this->sanitizeImageName($recipe->name);
        }
        
        // Fallback to generic name
        return 'mchef-app';
    }

    /**
     * Sanitize string for Docker image name
     */
    private function sanitizeImageName(string $name): string {
        // Convert to lowercase and replace invalid characters with hyphens
        $sanitized = strtolower($name);
        $sanitized = preg_replace('/[^a-z0-9\-_.]/', '-', $sanitized);
        // Remove multiple consecutive hyphens
        $sanitized = preg_replace('/-+/', '-', $sanitized);
        // Remove leading/trailing hyphens
        $sanitized = trim($sanitized, '-');
        
        return $sanitized;
    }

    /**
     * Generate registry-specific image name
     */
    private function getRegistryImageName(string $localImageName, array $registryConfig): string {
        $registryUsername = $registryConfig['username'] ?? '';
        $registryUrl = rtrim($registryConfig['url'], '/');
        
        // Extract image name and tag from local name
        $parts = explode(':', $localImageName);
        $imageName = $parts[0];
        $tag = $parts[1] ?? 'latest';
        
        $parts = [$registryUrl];
        if (!empty($registryUsername)) {
            $parts[] = $registryUsername;
        }
        $parts[] = $imageName;
        $registryImageName = implode('/', $parts);

        return "{$registryImageName}:{$tag}";
    }
}
